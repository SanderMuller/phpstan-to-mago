<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
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
     * @return list<string> sorted, unique
     */
    public static function of(string $file, array $siblings): array
    {
        $found = self::inFile($file, $siblings, [basename($file, '.php') => true]);
        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * @param array<string, string> $siblings
     * @param array<string, true>   $seen     names already read, so a cycle cannot recurse
     * @return list<string>
     */
    private static function inFile(string $file, array $siblings, array $seen): array
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
            }
        }

        foreach (self::relatives($statements) as $relative) {
            if (isset($seen[$relative]) || ! isset($siblings[$relative])) {
                continue;
            }

            $seen[$relative] = true;
            $identifiers = [...$identifiers, ...self::inFile($siblings[$relative], $siblings, $seen)];
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
