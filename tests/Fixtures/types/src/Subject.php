<?php

declare(strict_types=1);

namespace TypeShapes;

/**
 * One probed call per type shape, each with its own callee so the two runs can be joined by name.
 *
 * Keyed by callee rather than by line, because Mago's spans are 0-based where PHPStan's are 1-based and `?->`
 * desugars into a different node on one side — both traps this project has already paid for once.
 *
 * A shape is here because it separates one rendering from another. The two intersections are a pair on
 * purpose: the docblock one alone could not tell "Mago drops intersection members" apart from "Mago ignores
 * that docblock", and the native one settles it.
 */
interface Alpha {}
interface Beta {}

enum Suit: string
{
    case Hearts = 'H';
}

final class Thing {}

final class Aaa {}

final class Zzz {}

/** @param Alpha&Beta $intersection */
function shapes(
    int $i,
    float $f,
    string $s,
    bool $b,
    Thing $object,
    ?Thing $nullable,
    int|string $union,
    mixed $anything,
    array $plainArray,
    Suit $enum,
    $intersection,
    Alpha&Beta $nativeIntersection,
    ?int $nullableScalar,
    ?Aaa $nullableEarly,
    ?Zzz $nullableLate,
): void {
    probe_int($i);
    probe_float($f);
    probe_string($s);
    probe_bool($b);
    probe_class($object);
    probe_nullable($nullable);
    probe_union($union);
    probe_mixed($anything);
    probe_array($plainArray);
    probe_enum($enum);
    probe_intersection($intersection);
    probe_native_intersection($nativeIntersection);
    probe_nullable_scalar($nullableScalar);
    probe_nullable_early($nullableEarly);
    probe_nullable_late($nullableLate);
    probe_literal_string('foo');
    probe_literal_int(42);
    probe_literal_bool(true);
    probe_null(null);
    probe_call(shapesReturningList());
}

/** @return list<Thing> */
function shapesReturningList(): array
{
    return [];
}

function probe_int(mixed $x): void {}
function probe_float(mixed $x): void {}
function probe_string(mixed $x): void {}
function probe_bool(mixed $x): void {}
function probe_class(mixed $x): void {}
function probe_nullable(mixed $x): void {}
function probe_union(mixed $x): void {}
function probe_mixed(mixed $x): void {}
function probe_array(mixed $x): void {}
function probe_enum(mixed $x): void {}
function probe_intersection(mixed $x): void {}
function probe_native_intersection(mixed $x): void {}
function probe_nullable_scalar(mixed $x): void {}

function probe_nullable_early(mixed $x): void {}

function probe_nullable_late(mixed $x): void {}
function probe_literal_string(mixed $x): void {}
function probe_literal_int(mixed $x): void {}
function probe_literal_bool(mixed $x): void {}
function probe_null(mixed $x): void {}
function probe_call(mixed $x): void {}
