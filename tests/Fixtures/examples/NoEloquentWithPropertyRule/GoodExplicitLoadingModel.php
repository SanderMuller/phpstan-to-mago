<?php

declare(strict_types=1);

namespace Examples\Models;

use Illuminate\Database\Eloquent\Model;

final class GoodExplicitLoadingModel extends Model
{
    /**
     * An explicit empty default eager-loads nothing and merely restates Eloquent's own default, which the
     * rule skips. This is the guard whose polarity is inverted: it returns false on a match and true after
     * the loop, so a translation that assumed the usual "return true when it matches" reports here.
     */
    protected $with = [];

    /** A property of another name is none of the rule's business. */
    protected $withCount = ['comments'];
}
