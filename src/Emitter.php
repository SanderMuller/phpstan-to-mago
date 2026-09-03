<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\PrettyPrinter\Standard;

/**
 * The last of `Transpiler`'s four jobs: turning finished translation state into a file.
 *
 * Everything here reads {@see TranslationContext} and writes text. It runs after translation rather than
 * during it, with one exception: a destructuring `foreach` re-renders statements it has just emitted, and
 * that is a render either way.
 *
 * Split out first because it is the cleanest cut. Translation reaches it through three entry points and
 * `renderRange()`, and it reaches back into translation not at all.
 *
 * @phpstan-import-type Descriptor from Transpiler
 * @phpstan-import-type Declaration from Transpiler
 * @phpstan-import-type RecordFields from Transpiler
 */
final readonly class Emitter
{
    public function __construct(private TranslationContext $context) {}

    /**
     * The generated plugin's constructor, or nothing when the rule reads no configured value.
     *
     * Each parameter carries the rule package's own default, so a worker that constructs the plugin with no
     * arguments behaves like PHPStan at package defaults. The consumer overrides by passing values in its
     * worker — from `[extension-hosts.<name>.environment]` or argv — which is what keeps the generated file
     * free of any one project's configuration.
     */
    private function emitConstructor(): string
    {
        if (! $this->context->usesConfiguration) {
            return '';
        }

        $derived = [];
        $assignments = [];
        foreach ($this->context->pure as $property => $expression) {
            // Typed, because the generated plugin is analysed and a bare `array` fails at level 8. Every
            // producer the vocabulary allows here builds a set keyed by the names the rule listed —
            // `array_fill_keys([..], true)` and `array_flip([..])` — so the value type is what a membership
            // test reads, and `isset()` is the only thing that ever reads it.
            $derived[] = '    /** @var array<string, mixed> */';
            $derived[] = '    private readonly array $' . $property . ';';
            $assignments[] = '        $this->' . $property . ' = '
                . (new Standard(['shortArraySyntax' => true]))->prettyPrintExpr($expression) . ';';
        }

        $parameters = [];
        $origins = [];
        foreach ($this->context->configured as $property => $configured) {
            $type = match ($configured['kind']) {
                'config-list' => 'array',
                'config-bool' => 'bool',
                'config-number' => is_float($configured['default']) ? 'float' : 'int',
                default => 'string',
            };

            $parameters[] = '        public readonly ' . $type . ' $' . $property
                . ' = ' . $this->phpDefault($configured['default']) . ',';

            // Which PHPStan parameter the value came from, kept with the argument that carries it. A reader
            // of the generated plugin has no other way to learn that `$required` is `%type_coverage.declare%`
            // — the default is the *package's*, and a consumer running at their own threshold has to pass it
            // here. `tests/Support/ConsumerParameters` reads these lines for the same reason: without them a
            // differential run compares the consumer's configured original against a port at package
            // defaults, which is a difference in configuration reported as a disagreement.
            $origins[] = '     * @param ' . $type . ' $' . $property
                . " PHPStan's `%" . $configured['parameter'] . '%`';
        }

        // A derived property is assigned in the body, from the parameters above. The rule's own parameter
        // names are kept, which is what lets the derivation be copied rather than rewritten.
        $body = $assignments === [] ? ' {}' : " {\n" . implode("\n", $assignments) . "\n    }";

        // A constant the derivation names, declared here so the copy has something to refer to. Written with
        // the rule's own name and values, because the derivation is copied verbatim.
        $constants = [];
        foreach ($this->context->carriedConstants as $name => $value) {
            $constants[] = '    /** Carried from the rule, whose derivation names it. */';
            $constants[] = '    private const array ' . $name . ' = '
                . (new Standard(['shortArraySyntax' => true]))->prettyPrintExpr($value) . ';';
            $constants[] = '';
        }

        $properties = implode("\n", $constants) . ($derived === [] ? '' : implode("\n", $derived) . "\n");
        // A rule may derive a property without taking any configured value, and an empty parameter list read
        // as a formatting accident rather than as "this takes nothing".
        $signature = $parameters === []
            ? '    public function __construct()'
            : "    /**\n" . implode("\n", $origins) . "\n     */\n"
                . "    public function __construct(\n" . implode("\n", $parameters) . "\n    )";

        return "\n" . $properties . "\n" . $signature . $body . "\n";
    }

    /** A configured default, written as the PHP literal the generated constructor defaults to. */
    private function phpDefault(mixed $default): string
    {
        if (is_array($default)) {
            // Keys are written out for a map and dropped for a list, which is the difference between
            // `['input' => true]` and `[true]`. Every default read from a package's `parameters:` is a list,
            // so this stayed list-only and was right until a value arrived already computed: a lookup table
            // rendered without its keys still parses, still loads, and answers false to every membership
            // test it exists to answer.
            $list = array_is_list($default);

            $items = [];
            foreach ($default as $key => $item) {
                $items[] = ($list ? '' : $this->phpDefault($key) . ' => ') . $this->phpDefault($item);
            }

            return '[' . implode(', ', $items) . ']';
        }

        return match (true) {
            is_bool($default) => $default ? 'true' : 'false',
            is_int($default), is_float($default) => (string) $default,
            is_string($default) => $this->context->backend->bytes($default),
            default => 'null',
        };
    }

    /**
     * The node kinds the emitted plugin registers for.
     *
     * One kind for every hook but the class-declaration one, where the breadth depends on whether the rule
     * restricted itself to classes. Trait is absent on purpose: PHPStan's `InClassNode` does not fire for a
     * trait either, which the same control showed.
     *
     * Every class-like kind, unconditionally, because the rule's own class test is now asked at runtime rather
     * than folded away — see {@see classHookIsClass()}. Two earlier attempts tried to decide the breadth from
     * whether the rule narrows: a syntactic pre-pass over the source, then the flag the fold set during
     * translation. Both were wrong in the same direction, because neither the presence of the predicate nor its
     * translation proves the *rule* is class-only: compounded or negated, it is not. Not deciding is exact.
     *
     * A rule naming an *abstract* php-parser class asks PHPStan for every node beneath it and branches on the
     * concrete kind, so the plugin registers each — see {@see Vocabulary::HOOK_KINDS}.
     *
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     *
     * @return list<string>
     */
    public function targetKinds(array $hook): array
    {
        $named = Vocabulary::HOOK_KINDS[$this->context->nodeType] ?? null;
        if ($named !== null) {
            return $named;
        }

        $kind = (string) $hook['kind'];

        return ($hook['classOnly'] ?? false) === true
            ? [$kind, 'Enum', 'Interface', 'AnonymousClass']
            : [$kind];
    }

    /** @return string the emitted statements, rendered */
    private function renderAll(): string
    {
        return $this->renderRange(0);
    }

    public function renderRange(int $from): string
    {
        $out = '';
        foreach (array_slice($this->context->lines, $from) as $statement) {
            $out .= $this->context->backend->render($statement);
        }

        return $out;
    }

    public function reportStatement(): string
    {
        if ($this->context->message === null) {
            throw new Refusal('reporting before the message is known');
        }

        $pad = str_repeat(' ', $this->context->indent);

        // The code is PHPStan's own identifier for the rule, so the two tools label the finding the
        // same way. `IssueCode::InvalidArgument` stays as the fallback the analyzer requires.
        $code = $this->context->identifier === null
            ? ''
            : "\n" . $pad . '        .with_code("' . addcslashes($this->context->identifier, '"\\') . '")';

        return $pad . "context.report(\n"
            . $pad . "    IssueCode::InvalidArgument,\n"
            . $pad . "    Issue::error({$this->context->message}){$code}\n"
            . $pad . "        .with_annotation(Annotation::primary({$this->context->reportSpan}).with_message(\"here\")),\n"
            . $pad . ");\n";
    }

    /**
     * The guard a hook needs before the rule's own body, when Mago's node kind is wider than PHPStan's.
     *
     * php-parser has a node class per binary operator; Mago has one `Binary` kind and puts the operator in a
     * child. So a rule registered for `Concat` translates to a hook that also fires for `+` and `===`, and the
     * operator check the rule never had to write is what keeps the port exactly as wide as the rule.
     *
     * @param array<string, string|null>|array<string, bool> $hook
     */
    private function gate(array $hook): string
    {
        $condition = $hook['gate'] ?? null;
        if (! is_string($condition)) {
            return '';
        }

        return "        // The hook's node kind is wider than the rule's, so the rule's own kind is checked first.\n"
            . "        if (! {$condition}) {\n            return;\n        }\n\n";
    }

    /**
     * Where a report lands when the rule anchors it on nothing but the node the hook fired for.
     *
     * The node's own span, except for the whole-file hook: PHPStan's `FileNode` takes its position from the
     * first statement it holds, while Mago's `Program` starts at byte zero, and the two differ by however many
     * lines sit above the first statement.
     */
    public function defaultAnchor(): string
    {
        return $this->context->nodeKind === 'Program' ? 'Support::fileAnchor($context, $node)' : '$node->span';
    }

    private const array PHP_RESERVED_KINDS = ['class'];

    /** Rust identifiers that a PHP variable name could collide with. */
    private const array RUST_KEYWORDS = [
        'as', 'break', 'const', 'continue', 'crate', 'else', 'enum', 'extern', 'false', 'fn', 'for',
        'if', 'impl', 'in', 'let', 'loop', 'match', 'mod', 'move', 'mut', 'pub', 'ref', 'return',
        'self', 'static', 'struct', 'super', 'trait', 'true', 'type', 'unsafe', 'use', 'where',
        'while', 'async', 'await', 'dyn', 'abstract', 'become', 'box', 'do', 'final', 'macro',
        'override', 'priv', 'typeof', 'unsized', 'virtual', 'yield', 'try',
    ];

    public static function snake(string $name): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', rtrim($name, '_')) ?? $name);

        return in_array($snake, self::RUST_KEYWORDS, true) ? $snake . '_' : $snake;
    }

    /**
     * Support helpers the linter tier cannot serve, and why.
     *
     * Each of these reaches for something the linter does not have: an inferred type, the variables
     * in scope at a point, or the class hierarchy across files. A rule that calls one is refused for
     * that tier rather than approximated, on the same principle as the rest of this transpiler. In
     * particular `enclosing_class_is` and `metadata_is` look syntactic and are not: both ask whether
     * a class is a subclass of another, which means walking a parent chain that may leave the file.
     */
    private const array LINT_BLOCKED = [
        'type_is_named_object' => 'the inferred type of an expression',
        'type_is_instance_of' => 'the inferred type of an expression',
        'type_has_method' => 'the inferred type of an expression',
        'variable_is_undefined' => 'the variables in scope at this point',
        'enclosing_class_is' => 'the class hierarchy, which can cross files',
        'metadata_is' => 'the class hierarchy, which can cross files',
        'collect' => 'data accumulated across every file',
        'collected' => 'data accumulated across every file',
        'collected_value' => 'data accumulated across every file',
        'clear_collected' => 'data accumulated across every file',
    ];

    /**
     * The same rule body, wrapped as a linter rule instead of an analyzer plugin.
     *
     * The body is emitted once and reused: it navigates the CST and calls support helpers, neither of
     * which is tier-specific. What differs is the wrapper, the way a finding is reported, and how a
     * guard bails out, since `check` returns unit where a hook returns a result.
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     */
    public function emitLint(string $className, array $hook): string
    {
        if ($this->context->isCollector || $hook['trait'] === 'AnalysisHook') {
            throw new Refusal('the linter has no whole-run hook, so a collector cannot run on that tier');
        }

        if ($this->context->readsPriorScope) {
            throw new Refusal('reads the scope before the node, which the linter does not track');
        }

        if (isset($hook['each'])) {
            throw new Refusal('per-item reporting is not mapped for the linter tier');
        }

        // On this tier the node kind *is* the adapter: matching `Node::ClassConstantAccess` reaches
        // exactly what `as_class_constant_access` reaches. That equivalence holds only for adapters
        // that are a plain variant match, so anything that also filters is refused rather than
        // silently widened. `as_assignment` is the one that matters: it rejects `+=` and friends,
        // which `NodeKind::Assignment` would happily match.
        $variantOnlyAdapters = ['as_class_constant_access', 'as_global_constant', 'as_instantiation', 'as_method_call'];
        if (isset($hook['adapter']) && ! in_array($hook['adapter'], $variantOnlyAdapters, true)) {
            throw new Refusal("the adapter {$hook['adapter']} filters as well as matching, which the node kind does not");
        }

        $this->context->reportSpan = 'node.span()';
        $body = $this->renderAll() . ($this->context->reportedInline ? '' : $this->reportStatement());
        foreach (self::LINT_BLOCKED as $helper => $reason) {
            if (str_contains($body, "support::{$helper}(")) {
                throw new Refusal("needs {$reason} (support::{$helper})");
            }
        }

        if ($this->context->usesMetadata) {
            throw new Refusal('needs the enclosing class metadata, which the linter hooks do not carry');
        }

        // A hook returns `HookResult`; `check` returns unit. The bail is the only place the body
        // encodes that, so it is rewritten rather than parameterised through every guard.
        $body = str_replace(['return Ok({BAIL});', 'return Ok(());'], 'return;', $body);
        if (str_contains($body, 'Ok(')) {
            throw new Refusal('body still returns a hook result after rewriting');
        }

        // The analyzer needs one of its own enum variants alongside the real code; the linter takes
        // the issue directly. The `.with_code(..)` the body already emits is PHPStan's identifier, so
        // a finding is labelled identically on both tiers.
        $body = preg_replace('/context\.report\(\s*\n\s*IssueCode::\w+,\s*\n/', "context.collector.report(\n", $body);
        $body = str_replace('Issue::error(', 'Issue::new(self.cfg.level, ', (string) $body);

        $kind = $hook['kind'];
        $identifier = $this->context->identifier ?? throw new Refusal('no identifier to use as the rule code');
        $struct = $className;
        $config = $className . 'Config';

        // Only imported when the body reaches for it: a rule whose adapter became the node-kind match
        // can end up calling no helper at all, and an unused import is a warning.
        $supportImport = str_contains($body, 'support::') ? "use crate::rule::transpiled::support;\n" : '';

        [$good, $bad] = (new ExampleReader(Transpiler::$examplesDir))->forRule(
            $className,
            str_contains($this->renderAll(), 'support::file_ends_with('),
        );
        $goodExample = $this->rustString($good);
        $badExample = $this->rustString($bad);

        return <<<RUST
// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.
use mago_allocator::Arena;
use schemars::JsonSchema;

use mago_reporting::Annotation;
use mago_reporting::Issue;
use mago_reporting::Level;
use mago_span::HasSpan;
use mago_syntax::cst::Node;
use mago_syntax::cst::NodeKind;

use crate::category::Category;
use crate::context::LintContext;
use crate::requirements::RuleRequirements;
use crate::rule::Config;
use crate::rule::LintRule;
{$supportImport}use crate::rule_meta::RuleMeta;
use crate::settings::RuleSettings;

#[derive(Debug, Clone)]
pub struct {$struct} {
    cfg: {$config},
}

#[derive(Debug, Clone, Copy, Eq, PartialEq, Hash, JsonSchema)]
#[cfg_attr(feature = "serde", derive(serde::Serialize, serde::Deserialize))]
#[cfg_attr(feature = "serde", serde(default, rename_all = "kebab-case", deny_unknown_fields))]
pub struct {$config} {
    pub level: Level,
}

impl Default for {$config} {
    fn default() -> Self {
        Self { level: Level::Error }
    }
}

impl Config for {$config} {
    fn level(&self) -> Level {
        self.level
    }

    fn default_enabled() -> bool {
        false
    }
}

impl LintRule for {$struct} {
    type Config = {$config};

    fn meta() -> &'static RuleMeta {
        const META: RuleMeta = RuleMeta {
            name: "{$className}",
            code: "{$identifier}",
            description: "Transpiled from the PHPStan rule {$className}.",
            good_example: {$goodExample},
            bad_example: {$badExample},
            category: Category::BestPractices,
            requirements: RuleRequirements::None,
        };

        &META
    }

    fn targets() -> &'static [NodeKind] {
        const TARGETS: &[NodeKind] = &[NodeKind::{$kind}];

        TARGETS
    }

    fn build(settings: &RuleSettings<Self::Config>) -> Self {
        Self { cfg: settings.config }
    }

    fn check<'arena, A>(&self, context: &mut LintContext<'_, 'arena, A>, node: Node<'_, 'arena>)
    where
        A: Arena,
    {
        let Node::{$kind}(node) = node else {
            return;
        };

{$body}    }
}
RUST;
    }

    /** The snake_case module name a generated linter rule is registered under. */
    public function lintModule(string $className): string
    {
        return self::snake($className);
    }

    /** A Rust string literal, since the examples are multi-line PHP. */
    private function rustString(string $value): string
    {
        return '"' . addcslashes($value, "\"\\\n\r\t") . '"';
    }

    /**
     * The message the trailing report renders, or a placeholder where there is no trailing report.
     *
     * @return array{string, bool}
     */
    private function messageToRender(): array
    {
        // A rule whose finding a runtime pass builds has no message here to render, and the trailing report
        // is skipped for it — `reportsThroughPass` is set only where the pass was emitted inline. Asked with
        // `message === null` beside it, so a rule that also reports the ordinary way still has to have one.
        return $this->context->message === null && $this->context->reportsThroughPass
            ? ["''", false]
            : $this->reportableMessage();
    }

    /**
     * The rule's message, and whether it is already an expression rather than a quoted literal.
     *
     * A missing message means nothing found the report; one that is neither a literal nor a `sprintf()` is a
     * shape the emitter has no recipe for. Both are refusals rather than a guessed string, because a plugin
     * reporting the wrong text is a plugin nobody can check against the original.
     *
     * @return array{string, bool}
     */
    private function reportableMessage(): array
    {
        if ($this->context->message === null) {
            throw new Refusal('could not find the reported message');
        }

        $isLiteral = str_starts_with($this->context->message, '"') && str_ends_with($this->context->message, '"');
        $isFormatted = str_starts_with($this->context->message, 'sprintf(') || $this->context->messageIsExpression;
        if (! $isLiteral && ! $isFormatted) {
            throw new Refusal('PHP target: message is neither a literal nor a sprintf(): ' . $this->context->message);
        }

        return [$this->context->message, $isFormatted];
    }

    /**
     * A plugin that is an after-analysis hook and nothing else.
     *
     * For a rule whose every check was handed to a whole-project pass: there is no node to dispatch on, so
     * registering a node hook would mean declaring targets the plugin never looks at. `getTargets()` and
     * `getRequirements()` still exist because the interface asks for them, empty, exactly as
     * {@see emitAggregate()} leaves them.
     */
    private function emitAfterOnly(string $className): string
    {
        $identifier = 'transpiled/' . str_replace('_', '-', self::snake($className));
        $constructor = $this->emitConstructor();
        $passes = '';
        $runtime = [];
        foreach ($this->context->afterChecks as $pass) {
            $passes .= "        {$pass};\n";
            $runtime[explode('::', $pass)[0]] = true;
        }

        ksort($runtime);
        $imports = '';
        foreach (array_keys($runtime) as $class) {
            $imports .= "use Sandermuller\\PhpstanToMago\\Runtime\\{$class};\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.

namespace Transpiled;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
{$imports}
final class {$className} implements AfterAnalysisHook, Plugin
{
{$constructor}
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: '{$identifier}',
            name: '{$className}',
            description: 'Transpiled from PHPStan\'s {$className}.',
        );
    }

    public function register(PluginRegistry \$registry): void
    {
        \$registry->registerAfterAnalysisHook(\$this);
    }

    /** @return list<never> */
    public function getTargets(): array
    {
        return [];
    }

    /** @return list<never> */
    public function getRequirements(): array
    {
        return [];
    }

    public function afterAnalysis(AfterAnalysisContext \$context): void
    {
{$passes}    }
}

