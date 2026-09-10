<?php

declare(strict_types=1);

namespace Branches\Reporting;

/**
 * The exempting guard inside the first branch, and a call neither branch names.
 *
 * `$exempt->first()` is the guard the branch carries: it exits before the report, so a port that dropped the
 * guard while keeping the branch would report here. `->third()` is the control for the branch conditions
 * themselves -- neither check names it, so a rule that reported on it would be matching nothing in
 * particular.
 */
final class GoodExemptAndUnchecked
{
    public function report(object $subject): void
    {
        $exempt = $subject;
        $exempt->first();
        $subject->third();
    }
}
