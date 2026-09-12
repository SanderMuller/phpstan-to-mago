<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The Mago floor is one number, and three places have to agree on it.
 *
 * **This exists because they disagreed and only one CI leg noticed.** Teaching the emitter to declare
 * `FileAnalysisRequirement::VariableDefinedness` raised what a generated plugin needs to 1.48.1, while
 * `composer.json` still allowed `^1.47.6` and the README still promised 1.47.6. Every check passed: the
 * suite was green on a machine holding 1.48.1, the emitted bytes were identical, PHPStan was clean. The
 * `prefer-lowest` leg resolved the floor the constraint actually named and every emitted plugin died with
 * `Undefined constant Mago\Sdk\Analyzer\FileAnalysisRequirement::VariableDefinedness`.
 *
 * A `require-dev` constraint is not inherited by anyone installing this package, so the CI leg was the only
 * consumer of it. **A reader of the README is the real consumer and nothing was checking that sentence at
 * all** — which is this repository's own finding about enforced rules against remembered ones, landing on
 * the one number a consumer needs before they install anything.
 *
 * So the assertion is agreement rather than a literal: raise the floor in `composer.json` and this fails
 * until the README says the same thing, and the other way round.
 */
#[CoversNothing]
final class PinsTheMagoFloorItEmitsAgainstTest extends TestCase
{
    public function test_the_readme_promises_the_floor_composer_requires(): void
    {
        $root = dirname(__DIR__, 2);

        $manifest = json_decode((string) file_get_contents($root . '/composer.json'), true);
        self::assertIsArray($manifest);
        $dev = $manifest['require-dev'] ?? [];
        self::assertIsArray($dev);

        $constraint = $dev['carthage-software/mago'] ?? null;
        self::assertIsString($constraint, 'carthage-software/mago is no longer a dev dependency.');

        self::assertSame(
            1,
            preg_match('/(\d+\.\d+\.\d+)/', $constraint, $match),
            "The mago constraint {$constraint} names no floor version.",
        );

        $floor = $match[1];
        $readme = (string) file_get_contents($root . '/README.md');

        $this->assertStringContainsString(
            "Mago {$floor} or later",
            $readme,
            "composer.json's mago floor is {$floor} and the README promises a different one. A generated "
            . 'plugin runs against whatever Mago the consumer installed, and `require-dev` does not reach '
            . 'them — that sentence in the README is the only thing that does.',
        );
    }
}
