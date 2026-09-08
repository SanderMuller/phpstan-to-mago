<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

/**
 * What a declaration writes about where it comes from: `extends`, and the parent chain that follows.
 *
 * Written, not resolved. {@see Reflect} answers the codebase's version of the same question, which
 * includes what a trait or an interface brought in.
 */
final class Inheritance
{
    /** The enclosing declaration's extends clause, joined as PHPStan prints it. */
    public static function extendsText(NodeAnalysisContext $context, Part|Node|null $subject): string
    {
        return implode(', ', self::extendsNames($context, $subject));
    }

    /** Whether the enclosing class-like declaration has an extends clause at all. */
    public static function hasExtends(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        return self::extendsNames($context, $subject) !== [];
    }

    public static function extendsIs(NodeAnalysisContext $context, Part|Node|null $subject, string $name): bool
    {
        foreach (self::extendsNames($context, $subject) as $extended) {
            if (strcasecmp($extended, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the extends clause names one of these, which is `in_array($class->extends->toString(), [..])`.
     *
     * Case folded and per name, exactly as {@see extendsIs()} is and for the same reason: the clause's names
     * arrive resolved, and the rule compares against canonical spellings.
     *
     * @param list<string> $names
     */
    public static function extendsIsOneOf(NodeAnalysisContext $context, Part|Node|null $subject, array $names): bool
    {
        foreach ($names as $name) {
            if (self::extendsIs($context, $subject, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The names in the enclosing declaration's extends clause.
     *
     * Written from the probe rather than from the shape one would expect: the clause is its own node
     * carrying the names, so this looks for it among the declaration's children instead of walking
     * every descendant, which would also pick up names from the body.
     *
     * @return list<string>
     */
    private static function extendsNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        // The same ancestor problem as `enclosingClassName()`, and the same fix: an `extends` question
        // asked from an expression target read an empty ancestor list and answered "no parent".
        [$file, $located] = Tree::locate($context, $node);

        $declaration = null;
        foreach ([$located, ...$file->getAncestors($located)] as $candidate) {
            if (in_array($candidate->kind->value, Tree::CLASS_LIKE_KINDS, true)) {
                $declaration = $candidate;
                break;
            }
        }

        if ($declaration === null) {
            return [];
        }

        $names = [];
        foreach ($file->getChildren($declaration) as $child) {
            if ($child->kind !== NodeKind::Extends) {
                continue;
            }

            foreach ($file->getChildren($child) as $part) {
                $resolved = $file->getResolvedName($part);
                $text = $resolved instanceof ResolvedName ? $resolved->name : trim($file->getText($part));
                if ($text !== '' && $text !== 'extends') {
                    $names[] = $text;
                }
            }
        }

        return $names;
    }

    /**
     * The suffix the enclosing class owes its nearest listed ancestor, or null when it owes none.
     *
     * The walk is generic and the table is derived at transpile time: for each `ancestor => suffix` pair in
     * written order, the first pair whose ancestor the class descends from decides and stops. That pair is
     * satisfied when the class name already ends with the suffix, and reported otherwise. A class matching no
     * pair owes nothing. `ClassNameRespectsParentSuffixRule` is the shape.
     *
     * Order is the caller's and it is load-bearing: the rule merges configured ancestors ahead of its own
     * defaults, so whichever matches first chooses the message. A PHP array preserves insertion order, which
     * is why the table arrives as one rather than as two lists.
     *
     * `namedClassIsSubclassOf()` is exclusive where PHPStan's `ClassReflection::is()` is inclusive, and the
     * difference cannot be reached here: a class that *is* a listed ancestor already ends with that ancestor's
     * own suffix, so both answer silence. It also folds in traits where `is()` does not, which is exact for a
     * table of classes and interfaces and would be wider than the rule for a configured trait ancestor.
     *
     * @param array<string, string> $table ancestor class or interface name => the suffix it requires
     */
    public static function missingAncestorSuffix(
        NodeAnalysisContext $context,
        Part|Node|null $node,
        array $table,
    ): ?string {
        $class = Declares::enclosingClassName($context, $node);
        if ($class === null || $class === '') {
            return null;
        }

        foreach ($table as $ancestor => $suffix) {
            if (! Reflect::namedClassIsSubclassOf($context, $class, $ancestor)) {
                continue;
            }

            return str_ends_with($class, $suffix) ? null : $suffix;
        }

        return null;
    }

    /**
     * Every interface a named class implements, transitively  `ClassReflection::getInterfaces()`.
     *
     * `parentInterfaces`, not `directParentInterfaces`: PHPStan\'s `getInterfaces()` is the whole set, and a
     * rule walking it to compare method names wants an interface an ancestor brought in as readily as one the
     * class writes. {@see Declares} carries the other choice and the case that separates them.
     *
     * Names arrive lowercased from metadata, which is fine here  every consumer looks a method up by them
     * rather than printing them.
     *
     * @return list<string>
     */
    public static function interfaceNames(NodeAnalysisContext $context, ?string $class): array
    {
        if ($class === null) {
            return [];
        }

        $metadata = $context->codebase->getClassLike($class);

        return $metadata instanceof ClassLikeMetadata ? array_values($metadata->parentInterfaces) : [];
    }

    /**
     * The classes the enclosing declaration extends, nearest first, as written.
     *
     * `ClassLikeMetadata->parentClasses` rather than `Codebase::getClassAncestors()`: that one folds in
     * interfaces and traits, and a rule walking parents to find an overridden method means parents. Names
     * arrive lowercased from metadata, which is fine for looking a class up again and wrong for printing.
     *
     * @return list<string>
     */
    public static function parentClassNames(NodeAnalysisContext $context, Part|Node|null $node): array
    {
        $className = Declares::enclosingClassName($context, $node);
        if ($className === null) {
            return [];
        }

        $metadata = $context->codebase->getClassLike($className);

        return $metadata instanceof ClassLikeMetadata ? array_values($metadata->parentClasses) : [];
    }
}
