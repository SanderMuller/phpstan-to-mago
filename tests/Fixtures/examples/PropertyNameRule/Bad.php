<?php

declare(strict_types=1);

namespace Examples\Models;

final class Post
{
    /** Declared second in a multi-name declaration, so the whole list has to be walked. */
    protected array $casts = [];

    protected array $other = [], $with = ['author'];
}
