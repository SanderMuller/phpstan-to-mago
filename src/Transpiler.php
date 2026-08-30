<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Collectors\Collector;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;

/**
 * @phpstan-type Descriptor array{rust: string, kind: string, key?: string, php?: string, fields?: array<string, array{0: string, 1: string, 2?: string}>, collector?: string, service?: string, classPhp?: string, methodPhp?: string, indexPhp?: string, listPhp?: string, patternPhp?: string, subjectPhp?: string, reason?: string, as?: string, record?: array<string, array{rust: string, kind: string, php?: string, reason?: string, as?: string}>}
 * @phpstan-type Declaration array{class: ClassLike, uses: array<string, string>, namespace: string|null}
 * @phpstan-type RecordFields array<string, array{rust: string, kind: string, php?: string, reason?: string, as?: string}>
 */
final class Transpiler
{
    /** Survey mode: keep walking past gaps that are not about the body, to find the body gaps. */
    public static bool $survey = false;

    /**
     * Collect *every* obstacle a rule's body meets, rather than stopping at the first.
     *
     * The census records what stops a rule first, and sizing work from that is wrong by construction. It
     * has been wrong three times: the type renderer looked like one customer where 27 rules need it, a
     * five-rule family looked like one missing navigation where it needs that *and* the renderer, and a
     * whole corpus looked absent because the package that would have read it refused for an unrelated
     * reason. A first blocker says what to fix next; it never says what a fix is worth.
     *
     * Statement by statement, because that is the granularity a refusal can be resumed from: a statement
     * that refuses is skipped and the next one is translated. Obstacles inside one statement — a helper
     * inlined three levels down — still stop at the first, so the list is a *lower bound* on what a rule
     * needs and says so.
     *
     * Survey mode only, and never during emission: skipping a statement is exactly the approximation the
     * generator refuses to make.
     *
     * Off by default: it changes what a survey *reports*, because a rule whose statements are stepped over
     * translates to the end and looks emitted. A caller asking for needs asks for the list, not the verdict.
     */
    public static bool $collectNeeds = false;

    /** @var list<string> every obstacle this rule's body met, in the order it met them */
    private array $needs = [];

    /** Which tier to emit for: 'analyzer' (a plugin) or 'linter' (a lint rule). */
    public static string $target = 'php';

    /**
     * Whether to emit a rule whose numbers do not yet agree with the original.
     *
     * Off by default, and nothing is withheld today: the parameter aggregate is emitted with a stated bound
     * instead — see {@see Vocabulary::ACCEPTED_DIVERGENCE}. The flag stays because it is what a *new*
     * aggregate is exercised and measured behind, without the default being a rule that reports 95 % where the
     * original reports 49 %. {@see Vocabulary::unverifiedAggregate()} is the table it reads.
     *
     * A rule-level counterpart existed briefly, for `CombinedMethodCallRule` reporting 33 findings on a real
     * project that the original did not. It is gone because the disagreement is: 26 were a real defect
     * (`Support::declaringClassName()` reading a flattened `usedTraits` list) and 7 were the consumer's own
     * phpstan-ignore comments, which the differential now accounts for separately.
     */
    public static bool $allowUnverified = false;

    /**
     * Where to read the good and bad examples a linter rule embeds in its metadata.
     *
     * Used by the linter target only. It used to be a path relative to this file, which meant a
     * sibling directory that ships with nothing: the examples came out empty and said so nowhere.
     */
    public static ?string $examplesDir = null;

    /**
     * The functions a constructor derivation may call and still be copied verbatim.
     *
     * Closed on purpose, and every entry is a pure array or string operation with no dependency on state
     * beyond its arguments. Adding to it is adding to what the generated plugin promises to reproduce.
     *
     * @var list<string>
     */
    private const array PURE_FUNCTIONS = [
        'array_combine', 'array_fill_keys', 'array_filter', 'array_flip', 'array_keys', 'array_map',
        'array_merge', 'array_unique', 'array_values', 'count', 'explode', 'implode', 'in_array',
        'ltrim', 'rtrim', 'sprintf', 'str_replace', 'strtolower', 'strtoupper', 'trim',
    ];

    /** One rule's translation state, which every job below reads and writes. */
    private readonly TranslationContext $context;

    /** The fourth job, which turns finished state into a file. */
    private readonly Emitter $emitter;

    /** The middle two, which are one job because they are mutually recursive. */
    private readonly Translator $translator;

    public function __construct(private readonly string $file)
    {
        $this->context = new TranslationContext(
            self::$target === 'php' ? new PhpBackend() : new RustBackend(),
            new SourceIndex(),
        );
        $this->emitter = new Emitter($this->context);
        $this->translator = new Translator($this->context, $this->emitter, $file);
    }

    /**
     * @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>} the emitted rule, plus what the caller needs to register and attribute it
     */
    /**
     * What this rule would emit, or a refusal naming what stops it.
     *
     * In survey mode two checks are deliberately relaxed so the report can say what a rule needs *in total*
     * rather than stopping at its first structural blocker: a node type with no hook is assumed to have one,
     * and a property with no field mapping is assumed to resolve. Both are useful and both were silent, and a
     * silent assumption is how a ranking goes wrong: on 143 vendored rules, 25 named a different first
     * obstacle here than an emit run does, and every one of those was a body-level gap sitting *behind* a
     * blocker the survey had walked past. Closing such a gap moves nothing.
     *
     * So the assumptions travel with the answer. A refusal raised under one says which one.
     *
     * @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>} the emitted rule, plus what the caller needs to register and attribute it
     */
    public function transpile(): array
    {
        try {
            return $this->translate();
        } catch (Refusal $refusal) {
            throw $this->context->assumed === []
                ? $refusal
                : new Refusal($refusal->getMessage() . ', assuming ' . implode(' and ', $this->context->assumed));
        }
    }

