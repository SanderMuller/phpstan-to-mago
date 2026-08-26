<?php

declare(strict_types=1);

namespace Examples\NonBooleanIf;

final class GoodConditions
{
    /** A boolean condition, which the rule allows. */
    public function flagged(bool $flag): void
    {
        if ($flag) {
            echo 1;
        }
    }

    /** A comparison is a boolean too, so the guard holds without the condition being a `bool` variable. */
    public function compared(int $count): void
    {
        if ($count > 0) {
            echo 2;
        }
    }
}
