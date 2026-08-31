<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;

/**
 * What each coverage metric is called on either side of a comparison.
 *
 * Two names for one measurement, and neither derivable from the other: the runtime method
 * {@see TypeCoverage} answers it with, and the `measure: true` summary
 * line the real rule prints. Shared by {@see CoverageCorpus} and {@see CoverageControl} so that adding a
 * metric is one row rather than edits in two files that can disagree.
 *
 * Each summary is copied out of its rule rather than inferred from the metric's name. Three of the five
 * follow one pattern and two do not — `declares` prints "Strict declares coverage" where the shape predicts
 * "Declare coverage", and `constants` prints "Class constant type coverage" where it predicts "Constant type
 * coverage" — and a wrong summary is not a wrong number, it is no number at all: the regex finds nothing and
 * the run dies naming the real rule instead of the guess.
 */
final class CoverageMetrics
{
    /** @var array<string, array{method: string, summary: string}> */
    public const array ALL = [
        'parameters' => ['method' => 'parameters', 'summary' => 'Param type coverage'],
        'returns' => ['method' => 'returns', 'summary' => 'Return type coverage'],
        'properties' => ['method' => 'properties', 'summary' => 'Property type coverage'],
        'constants' => ['method' => 'constants', 'summary' => 'Class constant type coverage'],
        'declares' => ['method' => 'declares', 'summary' => 'Strict declares coverage'],
    ];
}
