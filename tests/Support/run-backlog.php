<?php

declare(strict_types=1);

/**
 * Prints the refused rules in the order they are worth working on.
 *
 *   php tests/Support/run-backlog.php
 *
 * A wrapper. {@see Backlog} holds the ordering and its reasons, so the same answer is available to a test
 * without running a subprocess -- which is the point: this ordering used to be improvised in a shell
 * one-liner and gave a different answer each time it was asked.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo Sandermuller\PhpstanToMago\Tests\Support\Backlog::render();
