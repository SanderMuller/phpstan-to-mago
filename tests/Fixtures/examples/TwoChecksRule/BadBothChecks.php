<?php

declare(strict_types=1);

namespace Checks\Reporting;

/**
 * Both checks fire on one file, which is the point of the pair.
 *
 * Flattened into one guard chain, `dump()` not being an invade call would exit the rule before the second
 * check ran — so one finding here, rather than two, is what a lost per-check block looks like.
 */
final class BadBothChecks
{
    public function report(array $rows, object $subject): mixed
    {
        dump($rows);

        return invade($subject)->hidden;
    }
}
