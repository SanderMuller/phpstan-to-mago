<?php declare(strict_types=1);

namespace Control;

/**
 * The one shape a mixin cannot close: a target whose metadata is missing a method the runtime has.
 *
 * `Illuminate\Redis\Connections\Connection` is `@mixin \Redis`, so PHPStan skips every method ext-redis
 * declares. Mago carries `\Redis` too and knows `scan`, `sscan` and `zscan` — each controlled separately —
 * and not `hscan`, so that one declaration is the whole of this metric's remaining over-count on
 * `laravel/framework`. Meant to disagree: 1 against 4.
 *
 * @mixin \Redis
 */
abstract class Base {}

final class Subject extends Base
{
    public function hscan($key, $cursor, $options = []) {}

    public function plain(string $only): void {}
}
