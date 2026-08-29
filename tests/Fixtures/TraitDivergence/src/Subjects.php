<?php

declare(strict_types=1);

namespace TraitDivergence;

/**
 * One method of every class-like shape a method hook can fire for.
 *
 * The trait is the point. Everything else is here so the test can say the two engines agree on the rest,
 * which is what makes the trait line a divergence rather than a general disagreement.
 */
final class PlainClass
{
    public function inClass(): void {}
}

abstract class AbstractClass
{
    public function inAbstract(): void {}

    /** No body. PHPStan fires for it, and so does mago -- checked, because it reads like a case that would not. */
    abstract public function abstractMethod(): void;
}

interface AnInterface
{
    public function inInterface(): void;
}

enum AnEnum: string
{
    case A = 'a';

    public function inEnum(): void {}
}

/** Used by two classes below, which is what makes PHPStan visit `inTrait` twice. */
trait ATrait
{
    public function inTrait(): void {}
}

final class UsesTheTrait
{
    use ATrait;
}

final class AlsoUsesIt
{
    use ATrait;
}
