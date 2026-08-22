<?php declare(strict_types=1);

namespace Control;

trait Inner
{
    public function inner($a, $b): void {}
}
trait Outer
{
    use Inner;
}
final class User
{
    use Outer;
}
