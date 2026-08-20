<?php

declare(strict_types=1);

namespace Examples\Models;

use Illuminate\Database\Eloquent\Model;

final class BadEagerLoadingModel extends Model
{
    /** Eager-loads on every query, which is what the rule is about. */
    protected $with = ['author'];
}
