<?php declare(strict_types=1);

namespace Control;

/**
 * Two `@mixin` links between the parent and the declaration.
 *
 * `hasMethod()` recurses, so a name two links out still locks the method. This is the shape
 * `laravel/framework` actually writes: `Relation` is `@mixin Builder` and `Builder` is
 * `@mixin \Illuminate\Database\Query\Builder`. Following one link and stopping counted this at 5.
 */
class Far
{
    public function invented(string $one, int $two): void {}
}

/** @mixin Far */
class Near {}

/** @mixin Near */
class Base {}

final class Subject extends Base
{
    public function invented(string $one, int $two): void {}

    public function plain(string $only): void {}
}
