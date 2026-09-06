<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\NodeAnalysisContext;

/**
 * The analysed PHP version, in the encoding a transplanted rule compares against.
 *
 * The two engines pack a version differently and neither says so at the comparison. Mago's
 * `PHPVersion::fromParts()` builds `(major << 16) | (minor << 8) | patch`; PHPStan's `PhpVersion` uses
 * `major * 10000 + minor * 100 + patch`. So 8.3.0 is 525056 to one and 80300 to the other, and a rule
 * comparing mago's `id` against a table of PHPStan-shaped ids finds every one of them smaller — which for a
 * deprecation table means reporting every option on every project.
 *
 * Neither number looks wrong on its own; they only look wrong beside each other. Read `fromParts()` in
 * `Mago\Sdk\PHPVersion` rather than probing the field, because a probe that shows `id` exists and is an int
 * answers a narrower question than the comparison asks.
 */
final class Versions
{
    /**
     * The analysed PHP version as PHPStan encodes it, so a rule's own thresholds compare unchanged.
     *
     * Built from the parts rather than converted from the packed id, which makes the mapping exact instead of
     * arithmetic anyone has to check.
     */
    public static function phpstanVersionId(NodeAnalysisContext $context): int
    {
        $version = $context->phpVersion;

        return $version->major() * 10000 + $version->minor() * 100 + $version->patch();
    }
}
