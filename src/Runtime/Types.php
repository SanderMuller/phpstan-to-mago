<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringType;

/**
 * What an inferred type says, which is Mago's answer to the questions PHPStan asks a `Type` object.
 *
 * A closed group: nothing here reads the CST, and nothing else in the runtime calls it. Two of its answers
 * were measured rather than read — a union keeps the class *and* the interface it implements where PHPStan
 * resolves to one, and `Foo::class` is a `ClassLikeString` rather than a literal string. Both are recorded
 * on the methods that carry them.
 */
final class Types
{
    /**
     * Whether an inferred type is callable, which is `$type->isCallable()->yes()`.
     *
     * Mago models a type as its atomic parts, and a callable is one of them. A closure object is a named object
     * rather than a `CallableType`, so it is matched by name — that is the shape `Closure::fromCallable()` and a
     * closure literal both produce.
     *
     * **Every atomic except null, and at least one.** Not the union rule {@see typeIsBoolean} follows, and the
     * difference is the caller's: `CallableTypeAnalyzer::isClosureOrCallableType()` runs
     * `TypeCombinator::removeNull()` over the type *before* asking `isCallable()->yes()`. So a null in the
     * union is discarded and anything else in it is not.
     *
     * Both halves were measured, in opposite directions, and one reading of this satisfied neither:
     *
     * - *Any* atomic was too loose. `nesbot/carbon`'s `Rounding.php` declares
     *   `callable|string $function = 'round'` and calls `$function(..)`. `removeNull` takes nothing away,
     *   `callable|string` is `maybe`, and the rule reports; answering on the first callable atomic stayed
     *   silent.
     * - *Every* atomic was too strict. Seven Laravel sites call a `?Closure` with no null guard —
     *   `Builder::findOr()` is one — so mago's type is `Closure|null` where PHPStan's, after `removeNull`,
     *   is `Closure`. Counting the null made the port report where the rule does not.
     */
    public static function typeIsCallable(NodeAnalysisContext $context, ?Type $type): bool
    {
        if (! $type instanceof Type || $type->atomicTypes === []) {
            return false;
        }

        $callable = false;
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }

            if (! self::isCallableAtomic($context, $atomic)) {
                return false;
            }

