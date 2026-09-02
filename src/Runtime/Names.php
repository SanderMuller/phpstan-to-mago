<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

/**
 * Names as the file writes them, and what a written name resolves to.
 *
 * The distinction the group is built on: Mago keeps a name as written, where php-parser hands PHPStan one
 * already rewritten through the file's imports. Comparing the written text to a fully-qualified list is
 * silent on exactly the imported spelling a rule targets, which is why `resolvedName()` exists beside
 * `textOf()` rather than instead of it.
 */
final class Names
{
    /** Kinds that stand in for `instanceof PhpParser\Node\Name`. */
    private const array NAME_KINDS = [NodeKind::Identifier, NodeKind::Keyword, NodeKind::LocalIdentifier];

    /**
     * The fully-qualified name a written name means, which is what `$scope->resolveName()` answers.
     *
     * Mago resolves a written name against the file's imports and namespace and hands back the result, so an
     * `Identifier` needs no work: `Thing` in `namespace Demo` comes back as `Demo\Thing`, an imported
     * `Imported` as `Other\Imported`, and `\Root\Absolute` with its leading slash removed. A name that
     * resolves to nothing declared still resolves — `hasClass()` is the separate question.
     *
     * Two spellings are not names to Mago and come back null, so they are answered here instead:
     *
     * - `self` and `static` are `Keyword` nodes, not identifiers, and PHPStan resolves both to the enclosing
     *   class. Probed: `getResolvedName()` returns null for each.
     * - `$name` in `new $name()` is a `Variable`, and null is right — PHPStan's rules guard on
     *   `instanceof Name` first, so they never ask.
     *
     * One gap, known rather than silent: `parent` is a `Keyword` too — probed, like the other two — and a node
     * hook has no metadata to resolve it through, so it comes back null where PHPStan would answer the parent
     * class. A rule reached through `new parent()` will disagree.
     */
    public static function resolvedName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        if ($node->kind === NodeKind::Keyword) {
            $keyword = strtolower(trim($context->source->getText($node)));

            return $keyword === 'self' || $keyword === 'static'
                ? Declares::enclosingClassName($context, $node)
                : null;
        }

        $resolved = $context->source->getResolvedName($node);

