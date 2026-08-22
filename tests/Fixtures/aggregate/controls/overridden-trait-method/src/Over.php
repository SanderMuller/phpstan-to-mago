<?php declare(strict_types=1);

namespace Control;

trait Provided
{
    public function m($a, $b): void {}
}
final class Over
{
    use Provided;

    public function m($x, $y): void {}
}
