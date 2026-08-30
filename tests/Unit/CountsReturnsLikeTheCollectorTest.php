<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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
final class CountsReturnsLikeTheCollectorTest extends TestCase
{
    private const string CONTROLS = __DIR__ . '/../Fixtures/aggregate/controls';

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function controls(): iterable
    {
        // A trait's body is analysed once per using class, so its one method arrives three times.
        yield 'a trait method and three using classes' => ['three-users', 3];
        // And zero times for a trait nobody uses: the body is never analysed in any class's context.
        yield 'a trait nobody uses' => ['no-users', 0];
        // Once, for the class that writes the `use`. A subclass does not count its parent's trait again.
        yield 'a trait on an abstract base with two subclasses' => ['inherited-trait', 1];
        // Once, for the one class at the end of the chain — not once per link in it.
        yield 'a trait used by a trait used by a class' => ['trait-in-trait', 1];
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
     * The one control that is *meant* to disagree, and the reason this metric is not mapped.
     *
     * `Over` uses `Provided` and declares its own `m()`. The class's own method wins, so the trait's version
     * is never analysed in that class's context and PHPStan counts one declaration. This counts two: the
     * multiplier asks how many classes use the trait and not which of them actually reach the declaration.
     *
     * `DeclaredParameters::timesCounted()` answers that question — through `reachedAs()`, which follows
     * overrides and `insteadof` and aliases — and it does so by walking the syntax rather than the metadata,
     * which is the shape this metric would have to take as well.
     *
     * Written as the exact divergence rather than "at least one", because a bound nobody pins is how +2
     * becomes +400, and the numbers were predicted before the run: one declaration to the original, two here.
     */
    public function test_an_overridden_trait_method_is_the_divergence_that_remains(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/overridden-trait-method', 'returns'))->totals();

        $this->assertSame(1, $original);
        $this->assertSame(2, $port);
    }
}
