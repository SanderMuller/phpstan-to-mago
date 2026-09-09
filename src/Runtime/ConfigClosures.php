<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Sandermuller\PhpstanToMago\Vocabulary;

/**
 * What a Symfony config closure declares about itself, which `FileNameMatchesExtensionRule` reads.
 *
 * `findExtensionName()` runs php-parser's `NodeFinder` with a callback that mutates a captured variable — a
 * walk whose answer is a side effect rather than a value, which the vocabulary has no statements for. So the
 * *question* is ported, the way {@see Returns} and {@see StaticReflectionTypes} are.
 *
 * **The callback's `STOP_TRAVERSAL` does nothing, and the port was written twice because of it.** The callback
 * ends with `return NodeVisitor::STOP_TRAVERSAL;`, which reads as "stop at the first `extension()` call". It
 * does not: `NodeFinder::find()` takes a *predicate*, not a visitor callback, and `FindingVisitor::enterNode()`
 * uses the return value only for its truthiness before returning `null` itself. The traversal is never
 * stopped. So the answer is **the last string argument of the last `extension()` call anywhere below the
 * closure**, and a first-call-wins port is silent where the original reports.
 *
 * That was not settled by reading the rule. The first port stopped at the first call, and PHPStan reported a
 * finding the port did not on a fixture written to pin the stop — `stopsOnTheFirstCall` in the example pair,
 * now named for what it actually measures. Reading `NodeFinder::find()` afterwards explained it. The rule's
 * own author appears to have believed the stop worked; the port has to match the behaviour, not the intent.
 *
 * Two further shapes, both ported as written:
 *
 * - **Within one call the last string argument wins.** The `foreach` assigns without breaking, so
 *   `extension('a', 'b')` contributes `b`. Confirmed by a row the original leaves silent.
 * - **The walk is blind.** It descends into nested closures, so an `extension()` call written inside one
 *   counts.
 *
 * Only `MethodCall` matches, because that is the class php-parser tests. Mago spells a nullsafe call as its own
 * `NullSafeMethodCall` kind, and matching it too would report where the original is silent — the same hazard
 * `internal/handoff-multi-kind-hook-is-not-a-redesign.md` records for the assert rules, from the other side.
 *
 * @internal to the runtime. An emitted plugin calls this directly, the way it calls {@see RectorAutoloadedTypes}.
 */
final class ConfigClosures
{
    /**
     * Each constructor parameter of the service a `set()` call in this receiver chain names, against the class
     * its type names.
     *
     * `ClassConstructorTypesResolver::resolveClassConstructorNamesToTypes()` in one question, which is what
     * {@see Vocabulary::COLLABORATOR_CALLS} is for: the collaborator walks a
     * receiver chain to the `set()` that named the service, reads that class's constructor through PHPStan's
     * reflection, and keeps the parameters whose type is an object. Mago answers the same three steps -- the
     * chain through {@see Chains::chainedCallNamed()}, the class name from the call's own arguments, and the
     * parameters from `getMethod($class, '__construct')`.
     *
     * A service named by a string rather than a class constant answers nothing, exactly as the original does:
     * it reads `NamingHelper::getName()` off a `ClassConstFetch` and returns null for anything else.
     *
     * @return array<string, string>
     */
    public static function constructorParameterTypes(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $set = Chains::chainedCallNamed($context, $subject, 'set');
        if (! $set instanceof Part) {
            return [];
        }

        $arguments = Calls::argumentList($context, $set);
        $class = ConstantStrings::at($context, Calls::positionalArgAt($arguments, 1))
            ?? ConstantStrings::at($context, Calls::positionalArgAt($arguments, 0));
        if ($class === null || $class === '') {
            return [];
        }

        $constructor = $context->codebase->getMethod($class, '__construct');
        if (! $constructor instanceof FunctionLikeMetadata) {
            return [];
        }

        $types = [];
        foreach ($constructor->parameters as $parameter) {
            $named = $parameter->declaredType ?? $parameter->type;
            $object = $named === null ? null : Types::soleObjectClass($named->type);
            if ($object !== null && $object !== '') {
                // Keyed without the sigil: mago's `ParameterMetadata->name` is `$dependency` where
                // PHPStan's `ParameterReflection::getName()` is `dependency`, and the rules reading this map
                // look a key up by the name they ltrimmed out of `arg('$dependency', ..)`. Measured with a
                // probe -- the map came back keyed `$dependency` against a lookup of `dependency`, so every
                // key missed and the port reported nothing where PHPStan reports.
                $types[ltrim($parameter->name, '$')] = $object;
            }
        }

        return $types;
    }

    /** The method a Symfony config closure adds a service through. */
    private const string CALL_NAME = 'call';

    /** How many repeats the original treats as worth a tagged iterator. */
    private const int MIN_ALERT_COUNT = 3;

    /**
     * `SymfonyFunctionName::REF` and `::SERVICE`, which the package holds fully qualified.
     *
     * @var list<string>
     */
    private const array REFERENCE_FUNCTIONS = [
        'Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\ref',
        'Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\service',
    ];

    /**
     * `findExtensionName()` — the extension a config closure names, or null.
     *
     * Null covers three situations the original also does not distinguish: no `extension()` call anywhere, one
     * carrying no string argument, and a subject that is not a node at all.
     */
    public static function extensionName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        $name = null;
        self::readEveryExtensionCall($context, $node, $name);