    /** @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>} */
    private function translate(): array
    {
        $code = (string) file_get_contents($this->file);
        $ast = SourceIndex::parse($code);
        if ($ast === null) {
            throw new Refusal('could not parse');
        }

        $this->collectUses($ast);
        $class = $this->findClass($ast);
        $className = (string) $class->name;

        $this->context->currentClass = $class;
        $this->context->ruleClass = $class;
        $this->context->ruleUses = $this->context->useMap;
        $this->context->ruleNamespace = SourceIndex::namespaceOf($ast, $className);

        $this->translator->collectConstants($class);

        $this->refuseWhatNoBodyCouldFix($class);

        if (self::$target === 'php') {
            $aggregate = AggregateRule::from($class, $this->file, PackageConfiguration::forRuleFile($this->file));
            if ($aggregate instanceof AggregateRule) {
                return $this->emitAggregate($className, $aggregate);
            }
        }

        $this->collectConfiguration($class, $this->qualified($className, $ast));
        $this->context->isCollector = $this->implementsCollector($class);
        $this->context->collectorName = $className;

        $nodeType = $this->findNodeType($class);
        if (! isset(Vocabulary::HOOKS[$nodeType])) {
            if (! self::$survey) {
                if (in_array($nodeType, self::MULTI_KIND_NODE_TYPES, true)) {
                    throw new Refusal($this->multiKindRefusal($nodeType, $class));
                }

                throw new Refusal("no hook mapping for node type $nodeType");
            }

            // Survey: assume the hook exists, to see what the body would need.
            //
            // The assumption has to travel with the answer. On 143 vendored rules, 25 reported a different
            // first obstacle here than an emit run does, and in every one of those the emit run named a
            // missing hook while the survey named something in the body *behind* it. A ranking built from
            // survey output then counts body-level gaps for rules that no body-level fix can move: closing
            // `unknown local $this` for `FetchingDeprecatedConstRule` buys nothing while `Expr_ConstFetch`
            // has no hook. So a refusal raised under the assumption says it was raised under it.
            $short = substr($nodeType, (int) strrpos('\\' . $nodeType, '\\'));

            $hook = ['trait' => 'SurveyHook', 'method' => 'survey', 'node' => $short, 'kind' => $short];
            $this->context->nodeKind = $short;
            $processNode = $this->inheritedRuleMethod($class, 'processNode');
            $this->translator->assume("a hook for {$nodeType}");
            foreach ($processNode->stmts ?? [] as $stmt) {
                $this->translateOrCollect($stmt);
            }

            return [
                'name' => $className,
                'trait' => $hook['trait'],
                'arguments' => [],
                'node' => $short,
                'kind' => $hook['kind'],
                'module' => Emitter::snake($className),
                'rust' => '',
                'identifier' => $this->context->identifier,
                'identifiers' => array_values(array_unique($this->context->identifiers)),
                'messages' => [],
            ];
        }

        $hook = Vocabulary::HOOKS[$nodeType];

        // A hook Mago answers only through the PHP SDK. Refused by name rather than registered against a
        // guessed Rust trait, which `ModuleEmitter` would turn into a crash instead of a refusal.
        if (($hook['phpOnly'] ?? false) && self::$target !== 'php') {
            throw new Refusal("a {$hook['kind']} hook, which only the PHP target carries");
        }

        $this->context->nodeKind = $hook['kind'];
        $this->context->classFrom = $hook['classFrom'] ?? 'scope';

        $this->context->nodeType = $nodeType;
        $this->context->hookKinds = $this->emitter->targetKinds($hook);
        if (count($this->context->hookKinds) < 2) {
            $this->context->hookKinds = [];
        }

        $processNode = $this->inheritedRuleMethod($class, 'processNode');
        $this->context->checkMode = self::$target === 'php' && $this->independentChecks($processNode) >= 2;
        foreach ($processNode->stmts ?? [] as $stmt) {
            $this->translateOrCollect($stmt);
        }

        $rust = match (self::$target) {
            'php' => $this->emitter->emitPhp($className, $hook),
            'linter' => $this->emitter->emitLint($className, $hook),
            default => $this->emitter->emit($className, $hook),
        };

        return [
            'name' => $className,
            'trait' => $hook['trait'],
            'node' => $hook['node'],
            'kind' => $hook['kind'],
            'module' => Emitter::snake($className),
            'rust' => $rust,
            'identifier' => $this->context->identifier,
            // Every identifier, not only the last: a harness comparing the two tools has to look for all of
            // them, or a merged rule's other checks pass by being ignored rather than by agreeing.
            'identifiers' => array_values(array_unique($this->context->identifiers)),
            // The configured values the generated plugin carries as constructor defaults, read from the
            // package's own neon. Handed back so a harness can register the *original* rule with the same
            // values: a rule whose two sides are configured differently is not a comparison.
            'arguments' => array_map(static fn (array $configured): mixed => $configured['default'], $this->context->configured),
            'messages' => array_values($this->context->constants === [] ? [] : array_filter(
                $this->context->constants,
                static fn (string $name): bool => str_contains($name, 'MESSAGE'),
                ARRAY_FILTER_USE_KEY,
            )),
        ];
    }

    /** Whether this class is a PHPStan Collector, i.e. the per-file half of a cross-file rule. */
    private function implementsCollector(Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            if ($this->translator->resolveClassName($interface) === Collector::class) {
                return true;
            }
        }

