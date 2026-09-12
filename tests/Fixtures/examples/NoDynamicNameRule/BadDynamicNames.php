<?php

declare(strict_types=1);

namespace Examples\Dynamic;

/**
 * Every dynamic spelling the rule reports, across both of its branches and all six of its targets.
 *
 * The rule registers for every expression — `getNodeType()` returns `Expr::class`, which its own comment calls
 * "a trick to allow multiple node types" — and branches on the concrete kind. One branch handles the two
 * static accesses, the other the calls and the property read.
 */
final class BadDynamicNames
{
    public function everyKind(string $name, object $subject, string $class, mixed $next, callable|string $mixedCallable = 'strlen'): mixed
    {
        // Branch one: the class part is computed, the member name is written.
        $constant = $class::FIXED;
        $staticProperty = $class::$prop;

        // Branch two: the member name is computed.
        $property = $subject->$name;
        $method = $subject->$name();
        $staticMethod = $class::$name();

        // A *function* call whose name is a plain variable, the sixth target and the one this pair had none
        // of. `Holder::$prop` and `$next(1)` spell the name part identically in mago, and the written-name
        // predicate answered "written" for both — so every middleware pipeline in a real consumer went
        // unreported.
        $called = $next(1);

        // A union the rule does *not* exempt: `removeNull` takes nothing out of `callable|string`, so
        // `isCallable()->yes()` is maybe and the rule reports. `nesbot/carbon`'s `Rounding.php` declares
        // exactly this — `callable|string $function = 'round'` — and the port stayed silent while it
        // answered on the first callable atomic rather than on the whole union.
        $partly = $mixedCallable(1);

        // Three literal strings the original does *not* exempt, and each for its own reason. Only `Yes`
        // exempts, and `ConstantStringType::isCallable()` has three ways of not saying it.
        //
        // An instance method named as a string: callable before PHP 8.0 and not after, which the original
        // decides through `PhpVersion::supportsCallableInstanceMethods()` — `versionId < 80000`.
        $instanceString = 'Examples\\Dynamic\\Holder::run';
        $viaInstanceString = $instanceString();

        // A class nothing resolves, which is `Maybe` rather than `No` — and a maybe reports.
        $unknownClass = 'Examples\\Dynamic\\NoSuchClass::whatever';
        $viaUnknownClass = $unknownClass();

        // And a name that is no function at all.
        $unknownFunction = 'noSuchFunctionAnywhereAtAll';
        $viaUnknownFunction = $unknownFunction();

        return [$constant, $staticProperty, $property, $method, $staticMethod, $called, $partly, $viaInstanceString, $viaUnknownClass, $viaUnknownFunction];
    }
}
