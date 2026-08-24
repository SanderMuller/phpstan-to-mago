<?php

declare(strict_types=1);

namespace Control;

/**
 * The shape `nikic/php-parser`'s `Internal/TokenPolyfill.php` is written in: one class name declared twice in
 * one file, the first inside a version guard that returns.
 */
if (\PHP_VERSION_ID >= 80000) {
    class Polyfill extends \PhpToken {}

    return;
}

class Polyfill
{
    public function __construct(public int $id, public string $text) {}

    public function is(mixed $kind): bool
    {
        return $kind === $this->id;
    }
}