        return $resolved instanceof ResolvedName && $resolved->name !== '' ? $resolved->name : null;
    }

    /** A navigated part's source text, for interpolating into a message. */
    public static function textOf(Part|string|null $subject): ?string
    {
        if ($subject === null || is_string($subject)) {
            return $subject;
        }

        return $subject->text;
    }

    /**
     * The name php-parser hands a rule for a node, after PHPStan has resolved the file's names.
     *
     * `NamingHelper::getName()` reads `->toString()` off a name, and by the time a rule sees the tree that
     * name is resolved: the class side of `Widget::class` answers `Examples\Wiring\Widget`. So a rule
     * comparing two of them compares fully-qualified names, and an alias or a leading backslash on one side
     * changes nothing — measured, the original reports
     * `set(Widget::class, \Examples\Wiring\Widget::class)` as a duplicate.
     *
     * Two exceptions, both measured rather than reasoned about: the three special class names stay as
     * written, and a subject that is not a name at all falls back to {@see self::writtenName()} — a
     * variable's own name is what php-parser gives there, and no resolution applies to it.
     */
    public static function nameAfterResolution(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $part = $subject instanceof Node ? Tree::part($context, $subject) : $subject;

        // `self`, `static` and `parent` stay as php-parser spells them. PHPStan's name resolution leaves
        // those three alone, so a rule comparing `self::class` against `Thing::class` sees `self` against
        // `Thing` and declines — measured on the pair, where resolving the keyword to the enclosing class
        // made the port report a duplicate PHPStan says nothing about. {@see self::resolvedName()} maps them
        // to the class on purpose, for the questions that are about the class rather than the spelling.
        if ($part instanceof Part && $part->kind === NodeKind::Keyword) {
            return self::textOf($part);
        }

        return self::isName($part) ? self::resolvedName($context, $part) : self::writtenName($context, $part);
    }

    /**
     * The name a node *writes*, which is php-parser's `$node->name` on a variable and `->toString()` on a
     * name or an identifier.
     *
     * `NamingHelper::getName()` in `symplify/phpstan-rules` is exactly these three cases and a null for
     * everything else, and the null matters: three rules test `is_string()` on the answer and decline when it
     * is not. So this answers null for any other node rather than falling back to its source text — a
     * navigated part always has text, and returning that would turn "not a name" into a name nobody wrote.
     *
     * Kept apart from {@see self::resolvedName()}, which answers what the *file* resolves a name to — and
     * that is what a *name* position needs, because PHPStan resolves names before a rule sees the tree. This
     * one is for the positions where php-parser hands back the spelling itself: a variable's own name is what
     * `$node->name` holds, and no resolution applies to it.
     *
     * Written `self::` rather than as a bare reference because `ResolvedName` is also an imported class here,
     * and a formatter reading the docblock capitalised the reference into it.
     */
    public static function writtenName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $variable = self::directVariableName($context, $subject);
        if ($variable !== null) {
            return $variable;
        }

        $part = $subject instanceof Node ? Tree::part($context, $subject) : $subject;
        if (! $part instanceof Part) {
            return null;
        }

        return self::isName($part) || self::selectorIsIdentifier($part) ? self::textOf($part) : null;
    }

    /** Whether a navigated part is `__DIR__`, which php-parser models as its own node class. */
    public static function isDirConstant(?Part $part): bool
    {
        return $part instanceof Part
            && $part->kind === NodeKind::MagicConstant
            && strcasecmp(trim($part->text), '__DIR__') === 0;
    }

    /**
     * A quoted string's value with its quotes removed, which is php-parser's `String_->value`.
     *
     * Null-tolerant for the same reason `constantNameText()` is: reading the value of something that is not a
     * string literal has no answer, and the rule's own `instanceof String_` guard is what makes sure it never
     * asks. Escapes are left as written — no rule in the corpus compares against a value that carries one, and
     * unescaping without a case that needs it would be inventing a behaviour.
     */
    public static function literalStringValue(NodeAnalysisContext $context, ?Part $part): ?string
    {
        if (! $part instanceof Part) {
            return null;
        }

        $literals = Tree::findKind($context, $part, ['LiteralString']);
        $text = $literals === [] ? null : trim($literals[0]->text);
        if ($text === null || strlen($text) < 2) {
            return null;
        }

        return substr($text, 1, -1);
    }

    /** Whether a navigated part is a quoted string, which is php-parser's `Scalar\String_`. */
    public static function isLiteralString(NodeAnalysisContext $context, ?Part $part): bool
    {
        return $part instanceof Part && Tree::findKind($context, $part, ['LiteralString']) !== [];
    }

    public static function isName(?Part $part): bool
    {
        return $part instanceof Part && in_array($part->kind, self::NAME_KINDS, true);
    }

    public static function nameEquals(?Part $part, string $literal): bool
    {
        // A leading `\` is dropped on both sides, because the rule's literal is written the way php-parser
        // spells a name and php-parser does not keep it: `\Livewire\invade(..)` and `Livewire\invade(..)` both
        // read back as `Livewire\invade`. Mago keeps the separator, so comparing the text as written made the
        // port silent on exactly the fully-qualified spelling the rule exists to catch.
        return $part instanceof Part && strcasecmp(ltrim($part->text, '\\'), ltrim($literal, '\\')) === 0;
    }

    public static function selectorIsIdentifier(?Part $part): bool
    {
        return $part instanceof Part && in_array($part->kind, self::NAME_KINDS, true);
    }

    /**
     * Whether a class name is one of PHP's own — `self`, `parent` or `static`.
     *
     * Those three arrive as `Keyword` where a written class name is an `Identifier`, which is the
     * distinction php-parser spells `Name::isSpecialClassName()`. Rules use it as a filter: a name that
     * resolves relative to the current class cannot be compared against a written one.
     */
    public static function isSpecialClassName(?Part $part): bool
    {
        return $part instanceof Part && $part->kind === NodeKind::Keyword;
    }

    /**
     * Whether a class name is written relative to the current namespace, as `namespace\Foo`.
     *
     * Answered from the name's own text, because that prefix is what makes it relative and Mago resolves the
     * name before a hook sees it. Compared case insensitively, as PHP treats the keyword.
     */
    public static function isRelativeName(?Part $part): bool
    {
        return $part instanceof Part && str_starts_with(strtolower($part->text), 'namespace\\');
    }

    public static function isVariable(?Part $part): bool
    {
        return $part instanceof Part && $part->kind === NodeKind::Variable;
    }

    /**
     * `$foo` gives `foo`; anything else, including `$$foo` and `${expr}`, gives null.
     *
     * Two things measured rather than assumed. Mago has no `Variable` node — it has `DirectVariable`,
     * `IndirectVariable` and `NestedVariable`, and this compares against the first, which is what "the name is
     * written" means. And a variable hook hands the analysis a `Node`, not a `Part`: the old signature took
     * only a `Part` and `VariableVariablesRule` died at analysis time with a `TypeError` the moment it was
     * registered — a failure no static check sees, because the emitted call is well-typed against the wrong
     * overload.
     */
    public static function directVariableName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $part = $subject instanceof Node ? Tree::part($context, $subject) : $subject;

        if (! $part instanceof Part) {
            return null;
        }

        // The written form, read from the text rather than from the kind. Both are needed and neither is
        // enough: `NodeKind::Variable` is a category a `Part` may carry where a hook's own node carries the
        // concrete `DirectVariable`, so a kind test alone answered null for one caller or the other. The text
        // settles it — `$$name` and `${expr}` do not match, and that is the whole question.
        return preg_match('/^\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $part->text) === 1
            ? substr($part->text, 1)
            : null;
    }

    /**
     * Whether an expression is a bare constant name — what php-parser calls a `ConstFetch`.
     *
     * Mago splits the one PHP concept across two node kinds, probed rather than assumed: `true`, `false` and
     * `null` are `Literal` nodes holding a `Keyword`, while any other bare name — `FOO`, `PHP_INT_MAX` — is a
     * `ConstantAccess`. A `Literal` holding a `LiteralInteger` or a `LiteralString` is neither, so the keyword
     * child is what has to be checked rather than the `Literal` kind alone.
     */
    public static function isConstantName(?Part $part): bool
    {
        if (! $part instanceof Part) {
            return false;
        }

        if ($part->kind === NodeKind::ConstantAccess) {
            return true;
        }

        return $part->kind === NodeKind::Literal && $part->firstChild()?->kind === NodeKind::Keyword;
    }

    /**
     * The name a bare constant name is written with, or null when the expression is not one.
     *
     * php-parser puts a `Name` on `ConstFetch->name`, so a rule reads `->name->toLowerString()` after guarding
     * on `instanceof ConstFetch`. Null-tolerant for the same reason the guard exists: reading the name of
     * something that is not a constant name has no answer, and null makes every comparison against it false.
     */
    public static function constantNameText(?Part $part): ?string
    {
        return self::isConstantName($part) ? trim((string) $part?->text) : null;
    }

    /**
     * The last segment of a written name — php-parser's `Name::getLast()`.
     *
     * A leading backslash is part of how the name was written, not part of the segment, so `\Acme\request`
     * and `request` both answer `request`.
     */
    public static function lastNameSegment(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * The name the codebase knows a function under, as declared, or null when it knows none.
     *
     * What `$reflectionProvider->getFunction($name, $scope)->getName()` gives a rule: the *resolved* name, so
     * a rule comparing it against `request` sees through a namespaced call that falls back to the global one.
     */
    public static function functionName(NodeAnalysisContext $context, ?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        foreach ([$name, self::lastNameSegment($name)] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $function = $context->codebase->getFunction($candidate);
            if ($function instanceof FunctionLikeMetadata) {
                return $function->originalName;
            }
        }

        return null;
    }

    /**
     * Whether a written class name resolves to one of a set of class names.
     *
     * The counterpart of {@see bytesIsOneOf} for a list the rule wrote as `::class` fetches. PHPStan compares
     * such a list against `Name::toString()`, and php-parser has already rewritten that name through the
     * file's imports — so `new Name(..)` under `use PhpParser\Node\Name;` reads back fully qualified. Mago
     * keeps the name as written, which is why the resolved name is asked for instead of the text.
     *
     * Leading `\` and case are handled as {@see nameEquals} handles them, for the reason recorded there:
     * php-parser does not keep the separator, and a comparison that does is silent on the fully-qualified
     * spelling.
     *
     * @param list<string> $names
     */
    public static function resolvedNameIsOneOf(NodeAnalysisContext $context, Part|Node|null $subject, array $names): bool
    {
        $resolved = self::resolvedName($context, $subject);
        if ($resolved === null) {
            return false;
        }

        foreach ($names as $name) {
            if (strcasecmp(ltrim($resolved, '\\'), ltrim($name, '\\')) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $names */
    public static function selectorIsOneOf(?Part $part, array $names): bool
    {
        return $part instanceof Part && in_array($part->text, $names, true);
    }

    /**
     * The namespace the analysed file declares, or null when it declares none.
     *
     * `$scope->getNamespace()` has no direct equivalent in the SDK. The file's own text does: `SourceFile`
     * carries `contents` under the `SourceText` requirement, and a PHP file declares at most one namespace
     * before any declaration. Read from the text rather than from the node tree because the answer is a
     * property of the file, not of the target — a rule asks it from an expression deep inside a method.
     *
     * The resolved-name route considered instead — taking an unqualified call's resolved name and dropping
     * its last segment — fails for an already-qualified name, which is why it is not used.
     */
    public static function enclosingNamespace(NodeAnalysisContext $context): ?string
    {
        if (preg_match('/^\\s*namespace\\s+([^;{\\s]+)\\s*[;{]/m', $context->source->contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], '\\');
    }
}
