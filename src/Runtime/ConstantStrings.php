<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassConstantMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * The literal behind an expression PHPStan would call a `ConstantStringType`.
 *
 * `$scope->getType($expr)` on a class-constant fetch answers the constant's *value*, and a widening `@var`
 * docblock does not take that away — PHPStan reads the initialiser. Mago's inferred type honours the
 * docblock instead: a constant written
 *
 *     /** @var string *\/
 *     private const IS_BREAK_IN_SWITCH = 'is_break_in_switch';
 *
 * reads as plain `string` there, so `constantStringOf()` answers null and every rule asking "is this a
 * constant string, and which one" silently declines.
 *
 * Controlled rather than reasoned about: two constants in one class, one with the docblock and one without,
 * and only the docblocked one goes null. And it is not a corner — `rector-src` docblocks every constant in
 * `AttributeKey`, which is where its node-attribute keys live, so `AvoidFeatureSetAttributeInRectorRule`
 * agreed with PHPStan on **0 of 9** real findings before this. The fires-gate pair could not see it: five
 * variations of the fixture — a same-class constant, an untyped one, a constant on another class, a closure,
 * a closure passed as an argument — all resolved, because none of them carried the docblock.
 *
 * So the initialiser is read where the type cannot answer. The declaring file is found through the
 * constant's own metadata location and read from disk: a node hook sees only its own file's syntax
 * (`internal/probe-declaring-file-body.php` measured that), but a plugin is PHP and the path is real. Only a
 * plain quoted string counts; anything else — a concatenation, another constant, an array — answers null and
 * the caller behaves as it did before.
 */
final class ConstantStrings
{
    /** @var array<string, string|null> */
    private static array $literals = [];

    /**
     * The constant string an expression stands for, from the inferred type or from the declaration itself.
     *
     * The type is asked first, because it is what the rule asks and it answers every shape this does not —
     * a literal written at the call site, a variable narrowed to one value, a parameter default.
     */
    public static function at(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $inferred = Types::constantStringOf(Support::expressionType($context, $subject));
        if ($inferred !== null) {
            return $inferred;
        }

        return self::declaredLiteral($context, $subject);
    }

    /** The initialiser of the class constant this expression fetches, when it is a plain quoted string. */
    private static function declaredLiteral(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = self::fetch($context, Tree::node($subject));
        if (! $node instanceof Node) {
            return null;
        }

        $name = self::constantName($context, $node);
        $owner = self::ownerClass($context, $node);
        if ($name === null || $owner === null) {
            return null;
        }

        $constant = $context->codebase->getClassConstant($owner, $name);
        if (! $constant instanceof ClassConstantMetadata) {
            return null;
        }

        $file = $constant->location->file;
        if ($file === null) {
            return null;
        }

        // Keyed by the declaration's own site, so one file holding many constants is read once per constant
        // rather than once per use. A rule asking about the same key in a hundred files reads nothing again.
        $key = $file . ':' . $constant->location->span->start;

        return self::$literals[$key] ??= self::literalAt($file, $constant->location->span->start);
    }

    /**
     * The `ClassConstantAccess` node behind an expression, or null when the expression is not one.
     *
     * An argument's value arrives as the category node `Access`, whose only child is the specific kind —
     * probed, after a kind test on `ClassConstantAccess` answered "not a constant fetch" for every fetch
     * there was. Both spellings are accepted, so a caller that already holds the specific node is unaffected.
     */
    private static function fetch(NodeAnalysisContext $context, ?Node $node): ?Node
    {
        if (! $node instanceof Node) {
            return null;
        }

        if ($node->kind === NodeKind::ClassConstantAccess) {
            return $node;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::ClassConstantAccess) {
                return $child;
            }
        }

        return null;
    }

    /** The class a `Foo::BAR` fetch names, with `self`, `static` and `parent` resolved to the enclosing one. */
    private static function ownerClass(NodeAnalysisContext $context, Node $node): ?string
    {
        $class = Calls::classPart($context, $node);
        if (Names::isSpecialClassName($class)) {
            return Declares::enclosingClassName($context, $node);
        }

        return Names::resolvedName($context, $class);
    }

    /** The constant's own name, from the selector beside the class. */
    private static function constantName(NodeAnalysisContext $context, Node $node): ?string
    {
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind !== NodeKind::ClassLikeConstantSelector && $child->kind !== NodeKind::ClassLikeMemberSelector) {
                continue;
            }

            $inner = $context->source->getChildren($child)[0] ?? $child;

            return trim($context->source->getText($inner));
        }

        return null;
    }

    /**
     * The first quoted string in the declaration that starts at this offset.
     *
     * Tokenised rather than matched, because a declaration's own text can hold anything a comment can: an
     * apostrophe in a trailing comment reads as an opening quote to a scan, which is the mistake that cost the
     * property metric 42 declarations when it was made there.
     */
    private static function literalAt(string $file, int $offset): ?string
    {
        if (! is_file($file)) {
            return null;
        }

        $source = (string) file_get_contents($file);
        $declaration = substr($source, $offset);
        $end = strpos($declaration, ';');
        if ($end === false) {
            return null;
        }

        foreach (token_get_all('<?php ' . substr($declaration, 0, $end) . ';') as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = $token[1];
            $quote = $value[0] ?? '';

            // Only a plain literal. An escape or an interpolation is a value this cannot read back without
            // evaluating it, and answering the raw text there would be worse than answering nothing.
            if (($quote !== "'" && $quote !== '"') || str_contains($value, '\\')) {
                return null;
            }

            return substr($value, 1, -1);
        }

        return null;
    }
}
