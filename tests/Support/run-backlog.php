<?php

declare(strict_types=1);

/**
 * Prints the refused rules in the order they are worth working on.
 *
 *   php tests/Support/run-backlog.php [<run-refusal-yield.php report>]
 *
 * With a yield report the measured findings rank above every structural axis, which is what the closing line
 * has always asked for and the ordering could not act on. Without one the ranking is structural and says so.
 *
 * A wrapper. {@see Backlog} holds the ordering and its reasons, so the same answer is available to a test
 * without running a subprocess -- which is the point: this ordering used to be improvised in a shell
 * one-liner and gave a different answer each time it was asked.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo Sandermuller\PhpstanToMago\Tests\Support\Backlog::render($argv[1] ?? null);
