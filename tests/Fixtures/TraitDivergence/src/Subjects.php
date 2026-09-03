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

/**
 * Used by nothing here, which is the degenerate case of the same divergence.
 *
 * PHPStan reaches a trait's body only through a using class, so with no user in the analysed tree it never
 * analyses this method and reports nothing for it. A node hook fires on the declaration and reports once.
 * Measured on `laravel/framework`: 9 of `NoDynamicNameRule`'s 15 port-only findings are in traits with no
 * user in the analysed paths — `SoftDeletes`, `ManagesTransactions`, `ReadsClassAttributes`.
 */
trait AnUnusedTrait
{
    public function inUnusedTrait(): void {}
}

/**
 * A trait whose only user is another trait, which no class uses either.
 *
 * The chain matters, and a count of `use` statements does not see it. Attributing the seven
 * `ForbiddenStaticClassConstFetchRule` port-only findings on `Illuminate\Database` needed this: six sat in
 * traits with no user at all, and the seventh — `BroadcastsEvents` — had one, `BroadcastsEventsAfterCommit`,
 * which is itself a trait that nothing in scope uses. PHPStan reaches a trait body only through a using
 * *class*, so a chain that never arrives at one is the same silence as no user at all.
 */
trait UsedOnlyByATrait
{
    public function inChainedTrait(): void {}
}

/** Uses the trait above and is used by nothing, so the chain stops here. */
trait NothingUsesThisOne
{
    use UsedOnlyByATrait;
}
