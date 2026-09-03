<?php declare(strict_types=1);

namespace Control;

/**
 * The mixin target *documents* the method rather than writing it.
 *
 * `hasMethod()` is answered for a `@method` line too, so the guard still fires — and the mixin target has no
 * declaration of its own to count, which is why the original counts only `plain()`. A `@method` on a plain
 * parent needs nothing extra here, because `Codebase::methodExists()` already answers for one.
 *
 * @method invented(string $one, int $two)
 */
class Mixin {}

/** @mixin Mixin */
class Base {}

final class Subject extends Base
{
    public function invented(string $one, int $two): void {}

    public function plain(string $only): void {}
}