        return false;
    }

    /** A written class name, as the FQCN the rule file's `use` list makes it mean. */

    /**
     * @param Stmt[] $ast
     */
    /**
     * The rule's fully qualified name, which is how the package's neon wires it.
     *
     * @param Stmt[] $ast
     */
    private function qualified(string $className, array $ast): string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name instanceof Name) {
                return $stmt->name->toString() . '\\' . $className;
            }
        }

        return $className;
    }

    /**
     * @param Stmt[] $ast
     */
    private function collectUses(array $ast): void
    {
        $this->context->useMap = [...$this->context->useMap, ...Uses::collect($ast)];
    }

    /**
     * @param Stmt[] $ast
     */
    private function findClass(array $ast): Class_
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Class_) {
                        return $inner;
                    }
                }
            }

            if ($stmt instanceof Class_) {
                return $stmt;
            }
        }

        throw new Refusal('no class found');
    }

    /**
     * Every string constant the rule declares, by name.
     *
     * Not just `ERROR_MESSAGE`: a rule may declare several and pick between them at the report site
     * (`NoMissnamedDocTagRule` has one each for methods, properties and constants), which is why the
     * message cannot be hoisted to the class level.
     */
    /**
     * The plugin for a collector-and-consumer pair: one after-analysis hook over the whole project.
     *
     * The counting lives in {@see TypeCoverage}, whose numbers were
     * brought into agreement with the original rule on a real project. This is only the shape around it: the
     * configured threshold as a constructor default, the rule's own message and code, and one finding per
     * declaration that is missing a type, anchored where the declaration is.
     *
     * @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>}
     */
    private function emitAggregate(string $className, AggregateRule $aggregate): array
    {
        $identifier = 'transpiled/' . str_replace('_', '-', Emitter::snake($className));
        $threshold = rtrim(rtrim(sprintf('%.1f', $aggregate->default), '0'), '.');
        $message = $this->context->backend->bytes($aggregate->message);
        $code = $this->context->backend->bytes($aggregate->identifier);
        $metric = $aggregate->metric;

        $template = <<<'PHP'
<?php

declare(strict_types=1);

// GENERATED by phpstan-to-mago from {CLASS}. Do not edit by hand.

namespace Transpiled;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;

{DIVERGENCE}final class {CLASS} implements AfterAnalysisHook, Plugin
{
    public function __construct(public readonly float $required = {THRESHOLD}) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: {PLUGIN},
            name: {NAME},
            description: {DESCRIPTION},
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook($this);
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

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $coverage = TypeCoverage::{METRIC}($context);
        if ($coverage->total === 0 || $coverage->percentage() >= $this->required) {
            return;
        }

        $message = sprintf(
            {MESSAGE},
            $coverage->total,
            $coverage->typed,
            $coverage->percentage(),
            $this->required,
        );

        foreach ($coverage->missing as $location) {
            $context->report(Level::Error, {CODE}, Issue::at($message, $location));
        }
    }
}
PHP;

        $rust = strtr($template, [
            '{CLASS}' => $className,
            '{THRESHOLD}' => $threshold,
            '{PLUGIN}' => $this->context->backend->bytes($identifier),
            '{NAME}' => $this->context->backend->bytes($className),
            '{DESCRIPTION}' => $this->context->backend->bytes("Transpiled from PHPStan's {$className}."),
            '{METRIC}' => $metric,
            '{MESSAGE}' => $message,
            '{CODE}' => $code,
            '{DIVERGENCE}' => $this->divergenceNote($metric),
        ]);

        return [
            'name' => $className,
            'trait' => 'AnalysisHook',
            'node' => null,
            'kind' => 'CollectedData',
            'module' => Emitter::snake($className),
            'rust' => $rust,
            // The threshold is this plugin's one knob, and it was absent here while the emitted constructor
            // carried it — so a consumer reading the manifest could not see that the value existed, let alone
            // what it defaulted to.
            'arguments' => ['required' => $aggregate->default],
            'identifier' => $aggregate->identifier,
            'identifiers' => [$aggregate->identifier],
            'messages' => [$aggregate->message],
        ];
    }

    /**
     * The docblock an aggregate carries when it is emitted with a known divergence, or nothing when it is not.
     *
     * The number belongs next to the rule, not only in this repository: someone reading a generated plugin has
     * no reason to know a bound was measured, and a coverage percentage that is quietly 1% off is exactly the
     * plausible-but-wrong shape to design against.
     *
     * @see Vocabulary::ACCEPTED_DIVERGENCE
     */
    private function divergenceNote(string $metric): string
    {
        $accepted = Vocabulary::ACCEPTED_DIVERGENCE[$metric] ?? null;
        if ($accepted === null) {
            return '';
        }

        $lines = explode("\n", wordwrap($accepted['note'], 110));

        return "/**\n * " . implode("\n * ", $lines) . "\n */\n";
    }

    /**
     * Why a rule naming an abstract php-parser class is refused, saying what this rule actually does.
     *
     * A rule returning one of those asks PHPStan for every node beneath it and narrows in the body —
     * `NoDynamicNameRule` says so in a comment, calling `return Expr::class` "a trick to allow multiple node
     * types". Mago's hooks are per node kind and a plugin may register several, so the shape is reachable in
     * principle and this is not a missing table row.
     *
     * What it *is* differs per rule, and this refusal used to assert one answer for all of them: that each
     * branch would have to rebind which child the body reads. That is raised from `getNodeType()` alone,
     * before the body is read, and reading the four rules in the corpus that reach it found the claim true of
     * one. `PreferredClassRule` does dispatch each kind to a different helper. `NoReferenceRule` and
     * `NewWithFollowingSettersCollector` test seven kinds each and read the *same* child in every branch —
     * `->byRef` and `->stmts` — so rebinding is not their obstacle and they are closer to portable than the
     * message said. `ForbiddenNodeRule` is further from it: its `instanceof` is against a *configured* list of
     * class names, so its target set is a runtime value and no static `getTargets()` can express it.
     *
     * So the message names the kinds the rule tests, and states the condition a body has to meet without
     * claiming to have checked it. A refusal that asserts something about a body it never read is how work
     * gets sized wrongly, which is the whole reason this one was rewritten.
     */
    private function multiKindRefusal(string $nodeType, ClassLike $class): string
    {
        // `processNode`'s own body, and only tests against the variable it takes. Both narrowings were forced
        // by getting the number wrong: walking the whole class counted `instanceof` tests inside helpers on
        // *sub*-nodes and made `NewWithFollowingSettersCollector` sixteen kinds, and matching the variable by
        // name alone still made it nine, because one of its helpers names its own parameter `$node` too. It
        // dispatches on seven. A message whose point is accuracy cannot be loose about the number it prints.
        $entry = $class->getMethod('processNode');
        $subject = $entry?->params[0]->var ?? null;
        $hookVariable = $subject instanceof Variable && is_string($subject->name) ? $subject->name : null;

        $kinds = [];
        foreach ((new NodeFinder())->findInstanceOf($entry instanceof ClassMethod ? [$entry] : [$class], Instanceof_::class) as $test) {
            if ($hookVariable !== null
                && (! $test->expr instanceof Variable || $test->expr->name !== $hookVariable)
            ) {
                continue;
            }

            if (! $test->class instanceof Name) {
                return "{$nodeType} covers several node kinds, and this rule narrows to them with `instanceof` "
                    . 'against a value rather than a written class name — a configured list of node classes. A '
                    . "plugin declares its targets statically, so there is no shape to register: the rule's "
                    . 'target set is only known at analysis time';
            }

            $kinds[$test->class->toString()] = true;
        }

        if ($kinds === []) {
            return "{$nodeType} covers several node kinds and this rule narrows to none of them, so there is no "
                . 'target set to derive';
        }

        return sprintf(
            '%s covers several node kinds, and this rule narrows to %d of them with `instanceof`: %s. A plugin '
            . 'can register several targets, so the shape is reachable — what it needs is a hook and a field '
            . 'mapping for each kind, and a body that reads the same child in every branch, because the field '
            . 'table is keyed by one kind per rule. Whether this body does has not been checked here',
            $nodeType,
            count($kinds),
            implode(', ', array_map(static fn (string $name): string => substr($name, (int) strrpos('\\' . $name, '\\')), array_keys($kinds))),
        );
    }

    /**
     * The shapes no hook, table row or body translation can make portable.
     *
     * Both are checked before anything reads the body, because reading it would refuse on whichever construct
     * it happened to trip on first — and that construct is not the obstacle. A refusal naming the wrong
     * obstacle is worse than none: it sizes the work wrongly for whoever reads it next.
     */
    private function refuseWhatNoBodyCouldFix(Class_ $class): void
    {
        // A collector-and-consumer pair has no per-file body to translate, so it is recognised and re-emitted
        // rather than walked.
        if (self::$target === 'php' && $this->implementsCollector($class) && AggregateRule::onlyFeedsAWriter($this->file)) {
            throw new Refusal(
                'every rule that consumes this collector reports nothing and writes a file instead, so the '
                . 'pair cannot become a plugin whatever the collector body does',
                permanent: true,
            );
        }

        if ($this->feedsBackIntoPhpstan($class)) {
            throw new Refusal(
                'this rule reports nothing: its whole output is $scope->invokeNodeCallback(), which synthesises '
                . "a node with inferred argument types and hands it back to PHPStan's own analysis so that "
                . "*other* rules fire on it. An analyzer plugin's only output is report(), and there is no "
                . 'equivalent of feeding a node back into Mago, so no node hook and no vocabulary entry can '
                . 'make this one portable',
                permanent: true,
            );
        }
    }

    /**
     * Whether a rule's only output is a node it hands back to PHPStan rather than a finding.
     *
     * `DataProviderDataRule` is the one in the corpus: it returns `[]` on every path and calls
     * `$scope->invokeNodeCallback()` with a `MethodCall` it builds out of inferred argument types, so PHPStan's
     * own argument-type rules report on a call that does not exist in the source. Nothing in Mago receives a
     * synthesised node, so the rule is unportable for a reason no hook or table row touches.
     *
     * Traced rather than inferred from the name: the whole body was read, and the refusal it produced before
     * this check named a missing node predicate — a construct that was never the obstacle. Same class of
     * misdirection as an unwired configured list refusing on `in_array()`.
     *
     * The call alone is enough; whether the rule also builds findings is not asked. A rule doing both is not
     * half portable — emitting the reporting half and dropping the callback would be narrower than the
     * original with nothing saying so, which is the failure mode to design against. Nothing in any installed
     * package does both, so no branch here pretends to handle it.
     *
     * Paired with {@see AggregateRule::reportsNothing()}, which answers the same question for a rule that
     * writes a file instead.
     */
    private function feedsBackIntoPhpstan(ClassLike $class): bool
    {
        foreach ((new NodeFinder())->findInstanceOf([$class], MethodCall::class) as $call) {
            if ($call->name instanceof Identifier && $call->name->toString() === 'invokeNodeCallback') {
                return true;
            }
        }

        return false;
    }

    /**
     * Sorts the rule's constructor properties into configured values and PHPStan services.
     *
     * A property is only configured if the package's neon says so. When the package declares no neon there
     * is nothing to read, so a constructor property stays unknown and the rule is refused when it reads
     * one — which is the honest outcome: a default nobody declared would be a guess.
     */
    private function collectConfiguration(ClassLike $class, string $className): void
    {
        $constructor = $class->getMethod('__construct');
        if (! $constructor instanceof ClassMethod) {
            return;
        }

        // A declared service type is evidence on its own, so it is read whether or not the package ships a
        // neon. Without this, a rule in a package that wires nothing had every constructor property come back
        // as an unknown local, which named neither the obstacle nor what to do about it.
        $configuration = PackageConfiguration::forRuleFile($this->file);
        $wired = $configuration instanceof PackageConfiguration ? $configuration->argumentsFor($className) : [];
        foreach ($constructor->getParams() as $position => $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                continue;
            }

            // Matched by name where the neon names the argument, by position where it wires positionally.
            $name = $param->var->name;
            $argument = null;
            foreach ($wired as $index => $candidate) {
                if ($candidate['name'] === $name || $candidate['name'] === (string) $index && $index === $position) {
                    $argument = $candidate;

                    break;
                }
            }

            if ($argument === null) {
                // php-parser's `NodeFinder` is stateless, so a rule that takes one in its constructor is not
                // carrying configuration and not holding a PHPStan service — it just did not want to write `new`
                // twice. Classified here or the property read refuses as an unwired configured value, which points
                // at the package's neon for something the neon has no business wiring.
                if ($param->type instanceof Name && $param->type->getLast() === 'NodeFinder') {
                    $this->context->finders[$name] = true;

                    continue;
                }

                // The package may not wire this rule at all. A declared type is still evidence: nothing but a
                // PHPStan service is spelled `ReflectionProvider`, and classifying it says what would have to
                // be translated instead of calling the property unknown.
                $service = $this->serviceTypeOf($param);
                if ($service !== null) {
                    $this->context->injected[$name] = $service;

                    continue;
                }

                if ($this->takeOwnObject($name, $param, $configuration)) {
                    continue;
                }

                // A constructor parameter the neon does not wire and whose type names no service. Recorded so
                // that reading it refuses by naming *that* — `hihaho/phpstan-rules` registers only the
                // constructor and nullsafe variants of its positional-flag family, and the two it leaves to a
                // combined rule refused with `unknown local $this`, which points at nothing.
                $this->context->unwired[$name] = $className;
                $this->context->ruleIsUnregistered = self::isUnregistered($configuration, $className);

                continue;
            }

            if ($argument['kind'] === 'service') {
                $this->context->injected[$name] = $argument['reference'];

                continue;
            }

            $default = $configuration instanceof PackageConfiguration && $configuration->hasParameter($argument['reference'])
                ? $configuration->defaultFor($argument['reference'])
                : $argument['reference'];

            $this->context->configured[$name] = [
                'parameter' => $argument['reference'],
                'default' => $default,
                'kind' => $this->translator->configKind($default),
            ];
        }

        $this->traceConstructorBody($constructor);
    }

    /**
     * Follows what the constructor body derives, so a derived property is refused for the right reason.
     *
     * Every rule in the corpus that has a constructor also has a body. Some derive a lookup table from
     * configured values and constants, which is translatable; one resolves a class through
     * `ReflectionProvider` and stores the reflection, which is not. Both used to read back as
     * `unknown local $this`, which named neither. Tracing the assignments lets the refusal say which it is.
     */
    private function traceConstructorBody(ClassMethod $constructor): void
    {
        foreach ($constructor->stmts ?? [] as $statement) {
            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                continue;
            }

            $target = $statement->expr->var;
            if (! $target instanceof PropertyFetch
                || ! $target->var instanceof Variable
                || $target->var->name !== 'this'
            ) {
                continue;
            }

            $property = $this->translator->memberName($target->name, $statement->getStartLine());

            // `$this->nodeFinder = new NodeFinder();` — a stateless helper the rule constructs once rather than
            // per call. Not a derivation of anything: the same handle `new NodeFinder()` produces inline.
            $constructed = $statement->expr->expr;
            if ($constructed instanceof New_
                && $constructed->class instanceof Name
                && $constructed->class->getLast() === 'NodeFinder'
            ) {
                $this->context->finders[$property] = true;

                continue;
            }

            $service = $this->serviceBehind($statement->expr->expr);
            if ($service !== null) {
                $this->context->injected[$property] = $service;

                continue;
            }

            if ($this->isPureDerivation($statement->expr->expr)) {
                $this->context->pure[$property] = $statement->expr->expr;

                continue;
            }

            // A pass that only validates the configured names and rewrites them to their declared spelling.
            // The plugin carries the configured map itself and folds case where it compares — see
            // {@see canonicalisingPass()} for why that is the same question rather than an approximation.
            $aliased = $this->canonicalisingPass($statement->expr->expr);
            if ($aliased !== null) {
                // Through `foldedKeys()`, not as the raw value: the pass being dropped keyed its map by each
                // name's declared spelling, so two configured keys naming one trait in different cases became a
                // single entry. Carrying the map as written kept both, and the case-insensitive match at the use
                // site then found both — the same finding reported twice.
                $this->context->pure[$property] = new StaticCall(
                    new Name('Support'),
                    'foldedKeys',
                    [new Arg(new Variable($aliased))],
                );

                continue;
            }

            // Two different obstacles, and the refusal has to say which. Either the package wires nothing
            // for this rule, so there is no configured value to derive from, or it wires values and the
            // derivation still reaches outside the pure set. Only the second is a transpiler gap.
            $this->context->derived[$property] = $this->context->configured === []
                ? 'the package wires no configured values for this rule, so there is nothing to derive from'
                : 'the derivation reaches outside the set the generated constructor can carry';
        }
    }

    /**
     * Refuses a call whose receiver is a property holding a PHPStan service, naming the service.
     *
     * The method is a symptom; the service behind it is what would have to be translated onto Mago's
     * codebase. Saying `->hasClass() is outside the vocabulary` points at the wrong thing to fix.
     */

    /**
     * The PHPStan service a constructor parameter's declared type names, if it names one.
     *
     * Only for a rule the package's neon does not wire, where the type is the only evidence there is. Kept to
     * the services the corpus actually injects rather than guessing from any unknown class: an unrecognised
     * type stays unknown, which is the honest answer.
     */
    private function serviceTypeOf(Param $param): ?string
    {
        $type = $param->type;
        if (! $type instanceof Name) {
            return null;
        }

        $short = $type->getLast();

        return match ($short) {
            'ReflectionProvider' => 'reflectionProvider',
            'Parser' => 'parser',
            'RuleLevelHelper' => 'ruleLevelHelper',
            default => null,
        };
    }

    /**
     * Whether a constructor derivation touches nothing but configured values, literals and pure functions.
     *
     * The PHP target can carry such a derivation verbatim: the generated plugin is PHP, the rule's own
     * constructor parameters are in scope there under the same names, and every function below is a pure
     * array or string operation. That is a copy, not an approximation — which is why the allowlist is closed
     * and anything else is refused. A method call, a `$this->` read, a `new`, a class constant, or a function
     * nobody vouched for all make it impure: any of them could depend on state the plugin does not have, and a
     * class constant provably does — the generated plugin carries no constants.
     */
    private function isPureDerivation(Expr $expr): bool
    {
        foreach ((new NodeFinder())->find([$expr], static fn (Node $node): bool => true) as $node) {
            if (! $this->isPureNode($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The configured value a constructor derivation is a canonicalising pass over, or null.
     *
     * `TraitRequiresInterfaceRule` derives its trait => interface map by walking the configured one, throwing
     * on a name that is not an existing trait or interface, and storing each name as the reflection reports
     * it. Neither half survives translation, and neither needs to:
     *
     * - The validation throws at construction for a misconfigured pair. A plugin that does not throw is not
     *   wider in what it *reports*: a trait that does not exist is used by no class, so the pair matches
     *   nothing either way.
     * - The rewriting exists so a configured name spelled in a different case still matches. Mago's metadata
     *   lowercases every name it holds, so a case-insensitive comparison at the use site asks exactly that
     *   question. Probed, not assumed — `usedTraits` came back lowercased, and transitive.
     *
     * So the property is an alias for the configured value, recorded as a derivation whose expression *is*
     * that value. Everything downstream then works unchanged: the constructor assigns it, and a read of it
     * resolves like any other carried configuration.
     *
     * One divergence, and it is the configured spelling that causes it: where a configured name differs in
     * case from the declaration, the original's message names the declared spelling and the port names the
     * configured one. Mago's class store answers nothing for a trait name, so there is no declared spelling
     * to recover at the use site.
     */
    private function canonicalisingPass(Expr $expr): ?string
    {
        if (! $this->translator->isOwnMethodCall($expr) || count($expr->getArgs()) !== 1) {
            return null;
        }

        $over = $expr->getArgs()[0]->value;
        if (! $over instanceof Variable || ! is_string($over->name) || ! isset($this->context->configured[$over->name])) {
            return null;
        }

        $declaring = $this->translator->declaringOf($expr->name->toString());
        if ($declaring === null) {
            return null;
        }

        return $this->rewritesNamesOnly($this->translator->findMethod($declaring['class'], $expr->name->toString()), $over->name)
            ? $over->name
            : null;
    }

    /**
     * Whether a helper walks one map and builds another holding the same names, and does nothing else.
     *
     * `$out = []; foreach ($configured as $k => $v) { <guards that throw> $out[<k>] = <v>; } return $out;`
     * where each side of the assignment is the loop variable itself or that variable put through the
     * reflection lookup that yields its declared spelling.
     */
    private function rewritesNamesOnly(ClassMethod $helper, string $parameter): bool
    {
        $statements = $helper->stmts ?? [];
        if (count($statements) !== 3
            || ! $statements[0] instanceof Expression
            || ! $statements[0]->expr instanceof Assign
            || ! $statements[0]->expr->var instanceof Variable
            || ! is_string($statements[0]->expr->var->name)
            || ! $statements[0]->expr->expr instanceof Array_
            || $statements[0]->expr->expr->items !== []
            || ! $statements[1] instanceof Foreach_
            || ! $statements[2] instanceof Return_
        ) {
            return false;
        }

        $built = $statements[0]->expr->var->name;
        [, $loop, $return] = $statements;

        if (! $return->expr instanceof Variable || $return->expr->name !== $built) {
            return false;
        }

        if (! $loop->expr instanceof Variable
            || $loop->expr->name !== $parameter
            || ! $loop->keyVar instanceof Variable
            || ! is_string($loop->keyVar->name)
            || ! $loop->valueVar instanceof Variable
            || ! is_string($loop->valueVar->name)
        ) {
            return false;
        }

        return $this->storesBothNames($loop->stmts, $built, $loop->keyVar->name, $loop->valueVar->name);
    }

    /**
     * The loop body: guards that only throw, then one assignment carrying both names into the built map.
     *
     * @param array<Stmt> $body
     */
    private function storesBothNames(array $body, string $built, string $key, string $value): bool
    {
        $stored = null;
        foreach ($body as $statement) {
            if ($statement instanceof If_ && $this->throwsOnly($statement)) {
                continue;
            }

            if ($stored instanceof Assign || ! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                return false;
            }

            $stored = $statement->expr;
        }

        if (! $stored instanceof Assign
            || ! $stored->var instanceof ArrayDimFetch
            || ! $stored->var->var instanceof Variable
            || $stored->var->var->name !== $built
            || ! $stored->var->dim instanceof Expr
        ) {
            return false;
        }

        return $this->isDeclaredSpellingOf($stored->var->dim, $key)
            && $this->isDeclaredSpellingOf($stored->expr, $value);
    }

    /** A guard whose only job is to reject a misconfiguration, which the generated plugin does not carry. */
    private function throwsOnly(If_ $guard): bool
    {
        return $guard->elseifs === []
            && ! $guard->else instanceof Else_
            && count($guard->stmts) === 1
            && $guard->stmts[0] instanceof Expression
            && $guard->stmts[0]->expr instanceof Throw_;
    }

    /** `$name`, or the reflection lookup that rewrites `$name` to the spelling its declaration uses. */
    private function isDeclaredSpellingOf(Expr $expr, string $name): bool
    {
        if ($expr instanceof Variable && $expr->name === $name) {
            return true;
        }

        if (! $expr instanceof MethodCall
            || $this->translator->memberName($expr->name, $expr->getStartLine()) !== 'getName'
            || ! $expr->var instanceof MethodCall
            || $this->translator->memberName($expr->var->name, $expr->var->getStartLine()) !== 'getClass'
            || count($expr->var->getArgs()) !== 1
        ) {
            return false;
        }

        $argument = $expr->var->getArgs()[0]->value;

        return $argument instanceof Variable
            && $argument->name === $name
            && $this->serviceBehind($expr->var->var) !== null;
    }

    /** One node of a derivation, judged on its own; see {@see isPureDerivation()} for what that means. */
    private function isPureNode(Node $node): bool
    {
        if ($node instanceof MethodCall || $node instanceof StaticCall || $node instanceof New_) {
            return false;
        }

        // `$this->earlier` where `earlier` is a property this same constructor already derived: the generated
        // constructor assigns them in order, so the copy reads exactly what the original read. Any other
        // property read is state the plugin does not have.
        if ($node instanceof PropertyFetch) {
            return $node->var instanceof Variable
                && $node->var->name === 'this'
                && isset($this->context->pure[$this->translator->memberName($node->name, $node->getStartLine())]);
        }

        // A class constant used to make a derivation impure, because the generated plugin carried no constants
        // and copying the derivation emitted a reference to nothing. The plugin can carry it instead, so the
        // constant is *taken* here and declared alongside the constructor.
        if ($node instanceof ClassConstFetch) {
            return $this->takeDerivedConstant($node);
        }

        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && in_array($node->name->toString(), self::PURE_FUNCTIONS, true);
        }

        // `$this` is not a value the derivation reads, it is the receiver of a property read judged above.
        if ($node instanceof Variable && $node->name === 'this') {
            return true;
        }

        return ! $node instanceof Variable || ! is_string($node->name) || isset($this->context->configured[$node->name]);
    }

    /**
     * Records a class constant a derivation reads, so the generated plugin can declare it.
     *
     * Only `self::` or `static::` naming an array constant this class or its hierarchy declares: a constant on
     * *another* class is that class's business, and a scalar has not been needed. False leaves the derivation
     * impure, which is the safe answer.
     */
    private function takeDerivedConstant(ClassConstFetch $fetch): bool
    {
        if (self::$target !== 'php'
            || ! $fetch->class instanceof Name
            || ! in_array($fetch->class->toString(), ['self', 'static'], true)
            || ! $fetch->name instanceof Identifier
        ) {
            return false;
        }

        $name = $fetch->name->toString();
        $value = $this->context->currentClass instanceof ClassLike ? $this->constantValue($this->context->currentClass, $name) : null;
        if (! $value instanceof Array_) {
            return false;
        }

        $this->context->carriedConstants[$name] = $value;

        return true;
    }

    /** A constant's value expression, looked up the way `self::` resolves it: this class, then its ancestry. */
    private function constantValue(ClassLike $class, string $name): ?Expr
    {
        foreach ($this->translator->hierarchy()->selfAndAncestors($class) as $declaring) {
            foreach ($declaring->getConstants() as $const) {
                foreach ($const->consts as $candidate) {
                    if ($candidate->name->toString() === $name) {
                        return $candidate->value;
                    }
                }
            }
        }

        return null;
    }

    /** The PHPStan service an expression reaches for, if any. */
    private function serviceBehind(Expr $expr): ?string
    {
        foreach ((new NodeFinder())->findInstanceOf([$expr], Variable::class) as $variable) {
            if (is_string($variable->name) && isset($this->context->injected[$variable->name])) {
                return $this->context->injected[$variable->name];
            }
        }

        foreach ((new NodeFinder())->findInstanceOf([$expr], PropertyFetch::class) as $fetch) {
            if ($fetch->var instanceof Variable && $fetch->var->name === 'this') {
                $name = $this->translator->memberName($fetch->name, $expr->getStartLine());
                if (isset($this->context->injected[$name])) {
                    return $this->context->injected[$name];
                }
            }
        }

        return null;
    }

    /**
     * What one level of an "any of them" loop tests: a guard, or a further loop.
     *
     * php-parser models attributes in two levels — a declaration has attribute *groups*, each holding attributes
     * — so `foreach ($groups as $group) { foreach ($group->attrs as $attr) { if (..) return true; } }` is the only
     * way to ask "does it carry this attribute". One level cannot express that, and flattening the two into one
     * would be inventing a shape the source does not have.
     */

    /**
     * Records a parameter holding an object of this package's own, and says whether it did.
     *
     * Two kinds, and only one of them has its methods inlined. A **configuration value object** resolves its
     * getters to the neon parameters they read — inlining one instead reached `$this->parameters[...]` on an
     * object the plugin does not have, and refused as `unknown local $this`, a message about the receiver
     * rather than about the shape. A **collaborator** is anything else the rule delegates to, whose methods
     * are inlined like a trait's or a parent's, so a package that keeps a small analyzer on its own class does
     * not become a vocabulary gap.
     *
     * The value object is checked first: both are "an object from this package", and the wrong order would
     * inline the one that must not be.
     */
    private function takeOwnObject(string $name, Param $param, ?PackageConfiguration $configuration): bool
    {
        $valueObject = $this->valueObjectFor($param, $configuration);
        if ($valueObject instanceof ConfigurationObject) {
            $this->context->valueObjects[$name] = $valueObject;

            return true;
        }

        if ($param->type instanceof Name) {
            $this->context->collaborators[$name] = $param->type->getLast();
        }

        return false;
    }

    /**
     * The configuration value object a constructor parameter declares, or null when it declares something else.
     *
     * Recognised the same way the aggregate path recognises it: the neon builds the class from exactly one
     * parameter root, which is what {@see PackageConfiguration::valueObjectRoot()} answers, and the class sits
     * beside the rules one directory up from `Rules/` — where every package checked puts it.
     */
    private function valueObjectFor(Param $param, ?PackageConfiguration $configuration): ?ConfigurationObject
    {
        if (! $param->type instanceof Name || ! $configuration instanceof PackageConfiguration) {
            return null;
        }

        $candidate = dirname($this->file, 2) . '/' . $param->type->getLast() . '.php';
        if (! is_file($candidate)) {
            return null;
        }

        $namespace = SourceIndex::namespaceOf(
            SourceIndex::parse((string) file_get_contents($candidate)) ?? [],
            $param->type->getLast(),
        );
        if ($namespace === null) {
            return null;
        }

        $root = $configuration->valueObjectRoot($namespace . '\\' . $param->type->getLast());

        return $root === null ? null : ConfigurationObject::fromFile($candidate, $root);
    }

    /**
     * `$classReflection->getParentClassesNames()` — the ancestry, parents only.
     *
     * `ClassLikeMetadata` keeps that list under `parentClasses`, which is not `getClassAncestors()`: that one
     * folds in interfaces and traits, and a rule walking parents to find an overridden method means parents.
     *
     * @return Descriptor|null
     */

    /**
     * One branch of a choice, as a PHP string expression.
     *
     * An interpolation is the interesting case: `"request('{$firstArg->value}')"` is a label built around
     * something read off the node, and it becomes a concatenation of the literal parts with the value between
     * them. Only the PHP target, which is where a produced value is a PHP string in the first place.
     */

    /**
     * How many independent checks the rule's body asks of one node.
     *
     * A check is `$x = $this->someError(..)` where the helper builds a rule error: the rule keeps whatever
     * came back, and moves on to ask the next one. Counted before translation because the answer decides how
     * the whole body is emitted, and a rule asking one check must emit what it emits today.
     */
    private function independentChecks(ClassMethod $processNode): int
    {
        $checks = 0;
        foreach ($processNode->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Expression || ! $stmt->expr instanceof Assign) {
                continue;
            }

            $call = $stmt->expr->expr;
            if (! $stmt->expr->var instanceof Variable || ! $this->translator->isOwnMethodCall($call)) {
                continue;
            }

            $method = $call->name->toString();
            $declaring = $this->translator->declaringOf($method);
            if ($declaring !== null && $this->translator->buildsRuleError($this->translator->findMethod($declaring['class'], $method))) {
                ++$checks;
            }
        }

        return $checks + $this->branchChecks($processNode);
    }

    /**
     * How many of the rule's checks are written as a branch rather than a helper call.
     *
     * `if (<this is my case>) { <guards> return [$error]; }`, twice over, is how a rule registered for several
     * node kinds handles each of them — `NoDynamicNameRule` has one branch for the two static accesses and one
     * for the four calls. Each is a check in the same sense a helper is: its guards decline that case, not the
     * rule, and `return []` inside one has to mean "not this branch" rather than "not this node".
     */
    private function branchChecks(ClassMethod $processNode): int
    {
        $checks = 0;
        foreach ($processNode->stmts ?? [] as $statement) {
            if ($statement instanceof If_ && $this->translator->isBranchCheck($statement)) {
                ++$checks;
            }
        }

        return $checks;
    }

    /**
     * Whether the package ships no neon naming this rule, which is why nothing wires it.
     *
     * Its own method so {@see collectConfiguration()} keeps the complexity it had; the answer itself is one
     * question asked of the package configuration.
     */
    private static function isUnregistered(?PackageConfiguration $configuration, string $className): bool
    {
        return $configuration instanceof PackageConfiguration && ! $configuration->registers($className);
    }

    /**
     * The node type a rule registers for, from the rule itself or from what it inherits.
     *
     * The inherited half is not decoration. `phpat/phpat` writes all but two of its rules as a two-line class —
     * `extends ShouldNotDepend implements Rule`, plus a `use` for an extractor — and declares `getNodeType()`
     * in none of them. The other two state even less: no `implements` clause either. Read from the rule's own methods alone, every one of them refused as though it had no
     * node type at all, which is a refusal that names the wrong thing about the rule.
     *
     * `Hierarchy::declaring()` is the same walker a static helper reference already resolves through, so a
     * base class or a trait is found the way one is anywhere else.
     */
    private function findNodeType(Class_ $class): string
    {
        $method = $this->inheritedRuleMethod($class, 'getNodeType');
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Return_
                && $stmt->expr instanceof ClassConstFetch
                && $stmt->expr->class instanceof Name
            ) {
                return $this->translator->resolveClassName($stmt->expr->class);
            }
        }

        throw new Refusal('getNodeType() is not a simple `return X::class`');
    }

    /** @return list<string> every obstacle this rule's body met, once collection was asked for */
    public function needs(): array
    {
        return $this->needs;
    }

    /**
     * One statement of `processNode()`, or — in survey mode — its obstacle, recorded and stepped over.
     *
     * {@see $needs} says why. Outside survey mode the refusal propagates, because a generator that skips
     * what it cannot translate emits a rule that runs and answers a different question.
     */
    private function translateOrCollect(Stmt $stmt): void
    {
        if (! self::$survey || ! self::$collectNeeds) {
            $this->translator->translateStatement($stmt);

            return;
        }

        try {
            $this->translator->translateStatement($stmt);
        } catch (Refusal $refusal) {
            $reason = trim((string) preg_replace('/ \(line \d+\)/', '', $refusal->getMessage()));
            if (! in_array($reason, $this->needs, true)) {
                $this->needs[] = $reason;
            }
        }
    }

    /**
     * One of the rule's two required methods, as it declares it or as it inherits it.
     *
     * The rule's own class first, because that is where all but one package writes both. The hierarchy after,
     * through the walker a static helper reference already resolves by — and only then, so a rule declaring
     * the method keeps behaving exactly as it did.
     *
     * **The import map moves with the method.** A name in an inherited body resolves through the *base's*
     * imports, not the rule's, and reading it the other way is silent rather than loud: the fixture's
     * `instanceof Identifier` resolved to a class in the rule's own namespace, which exists nowhere, and the
     * refusal blamed the member selector. {@see Uses} records the same trap from the helper-inlining side.
     */
    private function inheritedRuleMethod(Class_ $class, string $name): ClassMethod
    {
        try {
            return $this->translator->findMethod($class, $name);
        } catch (Refusal $refusal) {
            $declaring = $this->translator->declaringOf($name);
            if ($declaring === null) {
                throw $refusal;
            }

            $this->context->useMap = $declaring['uses'];
            $this->context->ruleNamespace = $declaring['namespace'];
            $this->context->currentClass = $declaring['class'];

            return $this->translator->findMethod($declaring['class'], $name);
        }
    }

    // -----------------------------------------------------------------------
    // Statements
    // -----------------------------------------------------------------------

    /** The report, as a statement at the current indentation. */

    /**
     * The report as an emitted statement.
     *
     * Rust keeps the finished text, so its output stays byte-identical; PHP carries the pieces and lets
     * the backend format them, since the two languages do not agree on how a report is written.
     */

    // -----------------------------------------------------------------------
    // Local bindings
    // -----------------------------------------------------------------------

    /** `$node->getArgs()[N]`, `$args[N]`, and either of those with `->value`. */

    // -----------------------------------------------------------------------
    // Conditions
    // -----------------------------------------------------------------------

    /**
     * A question about an expression's inferred type.
     *
     * The two callers differ only in the helper and how the argument is read, so the part that matters,
     * which position the SDK will answer for, is written once.
     */

    /**
     * A predicate that cannot be false in Mago's model, with the proof that says so.
     *
     * The mirror of {@see unreachable()}. Both record why; which one applies depends on whether the rule asks the
     * question straight or negated, and a guard is dropped when the *bail* folds to false either way.
     */

    // -----------------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // Literals
    // -----------------------------------------------------------------------

    /**
     * basename -> paths, over every vendor tree the rules seen so far live in.
     *
     * Merged and keyed by root rather than cached from the first rule: a rule file outside any
     * vendor directory would otherwise poison the cache with an empty index, and which rule that is
     * depends on argument order.
     */
    /** Node-kind names PHP reserves, which the SDK spells with a trailing underscore. */
    /**
     * Node kinds the SDK renames because PHP will not let the case be referenced.
     *
     * Exactly one, checked against the enum rather than assumed from a list of reserved words: PHP allows a
     * reserved word after `::`, so `NodeKind::Foreach` is legal and is declared bare. `class` is the exception,
     * because `::class` yields the class-name string instead — which is why the SDK spells that one case `Class_`.
     *
     * This list used to hold twenty-six words. Nothing was wrong while `Class` was the only reserved kind any hook
     * targeted; the first other one, `Foreach`, emitted `NodeKind::Foreach_` and the enum has no such case.
     */
    /**
     * php-parser classes that stand for a family rather than a node.
     *
     * A rule returning one of these from `getNodeType()` is asking PHPStan for every node beneath it. Listed so
     * the refusal can say that, instead of reporting a missing hook mapping for something no single hook covers.
     *
     * `ClassLike` was briefly listed here and is not, because listing it was the wrong repair. It is a family
     * the same as the three roots, but `HOOK_KINDS` already knew its four kinds and the PHP target already
     * registers a plugin for each, so what it wanted was a `HOOKS` row rather than a better refusal. A hook
     * row wins over this list: the family check runs only where `HOOKS` has nothing.
     *
     * @var list<class-string>
     */
    private const array MULTI_KIND_NODE_TYPES = [
        'PhpParser\Node\Expr',
        'PhpParser\Node',
        'PhpParser\Node\Stmt',
    ];

    // -----------------------------------------------------------------------
    // Emission
    // -----------------------------------------------------------------------

    /**
     * The good and bad examples a generated linter rule documents itself with.
     *
     * Taken from the differential corpus rather than written by hand, because the corpus already says
     * which construct makes a rule fire and which near miss must leave it quiet, and those statements
     * are checked against PHPStan on every run. Inventing a second set of examples would mean
     * maintaining an unverified copy of the same claim.
     *
     * The unit extracted is the top-level declaration containing the annotation, with the file's
     * header, so the snippet parses on its own. `Mago`'s own example test lints it with only this rule
     * enabled, so other rules' annotations inside the same unit do not matter.
     *
     * @return array{string, string} good, bad
     */

    /**
     * The PHP target's wrapper: a Mago SDK analyzer plugin.
     *
     * `getTargets()` does in PHP what the Rust adapter does by narrowing `Expression`, because the
     * hook table's `kind` already names the Mago node kind the SDK's `NodeKind` uses.
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     */
}

/** Assembles the emitted rules into one plugin module, so the whole output is generated. */
