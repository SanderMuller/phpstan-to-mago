<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\ShouldNotHappenException;
use Sandermuller\PhpstanToMago\Vocabulary;

/**
 * A reflection extension that claims one method nobody declared, which is the whole cause of the parameter
 * aggregate's accepted divergence.
 *
 * `ParamTypeDeclarationCollector` skips a method whose name a parent or interface has, and asks
 * `ClassReflection::hasMethod()` — so PHPStan's answer includes whatever the installed extensions say. A Mago
 * plugin has no equivalent, and reproducing one would mean reproducing every extension a consumer installs.
 * larastan's `ModelFactoryMethodsClassReflectionExtension` and `AuthsMethodsExtension` are two real instances;
 * this is the mechanism with nothing else attached, so a control can pin the divergence it produces exactly.
 *
 * Registered only by the `reflection-extension` control's own `services.neon`, so no other control sees it.
 *
 * @see Vocabulary::ACCEPTED_DIVERGENCE
 */
final class ControlMethodsExtension implements MethodsClassReflectionExtension
{
    /** The name this extension answers for, which no source in the control declares on an ancestor. */
    public const string INVENTED = 'invented';

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $methodName === self::INVENTED;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        // The collector asks only `hasMethod()`. Throwing rather than fabricating a reflection keeps the
        // control honest: if PHPStan ever does ask, the control fails loudly instead of measuring something
        // built to satisfy it.
        throw new ShouldNotHappenException(self::class . ' was asked for ' . $methodName . ', which it only claims to have.');
    }
}
