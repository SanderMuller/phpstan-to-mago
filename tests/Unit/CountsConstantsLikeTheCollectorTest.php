<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;
use Sandermuller\PhpstanToMago\Tests\Support\CoverageControl;

/**
 * What `ConstantTypeDeclarationCollector` counts, which is what neither of its sibling collectors counts.
 *
 * The three member collectors in one package answer the trait question three different ways. A trait's
 * methods are counted once per class that *reaches* them, its properties zero times, and its constants once
 * per using class whether the class redeclares them or not. Each of the three is a measurement here rather
 * than a reading of the collector.
 *
 * @see TypeCoverage::constants()
 */
#[Group('engine')]
final class CountsConstantsLikeTheCollectorTest extends TestCase
{
    private const string CONTROLS = __DIR__ . '/../Fixtures/aggregate/controls';

    public function test_counts_every_shape_the_way_the_original_does(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/constant-shapes', 'constants'))->totals();

        // Four statements in `One` — the grouped pair is one — plus the parent's, the interface's, the enum's
        // and the trait's counted once for each of its two users. None of the enum's two cases.
        $this->assertSame(9, $original, 'The real rule no longer counts this control as 9.');
        $this->assertSame($original, $port);
    }

    /**
     * The trait halves, which only separate when they sit together.
     *
     * `Overrider` declares the constant its trait declares, and the pair counts 2: the class's own, and the
     * trait's, analysed in the class's context all the same. A reach test — the one the return metric needs,
     * because a class's own method does take the name away from the trait — would read this as 1.
     *
     * `Unused` is the other half. A trait nobody uses is analysed in no class's context and counts 0, which
     * is what stops "count each declaration once" from reaching the same total by cancelling two errors.
     */
    public function test_a_trait_constant_is_counted_per_user_and_never_taken_away_by_an_override(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/constant-in-trait', 'constants'))->totals();

        $this->assertSame(2, $original, 'The real rule no longer counts this control as 2.');
        $this->assertSame($original, $port);
    }

    /**
     * A trait and its only user in one file, which is what the containment test is for.
     *
     * The codebase lists a trait's constant on the using class with the trait's own declaring location. When
     * the two share a file, comparing files says the class wrote it and the declaration is counted twice —
     * once for the class and once for the trait's single user. Comparing spans says it was written in the
     * trait, which is where it is counted.
     */
    public function test_a_trait_sharing_a_file_with_its_user_is_still_counted_once(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/constant-in-one-file', 'constants'))->totals();

        $this->assertSame(1, $original, 'The real rule no longer counts this control as 1.');
        $this->assertSame($original, $port);
    }

    /**
     * The grouped declaration the property metric still over-counts, which this one does not.
     *
     * `properties()` finds a declaration's statement by scanning the source back to the previous `;` or
     * brace, and a default holding either character breaks it. One consumer writes
     * `private const string DYNAMIC_TEXT = 'Welcome {name}', STATIC_TEXT = '...';`, which was the whole of a
     * +1 delta on its 715 constants. `ClassLikeConstant` is the statement, so nothing is inferred from text
     * here.
     */
    public function test_a_grouped_declaration_with_a_brace_in_its_default_counts_once(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/constant-grouped-with-literal', 'constants'))->totals();

        $this->assertSame(1, $original, 'The real rule no longer counts this control as 1.');
        $this->assertSame($original, $port);
    }
}
