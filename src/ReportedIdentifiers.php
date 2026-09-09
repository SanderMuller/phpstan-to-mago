<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The identifiers a rule reports, read from its source rather than from an emission.
 *
 * A refused rule emits nothing, so its identifiers are not in any manifest — and they are the only thing
 * that says whether the check it performs is already carried by a rule that *does* emit. One package in
 * this corpus ships standalone rules and merged ones holding the same checks, registers only the merged
 * ones, and the standalone ones then refuse on their unwired constructor parameter. Six refusals in the
 * census are that shape, and each reads as a gap.
 *
 * **Parsed, not grepped, and the difference is load-bearing.** `use SomeTrait;` inside a class body and
 * `use Some\Namespaced\Class;` at the top of the file are the same three characters to a regex. A first
 * version of this read `^\s*use ([A-Z]\w+);` and matched `use Override;` — harmless there because
 * `Override` reports nothing, and not harmless in general. `TraitUse` is a statement inside the class,
 * which the parser tells apart from an import for free.
 *
 * One hop through traits and one through a parent, no further. Both are what the corpus writes: the shared
 * check lives in a trait and the debug rules share a base class. A transitive closure would be more general
 * and is not measured, so it is not here.
 */
final readonly class ReportedIdentifiers
{
    /**
     * Every identifier string the rule at `$file` reports, including through its traits and parent.
     *
     * @param array<string, string> $siblings rule name to file, for resolving a trait or parent in the same
     *                                        package
     * @param bool $throughConstants whether to follow a `Foo::BAR` identifier to the constant's own value
     * @return list<string> sorted, unique
     */
    public static function of(string $file, array $siblings, bool $throughConstants = false): array
    {
        $found = self::inFile($file, $siblings, [basename($file, '.php') => true], $throughConstants);
        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * @param array<string, string> $siblings
     * @param array<string, true>   $seen     names already read, so a cycle cannot recurse
     * @return list<string>
     */
    private static function inFile(string $file, array $siblings, array $seen, bool $throughConstants): array
    {
        if (! is_file($file)) {
            return [];
        }

        $statements = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];

        $identifiers = [];
        foreach ((new NodeFinder())->findInstanceOf($statements, MethodCall::class) as $call) {
            $identifier = self::identifierArgument($call);
            if ($identifier !== null) {
                $identifiers[] = $identifier;

                continue;
            }

            if ($throughConstants) {
                $identifiers = [...$identifiers, ...self::throughConstant($call, $siblings)];
            }
        }

        foreach (self::relatives($statements) as $relative) {
            if (isset($seen[$relative]) || ! isset($siblings[$relative])) {
                continue;
            }

            $seen[$relative] = true;
            $identifiers = [...$identifiers, ...self::inFile($siblings[$relative], $siblings, $seen, $throughConstants)];
        }

        return $identifiers;
    }

    /**
     * The literal argument of `->identifier('..')`, or nothing.
     *
     * A `ClassConstFetch` argument — `RuleIdentifier::NO_REFERENCE` — is deliberately not resolved: the
     * constant's value is what matters and reading it means following another class, which would make this
     * a resolver rather than a reader. A rule whose identifier is a constant simply contributes none here,
     * and the effect is under-reporting, which leaves a refusal reading as a gap rather than the reverse.
     */
    private static function identifierArgument(MethodCall $call): ?string
    {
        if (! $call->name instanceof Identifier || $call->name->toString() !== 'identifier') {
            return null;
        }

        $argument = $call->args[0] ?? null;
        if (! $argument instanceof Arg) {
            return null;
        }

        return $argument->value instanceof String_ ? $argument->value->value : null;
    }

    /**
     * An identifier written as `SomeEnumOrClass::NAME`, read from the constant's own declaration.
     *
     * Off by default and asked for explicitly, because the two readers of this class want opposite
     * directions. The subsumption marker under-reports on purpose: an unmarked refusal reads as a gap, which
     * is what a refusal reads as anyway, where a wrongly marked one claims a check is covered. The yield
     * instrument wants the fullest set it can get, because there a missing identifier is a rule that
     * silently leaves the table and its zero cannot be told from a rule nobody's code triggers.
     *
     * `symplify/phpstan-rules` spells every identifier this way -- `RuleIdentifier::SEE_ANNOTATION_TO_TEST`
     * -- which is thirty of the forty-three refused rules having no readable identifier without this.
     *
     * @param array<string, string> $siblings
     * @return list<string>
     */
    private static function throughConstant(MethodCall $call, array $siblings): array
    {
        if (! $call->name instanceof Identifier || $call->name->toString() !== 'identifier') {
            return [];
        }

        $argument = $call->args[0] ?? null;
        if (! $argument instanceof Arg
            || ! $argument->value instanceof ClassConstFetch
            || ! $argument->value->class instanceof Name
            || ! $argument->value->name instanceof Identifier
        ) {
            return [];
        }

        $holder = $argument->value->class->getLast();
        if (! isset($siblings[$holder])) {
            return [];
        }

        return self::constantValue($siblings[$holder], $argument->value->name->toString());
    }

    /**
     * One constant's string value, from the file declaring it.
     *
     * Parsed rather than reflected: reflecting means loading the class, and this runs over whichever package
     * versions are installed rather than over code this repository controls.
     *
     * @return list<string>
     */
    private static function constantValue(string $file, string $constant): array
    {
        if (! is_file($file)) {
            return [];
        }

        $statements = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];

        foreach ((new NodeFinder())->findInstanceOf($statements, ClassConst::class) as $declaration) {
            foreach ($declaration->consts as $const) {
                if ($const->name->toString() === $constant && $const->value instanceof String_) {
                    return [$const->value->value];
                }
            }
        }

        // An enum spells its cases with a backing value rather than as constants.
        foreach ((new NodeFinder())->findInstanceOf($statements, EnumCase::class) as $case) {
            if ($case->name->toString() === $constant && $case->expr instanceof String_) {
                return [$case->expr->value];
            }
        }

        return [];
    }

    /**
     * The traits a class uses and the class it extends, by short name.
     *
     * @param array<Node> $statements
     * @return list<string>
     */
    private static function relatives(array $statements): array
    {
        $names = [];
        foreach ((new NodeFinder())->findInstanceOf($statements, ClassLike::class) as $class) {
            foreach ((new NodeFinder())->findInstanceOf([$class], TraitUse::class) as $use) {
                foreach ($use->traits as $trait) {
                    $names[] = $trait->getLast();
                }
            }

            if ($class instanceof Class_ && $class->extends instanceof Name) {
                $names[] = $class->extends->getLast();
            }
        }

        return $names;
    }
}