PHP;
    }

    /**
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     */
    public function emitPhp(string $className, array $hook): string
    {
        if ($hook['node'] === null) {
            throw new Refusal('PHP target: whole-run hooks are not wrapped yet');
        }

        if ($this->context->isCollector) {
            throw new Refusal('PHP target: collectors are not wrapped yet');
        }

        // Every check this rule has is a whole-project pass, so there is nothing for a node hook to do and no
        // message for it to report. The plugin is the after hook alone — the same shape an aggregate takes.
        if ($this->context->message === null && $this->context->afterChecks !== []) {
            return $this->emitAfterOnly($className);
        }

        [$reported, $isFormatted] = $this->messageToRender();

        $body = $this->gate($hook) . $this->renderAll();

        // A rule that already reported inside a loop has nothing to report at the end. Emitting it
        // anyway fired on every declaration, and PHP leaves the loop variable set after the loop, so
        // the message even looked plausible.
        // The anchor applies here too. It did not, and that was the whole defect: an anchor read from a loop item
        // is a variable the emitted `foreach` binds, and this report sits after the loop — so a rule asking for a
        // member's line silently got the class's span instead, through a path that looked right. Refused rather
        // than substituted, for the same reason the comment above gives: PHP leaves the loop variable set, so the
        // wrong answer would look plausible.
        if ($this->context->anchorNeedsLoop && ! $this->context->reportedInline) {
            throw new Refusal(
                'a report anchored on a loop item but emitted after the loop, where the item is no longer bound',
            );
        }

        $trailingReport = $this->context->reportedInline ? '' : strtr(<<<'REPORT'
        $context->report(
            Level::Error,
            {CODE},
            Issue::new(Support::viaTraitUsers($context, $node, {MESSAGE}), {ANCHOR}, 'here'),
        );

REPORT, ['{ANCHOR}' => $this->context->anchor ?? $this->defaultAnchor()]);
        $message = $isFormatted ? $reported : $this->context->backend->bytes(substr($reported, 1, -1));
        // A rule that classifies what it found reports under a code decided at analysis time, so the code is
        // an expression there; quoting it would report under the source text of the interpolation.
        $code = $this->context->identifier ?? 'transpiled.' . self::snake($className);
        $code = $this->context->identifierIsExpression ? $code : $this->context->backend->bytes($code);

        $trailingReport = str_replace(['{CODE}', '{MESSAGE}'], [$code, $message], $trailingReport);
        // `NodeKind::Class` does not reference the enum case: PHP special-cases `::class` and yields
        // the class-name string, so the worker rejects the target with a type error naming neither the
        // rule nor the kind. The SDK names that case `Class_`, the same trailing-underscore convention
        // this file already uses for Rust keywords, so reserved names take the suffix.
        // `NodeKind::Class` does not reference the enum case: PHP special-cases `::class` and yields
        // the class-name string, so the worker rejects the target with a type error naming neither the
        // rule nor the kind. The SDK names that case `Class_`, the same trailing-underscore convention
        // this file already uses for Rust keywords, so reserved names take the suffix.
        $kind = implode(', ', array_map(
            static fn (string $case): string => 'NodeKind::' . $case
                . (in_array(strtolower($case), self::PHP_RESERVED_KINDS, true) ? '_' : ''),
            $this->targetKinds($hook),
        ));
        $identifier = 'transpiled/' . str_replace('_', '-', self::snake($className));

        // Requirements are opt-in per capability: a rule that reads a type without asking for it gets
        // null, which would silently turn every check on it into a pass.
        $requirements = 'FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText';
        if ($this->context->usesReceiverType) {
            $requirements .= ', FileAnalysisRequirement::ReceiverType';
        }

        if ($this->context->usesExpressionTypes) {
            $requirements .= ', FileAnalysisRequirement::ExpressionTypes';
        }

        $constructor = $this->emitConstructor();

        // A rule with a whole-project check is both hook kinds at once, in one plugin. The node hook keeps
        // per-node dispatch and the requirements above for the checks that are node-shaped; the pass runs
        // once over the finished analysis. Empty for every other rule, which is what keeps those files
        // byte-identical.
        $implements = 'Plugin, NodeAnalysisHook';
        $afterImports = '';
        $runtimeImports = '';
        foreach (array_keys($this->context->runtimeHelpers) as $helper) {
            // `Support` is in the template already, and PHP rejects a duplicate `use` outright — the plugin
            // would not compile, let alone report.
            if ($helper !== 'Support') {
                $runtimeImports .= "use Sandermuller\\PhpstanToMago\\Runtime\\{$helper};\n";
            }
        }

        $afterRegister = '';
        $afterMethod = '';
        if ($this->context->afterChecks !== []) {
            $implements = 'Plugin, NodeAnalysisHook, AfterAnalysisHook';
            $afterImports = "use Mago\Sdk\Analyzer\AfterAnalysisContext;\nuse Mago\Sdk\Analyzer\AfterAnalysisHook;\n";
            $afterRegister = "\n        \$registry->registerAfterAnalysisHook(\$this);";
            $passes = '';
            $runtime = [];
            foreach ($this->context->afterChecks as $pass) {
                $passes .= "        {$pass};\n";
                $runtime[explode('::', $pass)[0]] = true;
            }

            ksort($runtime);
            foreach (array_keys($runtime) as $class) {
                $runtimeImports .= "use Sandermuller\\PhpstanToMago\\Runtime\\{$class};\n";
            }

            $afterMethod = "\n    public function afterAnalysis(AfterAnalysisContext \$context): void\n    {\n{$passes}    }\n";
        }

        // One method per check, in the order the rule asks them. Empty for every rule that asks one, which is
        // what keeps those files byte-identical.
        $checkMethods = '';
        foreach ($this->context->checks as $check) {
            $checkMethods .= "\n    private function {$check['name']}({$check['signature']}): void\n"
                . "    {\n{$check['body']}    }\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.

namespace Transpiled;

{$afterImports}use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;
{$runtimeImports}use Sandermuller\PhpstanToMago\Runtime\Support;

final class {$className} implements {$implements}
{
{$constructor}
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: '{$identifier}',
            name: '{$className}',
            description: 'Transpiled from PHPStan\'s {$className}.',
        );
    }

    public function register(PluginRegistry \$registry): void
    {
        \$registry->registerNodeAnalysisHook(\$this);{$afterRegister}
    }

    public function getTargets(): array
    {
        return [{$kind}];
    }

    public function getRequirements(): array
    {
        return [{$requirements}];
    }

    public function analyze(NodeAnalysisContext \$context): void
    {
        \$node = \$context->node;

{$body}{$trailingReport}    }
{$afterMethod}{$checkMethods}
}

