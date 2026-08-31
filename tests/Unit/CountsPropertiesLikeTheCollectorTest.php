<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;
use Sandermuller\PhpstanToMago\Tests\Support\CoverageControl;

/**
 * What `PropertyTypeDeclarationCollector` counts, which is not what its sibling collectors count.
 *
 * One control, holding every shape the collector treats differently, because the shapes only separate when
 * they sit together. The number on the left is whatever the real rule says about it.
 *
 * The one worth naming is the trait. `ReturnTypeDeclarationCollector` visits `ClassMethod` nodes, so a
 * trait's method is visited once in every using class's context; this one visits `InClassNode` and takes
 * `count($classLike->getProperties())` off the class node, whose property list never holds the trait's. So a
 * trait's methods are counted per user and its properties are counted **zero times** — two collectors in one
 * package, one shape apart. Counting properties per user the way methods are counted gave 5 here against 3.
 *
 * @see TypeCoverage::properties()
 */
final class CountsPropertiesLikeTheCollectorTest extends TestCase
{
    private const string CONTROLS = __DIR__ . '/../Fixtures/aggregate/controls';

    public function test_counts_every_shape_the_way_the_original_does(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/property-shapes', 'properties'))->totals();

        // A native type, a `@var`-only property and a property with only a default. Not the promoted one,
        // which is a `Param`; not the trait's, which the class node never lists.
        $this->assertSame(3, $original, 'The real rule no longer counts this control as 3.');
        $this->assertSame($original, $port);
    }

    /**
     * And the four shapes the typed half turns on, held together in one project.
     *
     * A property counts as typed when it is written with a type, when a parent class already declares it, or
     * when its docblock mentions `callable` or `resource`. The middle one is a *guard* rather than a type
     * test and is the easiest to miss: leaving it out read 63 % against the real rule's 100 % on a consumer
     * where the counts were already exact. And `isPropertyDocTyped()` does not mean "has a `@var`" — it is a
     * substring test for the two types the original gives up on, so a `@var int` is untyped to it.
     *
     * This asserts the count, because `CoverageControl` compares totals. The typed *split* is pinned by the
     * corpus differential, which compares the percentage as well and reads 100 % and 93.3 % against the real
     * rule's own on the two consumers. Said plainly rather than left to look like more than it is.
     */
    public function test_holds_every_shape_the_typed_half_turns_on(): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/property-typing', 'properties'))->totals();

        // Four in `Subject` and the one it inherits, which is declared in `Base` and counted there.
        $this->assertSame(5, $original, 'The real rule no longer counts this control as 5.');
        $this->assertSame($original, $port);
    }
}
