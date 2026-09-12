<?php

declare(strict_types=1);

namespace Examples\StaticCallsPlain;

/**
 * The three shapes the rule leaves alone, matching its `Callable` sibling's pair.
 *
 * - `instanceMethod()` is not static.
 * - `dynamicName()` writes the method name as a variable, so the selector is not an identifier.
 * - `unknownMethod()` names a method the class does not declare, so there is nothing to be static.
 */
final class GoodCalls
{
    public function instanceMethod(PlainSubject $subject): void
    {
        $subject->plain();
    }

    public function dynamicName(PlainSubject $subject, string $name): void
    {
        $subject->$name();
    }

    public function unknownMethod(PlainSubject $subject): void
    {
        $subject->notDeclaredAnywhere();
    }
}
