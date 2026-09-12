<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/**
 * The class a `@mixin` points at, whose methods the parent therefore has.
 *
 * Public, because a protected member here would be reported on its own and the file is about the override.
 */
class MixinSource
{
    public function mixedIn(): string
    {
        return 'mixed in';
    }
}

/**
 * A parent that declares nothing and has `mixedIn()` anyway.
 *
 * @mixin MixinSource
 */
class MixedInBase {}

/**
 * An override of a method only a `@mixin` puts on the parent.
 *
 * `ClassReflection::hasMethod()` is answered by PHPStan's own `MixinMethodsClassReflectionExtension`, which
 * ships in core rather than in larastan, so the original skips this declaration exactly as it skips an
 * inherited one. A port reading only written and inherited methods reports it — measured on this shape
 * before the fix, and the same missing walk cost a *false negative* in
 * `PreventParentMethodVisibilityOverrideRule` at the same time.
 *
 * The shape is only writable because of the mixin. Narrowing a real public parent method to protected is a
 * fatal error, so a fixture cannot hold one; a mixed-in method has no declaration for PHP to check against.
 */
final class MixedInWidget extends MixedInBase
{
    protected function mixedIn(): string
    {
        return 'override';
    }
}
