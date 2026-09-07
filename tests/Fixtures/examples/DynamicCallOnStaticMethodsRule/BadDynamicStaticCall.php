<?php

declare(strict_types=1);

namespace Examples\StaticCallsPlain;

class PlainBase
{
    public static function InheritedStatic(): void {}
}

final class PlainSubject extends PlainBase
{
    public static function OwnStatic(): void {}

    public function plain(): void {}
}

/**
 * The same two shapes the `Callable` sibling reports, written as ordinary calls.
 *
 * `inherited()` is the declaring-class control: the static method comes from the parent, so the message names
 * `PlainBase` and not the receiver's class. Both are also the casing control — the methods are declared with
 * a capital and called in lower camel case, and the message carries the declared spelling.
 *
 * These two rows also exercise the branch this rule needed and its sibling did not: the report sits behind an
 * assignment and a prototype guard inside the `isStatic()` branch, folded into the guard chain around it.
 */
final class MakesCalls
{
    public function ownStatic(PlainSubject $subject): void
    {
        $subject->ownStatic();
    }

    public function inherited(PlainSubject $subject): void
    {
        $subject->inheritedStatic();
    }
}
