<?php declare(strict_types=1);

namespace Control;

final class Edges
{
    public function none(): void {}

    public function variadic($first, string ...$rest): void {}
}
