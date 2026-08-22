<?php declare(strict_types=1);

namespace Control;

final class Callables
{
    /**
     * @param callable $c
     */
    public function skipped($c, $other): void {}

    /** @param string $s */
    public function counted($s, $other): void {}
}
