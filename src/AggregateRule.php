<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;

/**
 * A rule that reduces a collection rather than looking at a node.
 *
 * PHPStan spells this as a pair: a Collector gathering a fact per file, and a Rule on `CollectedDataNode`
 * reducing the collection. Mago has no collector, so there is nothing to translate statement by statement —
 * but an after-analysis hook sees every file at once and can report, so the pair is re-emitted as one hook
 * that asks the codebase the same question.
 *
 * Almost everything needed comes from the rule's own source: the message format is its `ERROR_MESSAGE`, the
 * code is the identifier it reports under, and the threshold is a configured parameter reached through a
 * `Configuration` getter. The single fact that cannot be read is *which measurement* a collector contributes
 * to, which is why {@see Vocabulary::AGGREGATES} names those and a collector outside it is refused.
 */
final readonly class AggregateRule
{
    private function __construct(
        public string $metric,
        public string $identifier,
        public string $message,
        /** @var list<string> the parameter paths the rule's getter falls back through, in that order */
        public array $thresholds,
        public float $default,
    ) {}

    /**
     * Reads a `CollectedDataNode` consumer, or returns null when the class is not one.
     *
     * @throws Refusal when it is one but names a collector, a message or a threshold that cannot be resolved
     */
    public static function from(ClassLike $class, string $ruleFile, ?PackageConfiguration $configuration): ?self
    {
        $collector = self::collectorNamed($class);
        if ($collector === null) {
            return null;
        }

        // A rule that never builds a finding is not a rule in Mago's terms: `report()` is an analyzer
        // plugin's only output. This one writes a JSON manifest with `file_put_contents` and returns `[]`
        // always — a build artefact wearing a rule's interface. Named specifically, because "no
        // ERROR_MESSAGE" would read as a missing constant rather than as a different kind of thing.
        if (self::reportsNothing($class)) {
            throw new Refusal(
                'this rule reports nothing: it writes a file and returns no findings, so there is nothing for '
                . "a plugin to report. An analyzer plugin's only output is report(), and agreement has no "
                . 'meaning for a build artefact',
                permanent: true,
            );
        }

        if (! isset(Vocabulary::AGGREGATES[$collector])) {
            throw new Refusal("no aggregate mapped for the collector {$collector}");
        }

        $metric = Vocabulary::AGGREGATES[$collector];
        $withheld = Vocabulary::unverifiedAggregate($metric);
        if ($withheld !== null && ! Transpiler::$allowUnverified) {
            throw new Refusal($withheld);
        }

        $message = self::constant($class, 'ERROR_MESSAGE')
            ?? throw new Refusal('an aggregate rule with no ERROR_MESSAGE to report');

        $identifier = self::constant($class, 'IDENTIFIER')
            ?? throw new Refusal('an aggregate rule with no IDENTIFIER to report under');

        [$thresholds, $default] = self::threshold($class, $ruleFile, $configuration);

        return new self($metric, $identifier, $message, $thresholds, $default);
    }

    /**
     * Whether every consumer of this collector reports nothing, which makes the pair unportable as a rule.
     *
     * A collector alone has no output; it exists to feed a `CollectedDataNode` rule. When every such rule in
     * the package writes a file instead of reporting, the pair cannot become a plugin whatever the collector's
     * body does — so the refusal names that rather than whichever construct the body happened to trip on.
     *
     * Traced by scanning the package for a rule that names this collector, not inferred from its name.
     */
    public static function onlyFeedsAWriter(string $collectorFile): bool
    {
        $collector = basename($collectorFile, '.php');
        $root = dirname($collectorFile, 2);
        $consumers = glob($root . '/Rules/{,*/,*/*/}*.php', GLOB_BRACE);
        if ($consumers === false) {
            return false;
        }

        $found = false;
        foreach ($consumers as $file) {
            $source = (string) file_get_contents($file);
            if (! str_contains($source, $collector . '::class')) {
                continue;
            }

            $found = true;
            if (str_contains($source, 'RuleErrorBuilder')) {
                return false;
            }
        }

        return $found;
    }

    /** Whether the class never builds a finding, which makes it a writer rather than a rule. */
    private static function reportsNothing(ClassLike $class): bool
    {
        foreach ((new NodeFinder())->findInstanceOf([$class], StaticCall::class) as $call) {
            if ($call->class instanceof Name && $call->class->getLast() === 'RuleErrorBuilder') {
                return false;
            }
        }

        return true;
    }

    /** The short name of the collector this rule reduces, or null when it reduces none. */
    private static function collectorNamed(ClassLike $class): ?string
    {
        foreach ((new NodeFinder())->findInstanceOf([$class], MethodCall::class) as $call) {
            if (! $call->name instanceof Identifier || $call->name->toString() !== 'get' || count($call->getArgs()) !== 1) {
                continue;
            }

            $argument = $call->getArgs()[0]->value;
            if ($argument instanceof ClassConstFetch
                && $argument->class instanceof Name
                && $argument->name instanceof Identifier
                && strtolower($argument->name->toString()) === 'class'
            ) {
                return $argument->class->getLast();
            }
        }

        return null;
    }

    /**
     * The configured threshold the rule compares against, as the parameter paths it reads and its default.
     *
     * Reached through the `Configuration` getter the rule calls, which {@see ConfigurationObject} reduces to
     * the parameters it reads. Refused rather than defaulted when it cannot be resolved: a coverage rule with
     * a guessed threshold would report against a number nobody chose.
     *
     * **All the paths, not the first that resolves.** A getter reading
     * `$this->parameters['constant'] ?? $this->parameters['constant_type']` reads two, and the package
     * declares the alias as `null` — so the first with a *numeric* default is the fallback, never the alias.
     * Recording only that one is right about the default and wrong about the consumer: someone who sets
     * `constant: 0` has set the alias, and a plugin carrying `constant_type` never sees it. That is why
     * `constantTypeCoverage` stayed 18 findings apart after the thresholds were otherwise aligned.
     *
     * @return array{list<string>, float}
     */
    private static function threshold(ClassLike $class, string $ruleFile, ?PackageConfiguration $configuration): array
    {
        if (! $configuration instanceof PackageConfiguration) {
            throw new Refusal('an aggregate rule whose package declares no configuration to read a threshold from');
        }

        foreach ((new NodeFinder())->findInstanceOf([$class], MethodCall::class) as $call) {
            if (! $call->name instanceof Identifier) {
                continue;
            }

            $getter = $call->name->toString();
            if (! str_starts_with($getter, 'getRequired') && ! str_starts_with($getter, 'getMax')) {
                continue;
            }

            $object = self::configurationObject($ruleFile, $configuration);
            $paths = array_values(array_filter(
                $object?->pathsFor($getter) ?? [],
                $configuration->hasParameter(...),
            ));

            foreach ($paths as $path) {
                $default = $configuration->defaultFor($path);
                if (is_int($default) || is_float($default)) {
                    return [$paths, (float) $default];
                }
            }

            throw new Refusal("no configured threshold behind {$getter}()");
        }

        throw new Refusal('an aggregate rule that compares against no configured threshold');
    }

    private static function configurationObject(string $ruleFile, PackageConfiguration $configuration): ?ConfigurationObject
    {
        // The value object sits beside the rules, one directory up from `Rules/`, which is where every package
        // checked puts it.
        $candidate = dirname($ruleFile, 2) . '/Configuration.php';
        $namespace = self::namespaceOf($candidate);
        if ($namespace === null) {
            return null;
        }

        $root = $configuration->valueObjectRoot($namespace . '\\Configuration');

        return $root === null ? null : ConfigurationObject::fromFile($candidate, $root);
    }

    private static function namespaceOf(string $file): ?string
    {
        if (! is_file($file)) {
            return null;
        }

        if (preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private static function constant(ClassLike $class, string $name): ?string
    {
        foreach ($class->getConstants() as $constant) {
            foreach ($constant->consts as $const) {
                if ($const->name->toString() === $name && $const->value instanceof String_) {
                    return $const->value->value;
                }
            }
        }

        return null;
    }
}