        return $name;
    }

    /**
     * Reads every `extension()` call below this node into `$name`, so the last one written wins.
     *
     * No early exit, which is the whole correction above: the walk the original runs visits the complete
     * subtree whatever its callback returns.
     */
    private static function readEveryExtensionCall(
        NodeAnalysisContext $context,
        Node $node,
        ?string &$name,
    ): void {
        if ($node->kind->value === 'MethodCall') {
            $selector = Calls::selector($context, $node);

            if (Names::selectorIsIdentifier($selector) && Calls::selectorIs($selector, 'extension')) {
                foreach (Calls::arguments(Calls::argumentList($context, $node)) as $argument) {
                    $value = Calls::argumentValue($argument);
                    $literal = $value instanceof Part ? CstLiteral::plainString($value->text) : null;

                    if ($literal !== null) {
                        $name = $literal;
                    }
                }
            }
        }

        foreach ($context->source->getChildren($node) as $child) {
            self::readEveryExtensionCall($context, $child, $name);
        }
    }

    /**
     * The service-adder method name repeated enough times to be worth a tagged iterator.
     *
     * `RepeatedServiceAdderCallNameFinder::find()`. Walks one statement's call chain for
     * `->call(<string>, [<service reference>])`, counts the names, and answers the first that occurs at least
     * three times — so a chain calling `add` twice and `set` once answers nothing, and one calling `add`
     * three times answers `add`.
     *
     * Three details are the original's rather than a reading of it:
     *
     * - **Exactly two arguments**, and the first must be a written string. A `->call()` with a third argument
     *   is not counted at all.
     * - **The second argument must be an array of exactly one element**, and that element a reference call.
     *   A two-element array is not a repeated single-service adder.
     * - **The count is per name**, and the first name over the threshold in traversal order wins. `array_count_values`
     *   preserves first-seen order, which is what the `foreach` after it walks.
     *
     * `ref()` and `service()` are compared against **fully qualified** names, because that is what
     * `SymfonyFunctionName` holds and what PHPStan compares: its `NameResolver` rewrites an imported function
     * call to its FQN before a rule sees it. Mago keeps the written spelling and answers resolution
     * separately, so the resolved name is what this reads — measured, and all four spellings agree:
     * `service(..)` under a `use function` import, `ref(..)`, a written-out FQN, and an unimported `other(..)`
     * which resolves into the current namespace and matches neither. Reading the written text would have
     * matched none of the first three.
     */
    public static function repeatedAdderCallName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        $names = [];
        self::collectAdderCallNames($context, $node, $names);

        foreach (array_count_values($names) as $name => $count) {
            if ($count >= self::MIN_ALERT_COUNT) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * Every `->call(<string>, [<reference>])` name below this node, in traversal order.
     *
     * @param list<string> $names
     */
    private static function collectAdderCallNames(NodeAnalysisContext $context, Node $node, array &$names): void
    {
        if ($node->kind->value === 'MethodCall') {
            $name = self::adderCallName($context, $node);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        foreach ($context->source->getChildren($node) as $child) {
            self::collectAdderCallNames($context, $child, $names);
        }
    }

    /** The name this `->call()` adds a single service under, or null when it is not that shape. */
    private static function adderCallName(NodeAnalysisContext $context, Node $node): ?string
    {
        $selector = Calls::selector($context, $node);
        if (! Names::selectorIsIdentifier($selector) || ! Calls::selectorIs($selector, self::CALL_NAME)) {
            return null;
        }

        $list = Calls::argumentList($context, $node);
        if (Calls::argCount($list) !== 2) {
            return null;
        }

        $name = self::literalOf(Calls::positionalArgAt($list, 0));
        if ($name === null) {
            return null;
        }

        return self::isSingleReferenceArray($context, Calls::positionalArgAt($list, 1)) ? $name : null;
    }

    /** Whether this argument is `[ref(..)]` or `[service(..)]` — one element, and that element a reference call. */
    private static function isSingleReferenceArray(NodeAnalysisContext $context, ?Part $argument): bool
    {
        if (! $argument instanceof Part || $argument->kind->value !== 'Array') {
            return false;
        }

        $elements = Tree::findKind($context, $argument, ['ValueArrayElement']);
        if (count($elements) !== 1) {
            return false;
        }

        $value = Calls::nthExpression($context, $elements[0], 0);

        // Through the `Call` category node. Mago files every call kind under one wrapper, so an element
        // holding `service(..)` arrives as `Call` and a `FunctionCall` test on it answers no  the same
        // wrapper {@see Calls} keeps its own list for, reached from a position that list does not cover.
        if ($value instanceof Part && $value->kind->value === 'Call') {
            $value = $value->firstChild();
        }

        if (! $value instanceof Part || $value->kind->value !== 'FunctionCall') {
            return false;
        }

        $callee = Calls::nthExpression($context, $value, 0);
        $resolved = $callee instanceof Part ? $context->source->getResolvedName($callee->node)?->name : null;

        return $resolved !== null && in_array(ltrim($resolved, '\\'), self::REFERENCE_FUNCTIONS, true);
    }

    /**
     * A written string literal's value, or null for anything computed.
     *
     * Takes the argument's *value*, not the argument: {@see Calls::positionalArgAt()} already unwraps the
     * `Argument`, `PositionalArgument` and `Expression` layers, so calling {@see Calls::argumentValue()} again
     * here read one level too deep and answered null for every `->call('add', ..)` in the corpus. Measured:
     * position 0 arrives as a `Literal` whose text is `'add'`, quotes included, which is what
     * {@see CstLiteral::plainString()} takes.
     */
    private static function literalOf(?Part $value): ?string
    {
        return $value instanceof Part ? CstLiteral::plainString($value->text) : null;
    }
}
