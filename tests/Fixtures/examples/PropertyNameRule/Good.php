<?php

declare(strict_types=1);

namespace Examples\Models;

/**
 * `$without` starts with the same letters, and `$withheld` contains them. Neither is `$with`.
 */
final class Comment
{
    protected array $without = [];

    protected array $withheld = [], $casts = [];
}
