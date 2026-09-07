<?php

declare(strict_types=1);

namespace Examples\StaticCalls;

/**
 * The three shapes the rule leaves alone.
 *
 * - `instanceMethod()` is not static, which is the whole question.
 * - `dynamicName()` writes the method name as a variable, so the selector is not an identifier and the rule
 *   declines before it looks anything up. In mago the selector is a `Variable` rather than a
 *   `LocalIdentifier`, measured on the partial-application node.
 * - `unknownMethod()` calls a method the receiver's class does not declare, so `hasMethod()` is false and
 *   there is nothing to be static.
 */
final class GoodCallables
{
    public function instanceMethod(CallableSubject $subject): callable
    {
        return $subject->plain(...);
    }

    public function dynamicName(CallableSubject $subject, string $name): callable
    {
        return $subject->$name(...);
    }

    public function unknownMethod(CallableSubject $subject): callable
    {
        return $subject->notDeclaredAnywhere(...);
    }
}
