<?php declare(strict_types=1);

namespace Control;

interface Describer
{
    public function describe($a, $b): string;
}

trait Inner
{
    public function inner($z): string
    {
        return (string) $z;
    }
}

trait Describes
{
    use Inner;

    public function describe($a, $b): string
    {
        return (string) $a . (string) $b;
    }
}

final class Plain implements Describer
{
    use Describes;
}

final class Renames implements Describer
{
    use Describes {
        describe as private traitDescribe;
    }

    public function describe($x, $y): string
    {
        return $this->traitDescribe($x, $y);
    }
}
