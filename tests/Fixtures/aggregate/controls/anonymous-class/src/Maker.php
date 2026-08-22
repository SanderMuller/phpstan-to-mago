<?php declare(strict_types=1);

namespace Control;

interface Shape
{
    public function draw($ia, $ib);
}
final class Maker
{
    public function make(): Shape
    {
        return new class implements Shape {
            public function draw($aa, $ab): void {}
        };
    }
}
