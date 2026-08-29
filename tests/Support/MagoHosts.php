<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

/**
 * Extra analyzer extensions on the mago side of a differential, as TOML.
 *
 * PHPStan reaches a framework corpus through larastan or `phpstan-symfony`; mago reaches it through nothing.
 * A difference measured across that gap describes the gap rather than the port, so the harness can carry a
 * comparable plugin and say which of the two it is measuring.
 *
 * Rendered last in the file, and deliberately so: TOML tables are positional, and a host block placed
 * between two keys of an earlier table silently adopts the keys below it. A peer session put one mid-file
 * and moved `register-super-globals` out of `[analyzer]`, with no visible difference in the output.
 */
final class MagoHosts
{
    /**
     * @param list<string> $hosts
     */
    public static function render(array $hosts): string
    {
        $blocks = '';
        foreach ($hosts as $index => $host) {
            $blocks .= "\n[extension-hosts.extra{$index}]\ncommand = [\"php\", \"{$host}\"]\nworkers = 1\n";
        }

        return $blocks;
    }
}
