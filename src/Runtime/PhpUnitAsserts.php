<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Whether a call is made on a `PHPUnit\Framework\Assert`.
 *
 * `AssertRuleHelper::isMethodOrStaticCallOnAssert()` is the guard four of `phpstan-phpunit`'s rules open
 * with, and it is not a shape the inliner can take: its body is a decision tree that assigns a type in each
 * branch rather than a chain of guards that exit. So the *question* is ported and the four rules keep their
 * own bodies, the same split {@see CognitiveComplexity} is built on.
 *
 * The original's answer is `(new ObjectType('PHPUnit\Framework\Assert'))->isSuperTypeOf($calledOnType)->yes()`,
 * and `yes()` is the load-bearing word: for a union receiver *every* member has to be an `Assert`, and a
 * nullable one is therefore not. {@see Support::objectClasses()} already answers that way — it returns the
 * empty list rather than a partial one as soon as an atomic is not a named object — which is why the strict
 * reading is used here and never the `IgnoringNull` variant.
 *
 * `self`, `static` and `parent` all resolve to the enclosing class, which is what the original does: its
 * `parent` branch builds an `ObjectType` of `$scope->getClassReflection()->getName()` rather than of the
 * parent. Copied rather than corrected.
 *
 * **One shape is not answered: a static call on an expression, `$class::assertSame(..)`.** The original
 * reads `$scope->getType($node->class)` there. Probed rather than assumed — mago leaves `receiverType` null
 * on a static call — so this answers false, which is silence rather than a wrong finding, and it is recorded
 * here rather than left to look like a measurement.
 */
final class PhpUnitAsserts
{
    private const string ASSERT = 'PHPUnit\\Framework\\Assert';

    public static function isCallOnAssert(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        $classes = self::receiverClasses($context, $subject);
        if ($classes === []) {
            return false;
        }

        foreach ($classes as $class) {
            if (! Support::classDescendsFrom($context, $class, self::ASSERT)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The classes the call's receiver can be, or the empty list where that is not a settled set of classes.
     *
     * @return list<string>
     */
    private static function receiverClasses(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return [];
        }

        if ($node->kind !== NodeKind::StaticMethodCall) {
            return Support::objectClasses($context->receiverType);
        }

        $class = Support::classPart($context, $node);
        if (Names::isSpecialClassName($class)) {
            $enclosing = Support::enclosingClassName($context, $node);

            return $enclosing === null ? [] : [$enclosing];
        }

        $resolved = Names::resolvedName($context, $class);

        return $resolved === null ? [] : [$resolved];
    }
}
