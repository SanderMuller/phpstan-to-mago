<?php

declare(strict_types=1);

namespace Examples\Compound;

/** The name test does not match, so the rule has nothing to say. */
enum GoodOtherName: string
{
    case Two = 'two';
}
