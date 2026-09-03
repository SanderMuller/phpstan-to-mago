<?php declare(strict_types=1);

namespace Control;

/**
 * The control above with the `@mixin` line removed, and nothing else changed.
 *
 * Two declarations of `invented()` and one of `plain()`, all counted, so the pair separates "the mixin makes
 * the collector skip it" from "something else about this shape does".
 */
class Mixin
{
    public function invented(string $one, int $two): void {}
}

class Base {}

final class Subject extends Base
{
    public function invented(string $one, int $two): void {}

    public function plain(string $only): void {}
}