            $callable = true;
        }

        return $callable;
    }

    /** A closure object is a named object rather than a `CallableType`, which is what both spellings produce. */
    private static function isCallableAtomic(NodeAnalysisContext $context, object $atomic): bool
    {
        return $atomic instanceof CallableType
            || ($atomic instanceof NamedObjectType && strcasecmp(ltrim($atomic->name, '\\'), 'Closure') === 0)
            || self::objectDeclaresInvoke($context, $atomic)
            || self::stringIsRefinedToCallable($atomic)
            || self::literalNamesACallable($context, self::literalStringOfAtomic($atomic));
    }

    /**
     * An object whose class declares `__invoke`, which PHP calls and PHPStan reports as callable.
     *
     * Mago types `(callable)|Processor` and `(callable)|Plain` identically — `NamedObjectType | CallableType`
     * either way — so the type alone cannot say whether the object arm is invokable. The codebase can, and
     * `Reflect::methodExists()` is the same lookup the mixin walk already uses, so an inherited or trait
     * `__invoke` answers here too.
     *
     * Found by the corpus sweep on `monolog`, whose processors are
     * `array<(callable(LogRecord): LogRecord)|ProcessorInterface>` and whose `ProcessorInterface` declares
     * `__invoke`. Three sites, and the port reported all three while PHPStan exempted them.
     *
     * `Closure` is still matched by name above rather than through this: it is the one class where the answer
     * is a language fact rather than a codebase lookup, and a corpus that cannot resolve `Closure` would
     * otherwise silently start reporting every closure call.
     */
    private static function objectDeclaresInvoke(NodeAnalysisContext $context, object $atomic): bool
    {
        return $atomic instanceof NamedObjectType
            && Reflect::methodExists($context, $atomic->name, '__invoke');
    }

    /**
     * A `callable-string`, which is what `is_callable()` narrowing produces for a string it cannot resolve.
     *
     * PHPStan spells it `CallableStringType` and answers `isCallable()` yes for it. Mago spells it as a
     * `ScalarType` of kind `String` carrying `callable: true` on its `StringType` refinement, and
     * `ScalarType::__toString()` renders only the kind — so a `callable-string` and a plain `string` render
     * identically, and the flag is the only thing that distinguishes them.
     *
     * Measured rather than inferred, because a probe reading the rendering said the opposite.
     * `ReadsTheCallableStringRefinementTest` asserts the flag at six starting types, with `is_string` as the
     * row where it must stay false.
     */
    private static function stringIsRefinedToCallable(object $atomic): bool
    {
        if (! $atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::String) {
            return false;
        }

        return $atomic->refinement instanceof StringType && $atomic->refinement->callable;
    }

    /**
     * Whether a literal string is one PHP could call, which is what `ConstantStringType::isCallable()` says.
     *
     * A string is not a shape the rule's exemption looked at until a corpus said otherwise: Laravel's
     * `Pluralizer` loops `['mb_strtolower', 'mb_strtoupper', 'ucfirst', 'ucwords']` and calls `$function(..)`,
     * where PHPStan's type is a union of four constant strings, every one of them callable, so
     * `isCallable()->yes()` and the rule declines. The port answered no for a string and reported both calls.
     *
     * The clauses are the original's, read out of `phpstan.phar` rather than inferred from the name, because
     * only its `Yes` exempts and it has three ways of not saying yes:
     *
     * - a function name, which is a plain existence check
     * - `Class::method` where the class is known and the method exists — and, from PHP 8.0, only when that
     *   method is static. `supportsCallableInstanceMethods()` is `versionId < 80000`, so on anything this
     *   package supports `'Widget::instanceMethod'` is *not* callable
     * - anything else is `No`, or `Maybe` for an unknown class or a missing method on a non-final one, and a
     *   maybe reports
     *
     * `Mixins::declaringMethod()` answers the method half, because PHPStan asks `hasMethod()` here too and a
     * `@mixin` on the named class supplies methods for it as much as anywhere else.
     */
    /**
     * The literal behind one string atomic, or null when it is not one literal string.
     *
     * A literal string is not a `StringType` beside the others: it is a `ScalarType` of kind `String` whose
     * *refinement* is the `StringType` carrying the value, and the type renders as plain `string` either way.
     * Testing `$atomic instanceof StringType` therefore matched nothing at all — measured, because the clause
     * written that way changed no finding on a probe holding four shapes it should have closed.
     *
     * {@see constantStringsOf()} reads the same structure for a whole type; this is the one-atomic form,
     * because the callable question is asked per atomic and a union has to answer for each.
     */
    private static function literalStringOfAtomic(object $atomic): ?string
    {
        if (! $atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::String) {
            return null;
        }

        $refinement = $atomic->refinement;

        return $refinement instanceof StringType && is_string($refinement->literalValue) ? $refinement->literalValue : null;
    }

    private static function literalNamesACallable(NodeAnalysisContext $context, ?string $literal): bool
    {
        if ($literal === null || $literal === '') {
            return false;
        }

        if ($context->codebase->functionExists($literal)) {
            return true;
        }

        // The original's own pattern, which is stricter than `str_contains($literal, '::')`: one identifier,
        // then `::`, then one identifier, and nothing else. `'Widget::a::b'` and `'$var::method'` are not
        // callable spellings and must not be read as one.
        if (preg_match('#^([a-zA-Z_\x7f-\xff\\\\][a-zA-Z0-9_\x7f-\xff\\\\]*)::([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$#', $literal, $matches) !== 1) {
            return false;
        }

        $method = Mixins::declaringMethod($context->codebase, $matches[1], $matches[2]);

        // `FunctionLikeMetadata->static`, not `flags->contains(MetadataFlags::STATIC)`. The flag exists and
        // is documented and reads false for a `public static function` — probed on this control, where the
        // method was found and the bit was not set. Reaching for the bit is the obvious move and it would
        // have made every `'Class::staticMethod'` report.
        return $method instanceof FunctionLikeMetadata && $method->static;
    }

    /**
     * Whether an inferred type is a union, which is what `$type instanceof UnionType` asks.
     *
     * Mago models a type as its atomic parts, so a union is simply a type with more than one of them. That
     * matches PHPStan on the nullable case too: `A|null` is a `UnionType` there and two atomic types here.
     */
    public static function typeIsUnion(?Type $type): bool
    {
        return $type instanceof Type && count($type->atomicTypes) > 1;
    }

    /**
     * Every class a type names, when the type is *certainly* an object — PHPStan's `getObjectClassNames()`.
     *
     * The list rather than the single-class reduction `soleObjectClass()` makes: a rule that loops these is
     * asking about each member of a union, and answering with one would go quiet on exactly the receivers the
     * loop exists for. Names as written — metadata lowercases them, and a rule comparing against a namespace
     * prefix needs the case.
     *
     * **A union with a non-object member names nothing**, and that is the whole difference between this and
     * {@see soleObjectClassIgnoringNull()}. `?Request` is not certainly a request, so a rule asking "is the
     * receiver a Request" gets no for it — which is what the original does, measured on two Nova actions
     * holding `protected ?ActionRequest $request = null` where the port reported and PHPStan did not.
     *
     * Both answers are needed and neither is a bug: the positional-flag check strips null deliberately,
     * because it asks what methods the receiver has rather than what it certainly is. The helper a rule gets
     * follows the accessor the rule used.
     *
     * @return list<string>
     */
    public static function objectClasses(?Type $type): array
    {
        return self::objectClassNames($type, false);
    }

    /**
     * The same list with a null atomic skipped, for a receiver the rule stripped null from first.
     *
     * `?Widget` carries a null atomic beside the object one, and the strict reading answers the empty list
     * for it — correct where the rule did not strip null, and silence where it did. A nullsafe call is the
     * shape: `TypeCombinator::removeNull($scope->getType($node->var))->getObjectClassReflections()` is one
     * class to PHPStan and was nothing here, so the plugin ran and found nothing on every nullable receiver.
     *
     * @return list<string>
     */
    public static function objectClassesIgnoringNull(?Type $type): array
    {
        return self::objectClassNames($type, true);
    }

    /** @return list<string> */
    private static function objectClassNames(?Type $type, bool $droppingNull): array
    {
        $names = [];
        foreach ($type instanceof Type ? $type->atomicTypes : [] as $atomic) {
            if ($droppingNull && $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }

            if (! $atomic instanceof NamedObjectType) {
                return [];
            }

            $names[] = $atomic->name;

            // An intersection is one atomic here, not several: mago hangs the other members off the first
            // one rather than putting them beside it. Reading `->name` alone answered `A` for `A&B`, so a
            // rule that asks each declarer of a method about it saw one declarer and could never find two
            // disagreeing — it reported where PHPStan declines. `Type::__toString()` collapses the same way,
            // which is measured in VERIFICATION.md; this is the same fact reached through a different door.
            foreach ($atomic->intersections ?? [] as $member) {
                if (! $member instanceof NamedObjectType) {
                    return [];
                }

                $names[] = $member->name;
            }
        }

        return $names;
    }

    /**
     * Whether every part of a type is a boolean, which is `Type::isBoolean()->yes()`.
     *
     * Every atomic, not any: PHPStan answers `yes` only when the whole type is boolean, so `bool|null` is not
     * one. A literal `true` is — its atomic is a boolean scalar carrying a refinement, and the refinement is
     * what makes it literal rather than what makes it a different kind.
     */
    public static function typeIsBoolean(?Type $type): bool
    {
        if (! $type instanceof Type || $type->atomicTypes === []) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if (! $atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::Boolean) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every part of a type is a literal string — PHPStan's `Type::isLiteralString()->yes()`.
     *
     * Every atomic has to be one, which is what `yes` means: `'a'|int` is a `maybe` there and is not one here
     * either, and an empty type is not a literal string. Answered from the same refinement
     * {@see constantStringsOf()} reads, so the two cannot drift — including the `ClassLikeString` a `::class`
     * expression produces, which PHPStan also calls a constant string.
     */
    public static function typeIsLiteralString(?Type $type): bool
    {
        if (! $type instanceof Type || $type->atomicTypes === []) {
            return false;
        }

        return count(self::constantStringsOf($type)) === count($type->atomicTypes);
    }

    /** Whether the inferred type is a single named object rather than a union, scalar or mixed. */
    public static function typeIsNamedObject(?Type $type): bool
    {
        if (self::namedObjectName($type, false) === null) {
            return false;
        }

        // `$this` and `static` are named objects to Mago and are *not* `ObjectType` to PHPStan: `ThisType`
        // extends `StaticType`, and `StaticType implements TypeWithClassName` without extending `ObjectType`.
        // So a rule guarding `! $callerType instanceof ObjectType` bails on a `$this->` receiver, and a port
        // that answers yes there is wider than the rule.
        //
        // Found on Shopware: `SingleArgEventDispatchRule` reported `$this->dispatch($nested, $name)` inside a
        // class that implements `EventDispatcherInterface`, where PHPStan is silent. Mago marks both cases on
        // the atomic — measured, `$this` comes back `isThis: true, static: true` and an ordinary receiver
        // false for both — so the distinction is readable rather than lost.
        foreach ($type instanceof Type ? $type->atomicTypes : [] as $atomic) {
            if ($atomic instanceof NamedObjectType && ($atomic->isThis || $atomic->static)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the inferred type is, or descends from, `$name`.
     *
     * Only a single named object answers this. A union receiver is not one class, and PHPStan's rules
     * that ask this question require exactly one object class reflection too, so refusing to answer for
     * a union matches the original rather than guessing at its intent.
     */
    public static function typeIsInstanceOf(NodeAnalysisContext $context, ?Type $type, string $name): bool
    {
        $className = self::namedObjectName($type, false);
        if ($className === null) {
            return false;
        }

        if (strcasecmp($className, $name) === 0) {
            return true;
        }

        foreach ($context->codebase->getClassAncestors($className) as $ancestor) {
            if (strcasecmp($ancestor, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an inferred type has a method, which is `$type->hasMethod($m)->yes()` in PHPStan.
     *
     * `methodExists()`, not `getMethod()`. The latter answers about methods the class *declares*, so it returns
     * null for every inherited one — measured on `Rector\Config\RectorConfig::make()`, which comes from the
     * container it extends: `getMethod` NULL, `getDeclaringMethod` found, `methodExists` yes, hierarchy
     * complete, four ancestors. PHPStan's question is hierarchy-inclusive, so this was answering no about any
     * method a class did not write itself, and `ForbiddenArrayMethodCallRule` stayed silent on
     * `[$rectorConfig, 'make']` where the original reports.
     *
     * The `getMethod()` call left in {@see attributeNames} is correct for the opposite reason: a declaration
     * hook fires on a method this class-like writes, so its own attributes are what that reads.
     */
    public static function typeHasMethod(NodeAnalysisContext $context, ?Type $type, string $method): bool
    {
        $className = self::namedObjectName($type, false);

        // Through `@mixin` as well, because `$type->hasMethod()` is answered by the same core extension that
        // answers `ClassReflection::hasMethod()`. Controlled on `ForbiddenArrayMethodCallRule`, which reports
        // `[$object, 'method']` when the method *exists*: with the name coming from a mixin on the class,
        // PHPStan reported and the port was silent, and `[$object, 'ownMethod']` and
        // `[$object, 'noSuchMethod']` agreed either way. A false negative, and the third of this shape —
        // {@see Mixins} carries the other two.
        return $className !== null && Mixins::declaringMethod($context->codebase, $className, $method) instanceof FunctionLikeMetadata;
    }

    /**
     * The one class an inferred type names, or null when it does not name exactly one.
     *
     * `$type->getObjectClassReflections()` with a `count() === 1` gate, which is how a rule asks "a single
     * concrete receiver". A union of two classes is not one class, and a rule that named a parameter against
     * one arbitrary member would suggest a name the other does not have.
     *
     * Cased as written — `NamedObjectType->name` keeps `Demo\Widget`, unlike `ClassLikeMetadata->name`, which
     * arrives lowercased. Measured, not read.
     */
    public static function soleObjectClass(?Type $type): ?string
    {
        return self::namedObjectName($type, false);
    }

    /**
     * The same question asked after dropping `null` from the type, which is `TypeCombinator::removeNull()`.
     *
     * Load-bearing for a nullsafe call, and it was measured rather than assumed: a `?Widget` receiver arrives
     * as two atomics, a `NamedObjectType` and a `SimpleAtomicType` of kind `Null`. The strict helper answers
     * null for that, so a port of a rule that removeNulls first would have gone silent on exactly the receivers
     * `?->` exists for — no error, just nothing reported.
     *
     * Separate from {@see soleObjectClass()} rather than replacing it: a rule that does *not* removeNull is
     * asking a narrower question, and answering the wider one for it would make the port wider than the rule.
     */
    public static function soleObjectClassIgnoringNull(?Type $type): ?string
    {
        return self::namedObjectName($type, true);
    }

    private static function namedObjectName(?Type $type, bool $droppingNull): ?string
    {
        if (! $type instanceof Type) {
            return null;
        }

        $names = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($droppingNull && $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }

            if (! $atomic instanceof NamedObjectType) {
                return null;
            }

            $names[strtolower($atomic->name)] = $atomic->name;
        }

        return count($names) === 1 ? reset($names) : null;
    }

    /**
     * The value behind a type that is one literal string, or null when it is not one.
     *
     * PHPStan spells this `ConstantStringType` and reads it with `->getValue()`. Mago's `Type` *renders* as
     * plain `string` either way — the literal is in the structure, on the scalar's refinement — so reading the
     * rendering would answer "not a constant" for every string in the corpus. Probed, not read.
     *
     * The same gap between the rendering and the structure runs through every shape, not only strings:
     * `DescribesTypesLikePhpstanTest` measures four more, and
     * `Type::$atomicTypes` carries all four.
     */
    public static function constantStringOf(?Type $type): ?string
    {
        return self::constantStringsOf($type)[0] ?? null;
    }

    /**
     * Every literal string a type names, which is PHPStan's `Type::getConstantStrings()`.
     *
     * The plural, and the singular above is now the first of these. A union of literal strings names more
     * than one, and the rules that reach here `foreach` the list and act per element — so reducing to one
     * would decide something the rule does not. Filtering the atomics is what the plural needs; the singular
     * reduces afterwards, where reducing is what the caller asked for.
     *
     * @return list<string>
     */
    public static function constantStringsOf(?Type $type): array
    {
        $values = [];
        foreach ($type instanceof Type ? $type->atomicTypes : [] as $atomic) {
            if (! $atomic instanceof ScalarType) {
                continue;
            }

            $refinement = $atomic->refinement;

            if ($atomic->kind === ScalarTypeKind::String) {
                if ($refinement instanceof StringType && is_string($refinement->literalValue)) {
                    $values[] = $refinement->literalValue;
                }

                continue;
            }

            // `Foo::class` is not a plain string to Mago: it is a `ClassLikeString` whose refinement holds
            // the name. PHPStan gives the same expression a `ConstantStringType`, so a rule asking
            // `getConstantStrings()` about a `::class` argument saw nothing here — measured on
            // `createMock(Concrete::class)`, where the atomic came back `ClassLikeString` and the list empty.
            //
            // Only the `Literal` variant. `class-string<T>` and the generic forms are the same atomic kind
            // with no literal behind them, and answering for those would name a class the code does not.
            if ($atomic->kind === ScalarTypeKind::ClassLikeString
                && $refinement instanceof ClassLikeStringType
                && $refinement->variant === ClassLikeStringVariant::Literal
                && is_string($refinement->literal)
            ) {
                $values[] = $refinement->literal;
            }
        }

        return $values;
    }
}
