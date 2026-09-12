<?php

declare(strict_types=1);

namespace Examples\StaticCalls;

class CallableBase
{
    public static function InheritedStatic(): void {}
}

final class CallableSubject extends CallableBase
{
    public static function OwnStatic(): void {}

    public function plain(): void {}
}

/**
 * Two first-class callables taken on static methods through an instance.
 *
 * `ownStatic()` is declared on the subject. `inheritedStatic()` is declared on the parent, and the message
 * names the *declaring* class rather than the receiver's — that is what `getDeclaringClass()` is for, and a
 * port reading the receiver's class instead would name `CallableSubject` for both.
 *
 * Both are also the casing control. The methods are declared with a capital in the middle and called in lower
 * camel case, which PHP allows: `MethodReflection::getName()` gives the *declared* spelling, so the message
 * reads `InheritedStatic` rather than the `inheritedStatic` the call site writes. Reusing the written name
 * would diverge here and nowhere else in this pair.
 */
final class TakesCallables
{
    public function ownStatic(CallableSubject $subject): callable
    {
        return $subject->ownStatic(...);
    }

    public function inherited(CallableSubject $subject): callable
    {
        return $subject->inheritedStatic(...);
    }
}
