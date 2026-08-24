<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * One member of a declaration: a method, a property, a class constant, a parameter or a type hint.
 *
 * Split from {@see Declares} by level rather than by subject. That class answers about the class-like —
 * what it extends, implements, uses and carries — and this one about the things written inside it. Both
 * read the tree; {@see Reflect} is the metadata counterpart of the pair.
 */
final class Members
{
    /**
     * What a body wrapper holds when the declaration has none.
     *
     * `MethodAbstractBody` for a method the class declares without one — abstract, or on an interface. Named
     * rather than derived from the wrapper's text, because `";"` is also what a body wrapper would hold for a
     * property hook and the kinds are the thing being asked about.
     */
    private const array ABSENT_BODY_KINDS = ['MethodAbstractBody', 'PropertyHookAbstractBody'];

    /** Body kinds: what a declaration or a statement keeps its statements in. */
    private const array BODY_KINDS = ['ForeachBody', 'Block', 'MethodBody', 'ForBody', 'WhileBody'];

    /** Names php-parser treats as magic, copied from `ClassMethod::$magicNames` rather than recalled. */
    private const array MAGIC_METHOD_NAMES = [
        '__construct', '__destruct', '__call', '__callstatic', '__get', '__set', '__isset', '__unset',
        '__sleep', '__wakeup', '__tostring', '__set_state', '__clone', '__invoke', '__debuginfo',
        '__serialize', '__unserialize',
    ];

