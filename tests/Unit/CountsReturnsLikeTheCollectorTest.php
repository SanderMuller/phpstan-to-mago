<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;
use Sandermuller\PhpstanToMago\Tests\Support\CoverageControl;

/**
 * What `ReturnTypeDeclarationCollector` counts, asked of the real rule one control at a time.
 *
 * The sibling of {@see CountsParametersLikeTheCollectorTest}, over the same control projects: each is the
 * smallest project that separates one counting rule from the next, and the number on the left is whatever
 * the real rule says about it. They are shared because the question that makes them interesting — how many
 * times PHPStan analyses a body — is the same question for every metric, and only the totals differ.
 *
 * These exist because a fixture agreed by accident. A trait used by two classes and a trait used by nobody
 * gave the same total as the real rule while counting the wrong things in both directions: the unused
 * trait's method supplied the one the shared trait's second user was missing. Deleting the unused trait
 * separated them — PHPStan stayed at 3 and the port dropped to 2.
 *
 * @see TypeCoverage::timesAnalysed()
 */
#[Group('engine')]
final class CountsReturnsLikeTheCollectorTest extends TestCase
{
    private const string CONTROLS = __DIR__ . '/../Fixtures/aggregate/controls';

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function controls(): iterable
    {
        // A trait's body is analysed once per using class, and since type-coverage 2.3.7 its one method
        // counts once however many classes use it. It counted three times up to 2.3.6.
        yield 'a trait method and three using classes' => ['three-users', 1];
        // And zero times for a trait nobody uses: the body is never analysed in any class's context.
        yield 'a trait nobody uses' => ['no-users', 0];
        // Once, for the class that writes the `use`. A subclass does not count its parent's trait again.
        yield 'a trait on an abstract base with two subclasses' => ['inherited-trait', 1];
        // Once, for the one class at the end of the chain — not once per link in it.
        yield 'a trait used by a trait used by a class' => ['trait-in-trait', 1];
        // The class's own method wins, so the trait's version is never analysed in its context. Counting the
        // trait's *users* rather than the users that *reach* the declaration made this 2.
        yield 'a trait method the using class overrides' => ['overridden-trait-method', 1];
        // A renamed method still arrives, under the new name, and since 2.3.7 the renamed use and the plain
        // use are the same declaration: the class's own method and the trait's, once each. It was 3 up to 2.3.6.
        yield 'a trait method reached under an alias' => ['aliased-trait-method', 2];
        // The interface's own declaration counts, and so does the trait's — the LSP guard is the parameter
        // collector's, and this one has none. Since 2.3.7 the trait's counts once for its two users, so 2.
        // It was 3 up to 2.3.6.
        yield 'a trait method whose name an interface declares' => ['locked-by-interface', 2];
        // An anonymous class is a using class like any other, and has no name to ask the codebase about.
        yield 'an anonymous class implementing an interface' => ['anonymous-class', 3];
        // The reflection extension that bounds the parameter metric has nothing to act on here: this
        // collector asks no question a reflection extension answers.
        yield 'a class whose ancestor a reflection extension invents' => ['reflection-extension', 2];
        // `@method` declares what `__call()` answers and writes no node, so the collector never sees it.
        // Only the real method counts. The codebase lists both, which was 32 declarations on one consumer's
        // factory directory alone — Laravel's factories carry two `@method` lines each.
        yield 'a class whose docblock declares methods' => ['docblock-method', 1];
        // The language gives an enum `cases()`, and a backed one `from()` and `tryFrom()`. Nobody writes
        // them, so there is no node to visit and only the two declared methods count. This was +430 of a
        // +444 corpus delta, all of it in one directory of 157 enums.
        yield 'an enum and a backed enum' => ['enum-cases', 2];
        // A `@method` line takes no name away from a trait, so both users reach the declaration, which counts
        // once since 2.3.7 (twice up to 2.3.6). The codebase resolves the name to the documented one, which
        // said the documenting class did not — so the row still guards the reach, not only the count.
        yield 'a class documenting the trait method it uses' => ['documented-trait-method', 1];
        // One class, two traits, both using a third: the body of the third is analysed once per path. Up to
        // 2.3.6 that counted twice, and a walk carrying a visited set counted it once, which was the last
        // divergence in this metric on a real consumer — one class using two validation traits that both use
        // a URL-prefixing one, and a -1 in 18307. Since 2.3.7 the two paths are one declaration and count once.
        yield 'a class reaching one trait through two' => ['trait-diamond', 1];
    }

    #[DataProvider('controls')]
    public function test_counts_what_the_real_rule_counts(string $control, int $expected): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/' . $control, 'returns'))->totals();

        // Asserted against the original as well, so a `type-coverage` release that changes the counting fails
        // here instead of silently redefining what the port has to match.
        $this->assertSame($expected, $original, "The real rule no longer counts {$control} as {$expected}.");
        $this->assertSame($original, $port, "The port disagrees with the real rule on {$control}.");
    }

    /**
     * A second control that is meant to disagree, and it is the only over-count left.
     *
     * A class that documents a name two traits declare and picks one with `insteadof` counts 1 to the real
     * rule and 2 here. The `@method` line makes the codebase resolve the name to the docblock, so asking
     * where the name lands says "not the trait" for the winner as well as the loser, and the fallback that
     * rescues a documented trait method rescues both.
     *
     * Refusing the fallback wherever an adaptation block appears was tried and is worse — it takes the winner
     * out too and reads 0 against 1. Telling them apart means reading the `insteadof` winner out of the
     * `TraitUseAdaptation` node, which is work rather than a condition. Pinned exactly, because a bound
     * nobody pins is how +1 becomes +400, and neither consumer this was measured on contains the shape.
     */
    public function test_a_documented_insteadof_is_the_known_over_count(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/documented-insteadof', 'returns'))->totals();

        $this->assertSame(1, $original);
        $this->assertSame(2, $port);
    }

    /**
     * The one control that is *meant* to disagree, and it is a known under-count rather than a new cause.
     *
     * A class declared twice in one file behind a version guard is counted by PHPStan and by neither body
     * here — the same shape `Vocabulary::ACCEPTED_DIVERGENCE['parameters']` records as -7 on
     * `nikic/php-parser`. The codebase holds one declaration for a name, and the second is invisible to it.
     *
     * Asserted as the exact divergence rather than "at least one", because a bound nobody pins is how -1
     * becomes -400.
     */
    public function test_a_conditionally_redeclared_class_is_the_known_under_count(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/conditionally-redeclared', 'returns'))->totals();

        $this->assertSame(1, $original);
        $this->assertSame(0, $port);
    }
}
