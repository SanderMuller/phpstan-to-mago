<?php

declare(strict_types=1);

namespace Examples\BodylessMethod;

final class Good
{
    /** A body with statements in it. */
    public function works(): int
    {
        return 1;
    }

    /** An empty body is still a body — the braces are written, so php-parser holds a list of none. */
    public function empty(): void {}
}
