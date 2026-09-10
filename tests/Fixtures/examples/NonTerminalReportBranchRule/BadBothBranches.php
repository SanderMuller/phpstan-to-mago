<?php

declare(strict_types=1);

namespace Branches\Reporting;

/**
 * Both branches fire on one file, which is the whole point of the pair.
 *
 * The rule reports from inside a branch and then runs a second check after it. Folded into one guard chain,
 * a call named anything but `first` would exit the rule before the second check ran -- so one finding here,
 * rather than two, is what a lost per-branch block looks like. That is the failure the rule's docblock was
 * written against, and this file is what turns the argument into a measurement.
 */
final class BadBothBranches
{
    public function report(object $subject): void
    {
        $subject->first();
        $subject->second();
    }
}