PHP;
    }

    /**
     * The hook row this rule was matched to, which is one value of {@see Vocabulary::HOOKS}.
     *
     * Typed as that shape rather than as a bag of strings. The lossy spelling it carried before —
     * `array<string, string>|array<string, null>|array<string, bool>` — made `$hook['extra'] ?? ''` and
     * `$hook['classOnly'] ?? false` read as `bool|string`, which is two of this file's baseline entries and
     * neither is a fault in the code.
     *
     * @param array{trait: string, method: string, node: string|null, kind: string, adapter?: string, extra?: string, classFrom?: string, classOnly?: bool, each?: string, phpOnly?: bool, gate?: string} $hook
     */
    public function emit(string $className, array $hook): string
    {
        $trait = $hook['trait'];
        $method = $hook['method'];
        $signatureType = $hook['node'];
        $adapter = $hook['adapter'] ?? null;
        $each = $hook['each'] ?? null;
        $extra = str_replace('{metadata}', $this->context->usesMetadata ? 'metadata' : '_metadata', $hook['extra'] ?? '');
        $returnType = 'HookResult<()>';
        $bail = '()';
        $tail = 'Ok(())';

        if ($this->context->readsPriorScope) {
            if ($hook['trait'] !== 'ExpressionHook') {
                throw new Refusal("no pre hook mapped for {$hook['trait']}");
            }

            // The rule reads the scope as it was before this node, so it must run on the pre hook.
            $method = 'before_expression';
            $returnType = 'HookResult<ExpressionHookResult>';
            $bail = 'ExpressionHookResult::Continue';
            $tail = 'Ok(ExpressionHookResult::Continue)';
        }

        $prologue = $adapter === null
            ? ''
            : "        let Some(node) = support::{$adapter}(node) else {\n            return Ok({BAIL});\n        };\n\n";
        $body = $prologue . $this->renderAll();
        if ($this->context->message === null && ! $this->context->isCollector) {
            throw new Refusal('could not find the reported message');
        }

        $ruleName = self::snake($className);
        $ruleNameUpper = strtoupper($ruleName);

        if ($this->context->isCollector && ! $this->context->collected) {
            throw new Refusal('collector never returns a datum');
        }

        $report = $this->context->isCollector ? '' : null;
        $this->context->reportSpan = 'node.span()';
        $report ??= $this->context->reportedInline
            ? ''
            : ($each === null
                ? $this->reportStatement()
                : "        for item in node.{$each}.iter() {\n"
                    . "            context.report(\n"
                    . "                IssueCode::InvalidArgument,\n"
                    . "                Issue::error({$this->context->message})\n"
                    . "                    .with_annotation(Annotation::primary(item.span()).with_message(\"here\")),\n"
                    . "            );\n"
                    . "        }\n");

        if (($hook['classOnly'] ?? false) && ! $this->context->narrowedToClass) {
            throw new Refusal(
                'InClassNode fires for interfaces, traits and enums too, and this rule does not narrow '
                . 'to Class_, so it needs those declaration hooks as well',
            );
        }

        $body = str_replace('{BAIL}', $bail, $body);

        // The whole-run hook has no node to be handed, and a context of its own.
        //
        // **No rule in the installed corpus reaches this branch, and a change to it is invisible to the whole
        // suite.** Measured: replacing the `"generated"` literal below leaves all 943 tests passing, while the
        // same edit to the node-hook scaffold underneath fails every analyzer snapshot. The five aggregates
        // never arrive here — `Transpiler::aggregate()` builds a PHP template of its own and returns it under
        // the `rust` key — and the three other `CollectedDataNode` rules refuse on a Rust target for reasons
        // that have nothing to do with this scaffold: `->isEnabled`, an unknown local, and an access path.
        //
        // So it is dead in practice rather than dead by construction, and the thing that would make it live
        // is one of those three refusals being closed. The census is what says so: a `CollectedDataNode` rule
        // moving REFUSE to EMIT is the signal to give this branch a snapshot before trusting it.
        if ($trait === 'AnalysisHook') {
            $body = str_replace('{BAIL}', $bail, $prologue . $this->renderAll());

            return <<<RUST
// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.
pub struct {$className};

static {$ruleNameUpper}_META: ProviderMeta =
    ProviderMeta::new("transpiled::{$ruleName}", "{$className}", "generated");

impl Provider for {$className} {
    fn meta() -> &'static ProviderMeta {
        &{$ruleNameUpper}_META
    }
}

impl AnalysisHook for {$className} {
    fn after_analysis(&self, context: &mut AnalysisHookContext<'_>) -> HookResult<()> {
{$body}        support::clear_collected();

        Ok(())
    }
}
RUST;
        }

        return <<<RUST
// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.
pub struct {$className};

static {$ruleNameUpper}_META: ProviderMeta =
    ProviderMeta::new("transpiled::{$ruleName}", "{$className}", "generated");

impl Provider for {$className} {
    fn meta() -> &'static ProviderMeta {
        &{$ruleNameUpper}_META
    }
}

impl {$trait} for {$className} {
    fn {$method}(&self, node: &{$signatureType}<'_>{$extra}, context: &mut HookContext<'_, '_>) -> {$returnType} {
{$body}{$report}
        {$tail}
    }
}
RUST;
    }
}
