<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\Metadata\ClassConstantMetadata;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata as ClassMetadata;
use Mago\Sdk\Analyzer\Metadata\TypeMetadata;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;

/**
 * The class constants `ConstantTypeCoverageRule` measures, counted the way its collector counts them.
 *
 * Its own class rather than three more methods on {@see TypeCoverage}, for the reason
 * {@see DeclaredParameters} is: the collector needs a trait-user index, a statement map read from the tree
 * and a parent-class guard, and the coverage class is over its complexity limit with all three inline.
 *
 * The third member collector in one package, and it agrees with neither of its siblings on the one question
 * they all have to answer: what a trait's members are worth. `ReturnTypeDeclarationCollector` counts a
 * trait's method once per class that *reaches* it, so a class redeclaring the name takes it away;
 * `PropertyTypeDeclarationCollector` counts a trait's property zero times; this one counts a trait's constant
 * once per using class **whether or not the class redeclares it**. Three collectors, three answers.
 *
 * Measured rather than read. A trait with one constant, used by one class that redeclares the same constant,
 * counts 2 to the real rule — the trait's, in that class's context, plus the class's own. The `reachedAs()`
 * test the return metric needs would have read that as 1. A trait nobody uses counts 0, which is the half
 * that stops "count each declaration once" from reaching the same total by cancelling two errors.
 *
 * The typed test is narrower than the property one: `$classConst->type instanceof Node` and nothing else.
 * There is no docblock fallback here, so `declaredType` alone answers it — `type`, which mago sets for any
 * `@var`, would be too generous the same way it was for properties.
 *
 * Every counting rule here has a control under `tests/Fixtures/aggregate/controls`, compared against the real
 * rule rather than against an expectation of it.
 */
final class DeclaredConstants
{
    /**
     * Every class constant the collector would count, and how many of them carry a written type.
     *
     * @return array{total: int, typed: int, missing: list<SourceLocation>}
     */
    public static function of(AfterAnalysisContext $context): array
    {
        $total = 0;
        $typed = 0;
        $missing = [];
        $traitUsers = TraitUsers::of($context);
        $statementSpans = self::statements($context);

        foreach (Analysed::classNames($context) as $class) {
            $metadata = $context->codebase->getClassLike($class);
            if (! $metadata instanceof ClassMetadata) {
                continue;
            }

            // A trait's body is analysed once per using class, so its constants are counted that many times;
            // every other class-like is analysed once. Unlike the return metric this is a plain count of
            // users rather than a reach test, because a class redeclaring the constant does not stop the
            // trait's own declaration being visited in its context.
            $times = $metadata->kind === ClassLikeKind::Trait
                ? count($traitUsers[strtolower($metadata->originalName)] ?? [])
                : 1;
            if ($times === 0) {
                continue;
            }

            $file = $metadata->location->file;
            if (! is_string($file)) {
                continue;
            }

            // One `ClassConst` statement, not one name: `const A = 1, B = 2;` is a single node to the
            // collector and two entries in the metadata list.
            $statements = [];

            foreach ($metadata->constants as $name) {
                $constant = $context->codebase->getClassConstant($class, $name);
                if (! $constant instanceof ClassConstantMetadata) {
                    continue;
                }

                // The list holds what the class-like *has*, not what it writes: a trait's constant, an
                // interface's and a parent's all appear on the class with their own declaring location. Only
                // a declaration written inside this class-like counts here, and the rest are counted where
                // they are written. Asked as containment rather than as file equality, because a trait and
                // the class using it may sit in one file and the file test would count that trait's constant
                // twice.
                $at = $constant->location;
                if ($at->file !== $file || ! $metadata->location->span->contains($at->span)) {
                    continue;
                }

                $statement = self::statementHolding($statementSpans[$file] ?? [], $at->span);
                $key = $statement instanceof Span ? $statement->start . ':' . $statement->end : 'at:' . $at->span->start;
                if (isset($statements[$key])) {
                    continue;
                }

                $statements[$key] = true;

                $total += $times;

                if ($constant->declaredType instanceof TypeMetadata
                    || self::guardedByParent($context, $metadata, $name)
                ) {
                    $typed += $times;

                    continue;
                }

                // Anchored on the statement rather than on the name, because the original reports
                // `$classConst->getLine()` — the line the `const` keyword is on. A declaration written over
                // several lines puts its names on different ones, so anchoring on the name reports a line the
                // real rule never reports.
                //
                // Once, however many times it is counted: a declaration has one site, and PHPStan reports one
                // error per (file, line, message) whatever the collector handed it.
                $missing[] = $statement instanceof Span ? new SourceLocation($file, $statement) : $at;
            }
        }

        return ['total' => $total, 'typed' => $typed, 'missing' => $missing];
    }

    /**
     * Where each `const` statement starts and ends, per analysed file.
     *
     * `const A = 1, B = 2;` is one `ClassConst` node to the collector and two entries in the metadata list,
     * so the two names have to collapse to one count. {@see TypeCoverage::properties()} finds the statement by
     * scanning the source back to the previous `;` or brace, which is wrong whenever a default holds one of
     * those characters — and one consumer holds exactly that: `private const string DYNAMIC_TEXT = 'Welcome
     * {name}', STATIC_TEXT = '...';` counted twice, the whole of a +1 delta on 715 declarations.
     *
     * Blanking string literals before that scan was written for the property metric and reverted, because an
     * apostrophe in a comment opens a quote that never closes and it cost 42 declarations across the two
     * consumers. The tree answers the question outright instead: `ClassLikeConstant` *is* the statement, so
     * there is nothing to infer from text.
     *
     * @return array<string, list<Span>>
     */
    private static function statements(AfterAnalysisContext $context): array
    {
        $spans = [];
        foreach ($context->analysis->files as $file) {
            $source = $file->getSourceFile();
            foreach ($source->getNodes(NodeKind::ClassLikeConstant) as $node) {
                $spans[$file->file][] = $node->span;
            }
        }

        return $spans;
    }

    /**
     * The `const` statement one constant is written in, or null when the file's syntax does not hold it.
     *
     * Null is not a formality: a file the analysis reports metadata for but no syntax — a stub, or a source
     * the tree pass skipped — has no statement to collapse onto, and the caller falls back to the constant's
     * own position. That counts each name separately, which is the answer for a file with no grouped
     * declaration and the closer of the two wrong answers for one that has.
     *
     * @param list<Span> $statements
     */
    private static function statementHolding(array $statements, Span $at): ?Span
    {
        foreach ($statements as $statement) {
            if ($statement->contains($at)) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * Whether a parent class already declares this constant, which takes it out of the missing list.
     *
     * `isGuardedByParentClassConstant()` walks `ClassReflection::getParents()`, which is parent *classes* and
     * not interfaces — so a constant an interface declares is not guarded by it, and the interface's own
     * declaration is counted on the interface instead.
     */
    private static function guardedByParent(AfterAnalysisContext $context, ClassMetadata $metadata, string $name): bool
    {
        foreach ($metadata->parentClasses as $parent) {
            if ($context->codebase->getClassConstant($parent, $name) instanceof ClassConstantMetadata) {
                return true;
            }
        }

        return false;
    }
}