    /**
     * A function-like's name as `tomasvotruba/cognitive-complexity` spells it in its message.
     *
     * Four answers, one per kind the `FunctionLike` hook registers, reproducing
     * `FunctionLikeCognitiveComplexityRule::resolveFunctionName()`: a plain function is `name()`, a method is
     * `Class::name()` and falls back to `name()` where there is no enclosing class, and the two anonymous
     * kinds are the fixed strings the original uses. Those last two are unreachable through that rule, which
     * declines anything but a method or a function — reproduced anyway, because the port should not depend on
     * a caller's narrowing for its own correctness.
     *
     * Purely syntactic apart from the enclosing class name, which is why this is a helper rather than a
     * translated expression: the original branches four ways and builds a string in one of them.
     */
    public static function functionLikeName(NodeAnalysisContext $context, Part|Node|null $subject): string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return '';
        }

        if ($node->kind === NodeKind::Closure) {
            return 'closure';
        }

        if ($node->kind === NodeKind::ArrowFunction) {
            return 'arrow function';
        }

        $name = self::declaredName($context, $node) . '()';
        if ($node->kind !== NodeKind::Method) {
            return $name;
        }

        $class = Declares::enclosingClassName($context, $node);

        return $class === null ? $name : $class . '::' . $name;
    }

    /** The identifier a declaration was written with, or an empty string when it has none. */
    private static function declaredName(NodeAnalysisContext $context, Node $node): string
    {
        [$file, $located] = Tree::locate($context, $node);
        foreach ($file->getChildren($located) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                return trim($file->getText($child));
            }
        }

        return '';
    }

    /**
     * Whether the node is a method declaration, for a rule narrowing a function-like hook.
     *
     * A rule naming `FunctionLike` is handed every function-like there is and branches on the concrete one:
     * `instanceof ClassMethod` is this, `instanceof Function_` is the next one, and a closure or arrow
     * function matches neither — which is the rule declining, not the port going quiet.
     */
    public static function isMethodDeclaration(Part|Node|null $subject): bool
    {
        $node = Tree::node($subject);

        return $node instanceof Node && $node->kind === NodeKind::Method;
    }

    /** Whether the node is a plain function declaration. See {@see isMethodDeclaration()}. */
    public static function isFunctionDeclaration(Part|Node|null $subject): bool
    {
        $node = Tree::node($subject);

        return $node instanceof Node && $node->kind === NodeKind::Function;
    }

    /**
     * A property declaration's type hint, or null when it has none.
     *
     * The hook is handed a `Property`, which wraps a `PlainProperty` (or a hooked or promoted variant),
     * so the hint can be one level down. Found by descending rather than assumed, since an untyped
     * property has no `Hint` child at all and must answer null rather than something empty.
     */
    public static function propertyHint(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ([$node, ...$context->source->getChildren($node)] as $candidate) {
            foreach ($context->source->getChildren($candidate) as $child) {
                if ($child->kind === NodeKind::Hint) {
                    return Tree::part($context, $child);
                }
            }
        }

        return null;
    }

    /**
     * The parameters a function-like declares, in order.
     *
     * `FunctionLikeParameterList` holds one `FunctionLikeParameter` each, and a closure with no parameters still
     * has the list — so an empty list and a missing one are the same answer, which is what `getParams()` gives.
     *
     * @return list<Part>
     */
    public static function declaredParams(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $out = [];
        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind !== NodeKind::FunctionLikeParameterList) {
                continue;
            }

            foreach ($context->source->getChildren($child) as $parameter) {
                if ($parameter->kind === NodeKind::FunctionLikeParameter) {
                    $out[] = Tree::part($context, $parameter);
                }
            }
        }

        return $out;
    }

    /** The nth declared parameter, or null when the declaration has no such position. */
    public static function declaredParamAt(NodeAnalysisContext $context, Part|Node|null $subject, int $index): ?Part
    {
        return self::declaredParams($context, $subject)[$index] ?? null;
    }

    /** The written type of a declared parameter, or null when it has none. */
    public static function declaredParamHint(?Part $parameter): ?Part
    {
        if (! $parameter instanceof Part) {
            return null;
        }

        foreach ($parameter->children() as $child) {
            if ($child->kind === NodeKind::Hint) {
                return $child;
            }
        }

        return null;
    }

    /**
     * The statements a node holds, which is php-parser's `$node->stmts`.
     *
     * Load-bearing that this is the *body* and not the node: `findInstanceOf($node->stmts, Foreach_::class)`
     * inside a foreach must not find the foreach it started from, or every count is one too high.
     *
     * A declaration with no body answers null, because php-parser's `$node->stmts` is null there and three
     * rules in the corpus open by testing exactly that. Mago spells the absence one level down, which was
     * measured rather than assumed: an abstract method and an interface method both carry a `MethodBody`
     * child whose text is `";"` and whose only child is `MethodAbstractBody`. Reading the wrapper alone
     * answered "has a body" for both, and the fires-gate caught it on a pair written for the question.
     */
    public static function bodyOf(NodeAnalysisContext $context, Part|Node|null $subject): ?Part
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if (! in_array($child->kind->value, self::BODY_KINDS, true)) {
                continue;
            }

            foreach ($context->source->getChildren($child) as $inner) {
                if (in_array($inner->kind->value, self::ABSENT_BODY_KINDS, true)) {
                    return null;
                }
            }

            return Tree::part($context, $child);
        }

        return null;
    }

    /**
     * The written name of a class-like or method declaration, short and unqualified.
     *
     * php-parser's `$node->name->toString()` on a declaration gives the name as written, not the namespaced one —
     * `Something` rather than `App\Something` — which is what a rule testing a prefix or suffix compares.
     */
    public static function declarationName(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        foreach ($context->source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier || $child->kind === NodeKind::Identifier) {
                return trim($context->source->getText($child));
            }
        }

        return null;
    }

    /** A method declaration's own name. */
    public static function methodName(?Part $method): ?string
    {
        if (! $method instanceof Part) {
            return null;
        }

        foreach ($method->children() as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return trim($child->text);
            }
        }

        return null;
    }

    /**
     * Whether a method is magic, which php-parser answers from a fixed list of seventeen names.
     *
     * Not "starts with `__`": that would catch `__myHelper`, which php-parser does not, and the direction of
     * that error is a port reporting where the rule stays silent.
     */
    public static function methodIsMagic(?Part $method): bool
    {
        $name = self::methodName($method);

        return $name !== null && in_array(strtolower($name), self::MAGIC_METHOD_NAMES, true);
    }

    /**
     * The visibility of a method as the codebase knows it, which is what a rule asks of a reflection.
     *
     * Read from `FunctionLikeMetadata->visibility` rather than from `flags`: `MetadataFlags` carries `STATIC`,
     * `ABSTRACT` and `FINAL` and no visibility at all, so a flags check would answer every method the same.
     * Null when the method is not found, so each predicate below decides for itself what absence means.
     */
    private static function reflectedMethodVisibility(NodeAnalysisContext $context, ?string $class, ?string $method): ?Visibility
    {
        if ($class === null || $method === null) {
            return null;
        }

        $declaring = $context->codebase->getDeclaringMethod($class, $method);

        return $declaring instanceof FunctionLikeMetadata ? $declaring->visibility : null;
    }

    /** Whether the codebase's method is public. A method that is not found is not public. */
    public static function reflectedMethodIsPublic(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        return self::reflectedMethodVisibility($context, $class, $method) === Visibility::Public;
    }

    /** Whether the codebase's method is private. */
    public static function reflectedMethodIsPrivate(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        return self::reflectedMethodVisibility($context, $class, $method) === Visibility::Private;
    }

    /**
     * The items of a property declaration: `protected $a = 1, $b = 2;` has two.
     *
     * The tree, read from a probe rather than assumed: `Property` wraps a `PlainProperty`, which holds one
     * `PropertyItem` per declared name. php-parser calls the same list `$node->props`.
     *
     * @return list<Part>
     */
    public static function propertyItems(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        $items = [];
        foreach ($context->source->getChildren($node) as $child) {
            // A property declaration may be plain or hooked; both hold the items.
            if (! in_array($child->kind->value, ['PlainProperty', 'HookedProperty'], true)) {
                continue;
            }

            foreach ($context->source->getChildren($child) as $item) {
                if ($item->kind === NodeKind::PropertyItem) {
                    $items[] = Tree::part($context, $item);
                }
            }
        }

        return $items;
    }

    /**
     * A property item's default value, or null when it declares none.
     *
     * The tree, from a probe rather than assumed: an initialised item holds a `PropertyConcreteItem` whose
     * children are the `DirectVariable` and an `Expression` wrapping the value; an uninitialised one holds a
     * `PropertyAbstractItem` with only the variable. The `Expression` wrapper is unwrapped, the same way
     * {@see nthExpression} unwraps it, so what comes back is the value node a rule asks `instanceof` of.
     */
    public static function propertyItemDefault(?Part $item): ?Part
    {
        if (! $item instanceof Part) {
            return null;
        }

        foreach ([$item, ...$item->children()] as $candidate) {
            foreach ($candidate->children() as $child) {
                if ($child->kind !== NodeKind::Expression) {
                    continue;
                }

                $inner = $child->children()[0] ?? null;

                return $inner ?? $child;
            }
        }

        return null;
    }

    /**
     * A property item's name, without the `$`.
     *
     * `$with = ['author']` gives `with`. The name is a `DirectVariable` under a `PropertyConcreteItem` for an
     * initialised property, or directly under the item for an uninitialised one, so both are searched.
     */
    public static function propertyItemName(?Part $item): ?string
    {
        if (! $item instanceof Part) {
            return null;
        }

        foreach ([$item, ...$item->children()] as $candidate) {
            foreach ($candidate->children() as $child) {
                if ($child->kind === NodeKind::DirectVariable) {
                    return ltrim($child->text, '$');
                }
            }
        }

        return null;
    }
}
