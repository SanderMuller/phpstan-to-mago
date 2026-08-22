<?php declare(strict_types=1);

namespace Control;

interface Handler
{
    public function handle($a, $b);
}
trait Handles
{
    public function handle($a, $b): void {}
}
final class Implementing implements Handler
{
    use Handles;
}
final class Plain
{
    use Handles;
}
