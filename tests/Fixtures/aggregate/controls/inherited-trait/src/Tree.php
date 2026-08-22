<?php declare(strict_types=1);

namespace Control;

trait Shared
{
    public function shared($a, $b): void {}
}
abstract class Base
{
    use Shared;
}
final class Child extends Base {}
final class Other extends Base {}
