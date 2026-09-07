<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\Node;

/**
 * The type an `instanceof` or an `is_a()` names, which is `NoInstanceOfStaticReflectionRule`'s own resolver.
 *
 * `resolveExprStaticType()` branches on which of the two node kinds the hook fired for and reads a different
 * field of each — the shape `internal/handoff-multi-kind-hook-is-not-a-redesign.md` calls family C and says
 * not to build an inliner for. Porting the helper whole moves that branching into runtime PHP and leaves the
 * rule body as a union guard, a null test and one mapped question, so no inliner mechanism is needed for it.
 *
 * Mago has **no `instanceof` node kind**. Measured in `internal/probe-scoped-return-and-yield.php`'s sibling
 * probe: `$a instanceof Foo` is a `Binary` whose `BinaryOperator` child holds the text `instanceof`, with the
 * subject and the class as its first and second `Expression` children. `1 + 2` is the same kind, which is why
 * the operator has to be tested rather than the kind alone.
 *
 * The class side keeps the spelling the author wrote, and its inner kind is the discriminator:
 *
 * | written                  | inner kind   | text        | PHPStan                        |
 * |:--|:--|:--|:--|
 * | `instanceof Foo`         | `Identifier` | `Foo`       | `ConstantStringType('Foo')`     |
 * | `instanceof \App\Foo`    | `Identifier` | `\App\Foo`  | `ConstantStringType('App\Foo')` |
 * | `instanceof self`        | `Keyword`    | `self`      | skipped by the rule             |
 * | `instanceof static`      | `Keyword`    | `static`    | `ConstantStringType('static')`  |
 * | `instanceof $cls`        | `Variable`   | `$cls`      | `$scope->getType($cls)`         |
 *
 * Two things in that table would have been guessed wrong. **`self` and `static` are `Keyword`, not
 * `Identifier`** — and `Keyword` is one of {@see Names::isName()}'s kinds, which is what makes the name branch
 * cover them the way php-parser's `instanceof Name` does. And **only `self` is skipped**: the rule compares
 * against `'self'` alone, so `instanceof static` resolves to a constant string naming no allowed class and is
 * reported. Folding the two keywords together would have gone quiet on a case the original reports.
 *
 * @internal to the runtime. An emitted plugin calls this directly, the way it calls
 *   {@see RectorAutoloadedTypes}.
 */
final class StaticReflectionTypes
{
    /**
     * `resolveExprStaticType()` — the type to test, or null where the rule reads nothing.
     *
     * Null for every node the union guard let through that this does not read: a call that is not `is_a()`,
     * a binary operation that is not `instanceof`, and `instanceof self`.
     */
    public static function subjectType(NodeAnalysisContext $context, Part|Node|null $subject): ?Type
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        // Asked again here, and the rule's own union guard already asked it. Mutation-checked: breaking either
        // test alone leaves the fires gate green, and only breaking both reports on `$a + $b` — so neither is
        // individually load-bearing for this consumer and the pair is. Kept rather than deleted because this
        // is a public runtime helper and a `Binary` is every operator: without the test a caller that has not
        // already narrowed would resolve `+` as an `instanceof` and read its right operand as a class.
        if (Operators::binaryOperatorIs($context, $node, 'instanceof')) {
            return self::instanceofType($context, $node);
        }

        return self::isACallType($context, $node);
    }

    /**
     * `resolveInstanceOfType()` — the class side, as a literal type where it is written and inferred where not.
     *
     * `Type::literalString()` rather than a fabricated atomic: PHPStan's `new ConstantStringType($name)` lands
     * in {@see RectorAutoloadedTypes::isAllowed()}'s `ScalarTypeKind::String` branch, and that factory produces
     * exactly the shape that branch reads — the `'TS\Alpha'` row of the table in that class. So the written
     * name reaches the allow-list by the same route a literal string in the source would.
     *
     * The leading separator is dropped because php-parser's `Name::toString()` does not keep one, and the name
     * this hands over is compared against a prefix anchored at the start.
     */
    private static function instanceofType(NodeAnalysisContext $context, Node $node): ?Type
    {
        $class = Calls::nthExpression($context, $node, 1);
        if (! $class instanceof Part) {
            return null;
        }

        if (! Names::isName($class)) {
            return Support::expressionType($context, $class);
        }

        // The *resolved* name, not the written one. PHPStan runs its `NameResolver` before a rule sees the
        // tree, so `$instanceof->class->toString()` on an imported `Node` answers `PhpParser\\Node`; mago keeps
        // the spelling the author used and answers the resolution separately. Reading the text reported
        // `instanceof Node` as a disallowed class, because `Node` matches no allowed prefix and
        // `PhpParser\\Node` matches the first one. Measured on the example pair rather than reasoned about, and
        // it is the same species as every other value in this runtime that is right and answers a different
        // question than the rule asks.
        //
        // Resolution answers nothing for `self` and `static`, which are keywords rather than names, and the
        // text is what php-parser's `toString()` gives for both " + D + " so the fallback is exact rather than
        // defensive.
        $resolved = $context->source->getResolvedName($class->node)?->name;
        $written = ltrim(trim($resolved ?? $class->text), '\\');

        return $written === 'self' ? null : Type::literalString($written);
    }

    /**
     * The second argument's inferred type, for `is_a($value, <type>)` and nothing else.
     *
     * The name is read through {@see Names::calledFunctionName()} rather than off the written text, because
     * mago resolves an unqualified call inside a namespace to the namespaced candidate whether or not it
     * exists — a lesson this runtime has already paid for once, on a constant rather than a function.
     */
    private static function isACallType(NodeAnalysisContext $context, Node $node): ?Type
    {
        // The callee, not the call: {@see Names::calledFunctionName()} reads a *name* and answers null for a
        // call node, which silently made this branch unreachable and cost `is_a()` its finding. The callee is
        // the first `Expression` child, the same navigation the call rules already use.
        if (Names::calledFunctionName($context, Calls::nthExpression($context, $node, 0)) !== 'is_a') {
            return null;
        }

        return Support::expressionType($context, Calls::positionalArgAt(Calls::argumentList($context, $node), 1));
    }
}
