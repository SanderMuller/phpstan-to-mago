<?php declare(strict_types=1);

namespace Control;

/**
 * Every shape the property collector treats differently, in one class.
 *
 * `$written` has a native type and `$docblocked` only a `@var`, and the original counts both as typed —
 * `isPropertyDocTyped()` is a second check beside the node one, so reading only the written type reads a
 * project of docblocked models as almost entirely untyped. `$withDefault` has neither and is untyped, which
 * is what says the `@var` reading is not picking up an inference from the default.
 *
 * `$promoted` is a `Param` to php-parser, and the collector visits `Property` nodes, so the original never
 * sees it.
 *
 * `$fromTrait` arrives from the trait and is counted where it is written, once per using class — the
 * codebase lists it on this class as well as on the trait, which `methods` does not do.
 */
final class One
{
    use Shared;

    public string $written = '';

    /** @var string */
    public $docblocked;

    public $withDefault = 'x';

    public function __construct(public int $promoted) {}
}
