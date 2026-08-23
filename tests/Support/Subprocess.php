<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

/**
 * The environment every differential starts its tools in.
 *
 * `laravel/pao` is a dev dependency that autoloads through a composer `files` entry, detects that an agent is
 * driving the terminal, and rewrites `phpstan analyse` — it forces `--error-format=json`, silences stdout, and
 * prints its own `{"tool": "phpstan", ...}` envelope at shutdown instead. Every harness here starts PHPStan in
 * a sandbox that symlinks this repository's `vendor`, so PHPStan loads that autoloader and pao rewrites the run
 * the differential depends on.
 *
 * That envelope *caps* how many errors it lists. `PhpstanReport` refuses a capped original rather than
 * comparing against it, which is correct and is what made this visible: one full-suite run failed with
 * "declares 1 errors and lists 0" on a rule the change under test did not touch, then passed on the next two
 * runs and in isolation. Contention was the first hypothesis and did not reproduce under twelve concurrent
 * PHPStan processes; the cause is upstream of contention, and `PAO_DISABLE` in `pao`'s own bootstrap is the
 * documented way off it.
 *
 * So the differentials do not ask pao to behave. They opt out, and PHPStan's own `--error-format=json` is what
 * arrives — the format `PhpstanReport` reads first and the only one that does not cap.
 */
final class Subprocess
{
    /**
     * The current environment with the tool wrapper switched off.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        /** @var array<string, string> $environment */
        $environment = getenv();

        return [...$environment, 'PAO_DISABLE' => '1'];
    }
}
