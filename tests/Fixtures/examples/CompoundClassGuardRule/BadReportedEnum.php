<?php

declare(strict_types=1);

namespace Examples\Compound;

/** An enum is not a class, so the exemption does not apply and the rule reports. */
enum BadReportedEnum: string
{
    case One = 'one';
}
