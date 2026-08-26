<?php

declare(strict_types=1);

namespace Examples\NonBooleanTernary;

final class GoodTernaries
{
    /** A boolean condition, which the rule allows. */
    public function flagged(bool $flag): string
    {
        return $flag ? 'yes' : 'no';
    }
}
