<?php declare(strict_types=1);

namespace Control;

interface Contract
{
    public function m($ia, $ib);
}
trait Holds
{
    public function m($ta, $tb): void
    {
        $closure = function ($ca, $cb): void {};
        $arrow = fn ($aa) => $aa;
    }
}
final class Locked implements Contract
{
    use Holds;
}
