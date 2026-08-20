<?php

declare(strict_types=1);

namespace Examples\Models;

/** The same declaration outside an Eloquent model, which is the third guard. */
final class GoodNotAModel
{
    protected $with = ['author'];
}
