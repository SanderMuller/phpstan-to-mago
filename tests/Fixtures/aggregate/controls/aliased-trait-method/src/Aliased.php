<?php declare(strict_types=1);

namespace Control;

trait Describes
{
    public function describe($a, $b): string
    {
        return (string) $a . (string) $b;
    }
}

final class Plain
{
    use Describes;
}

final class Renames
{
    use Describes {
        describe as private traitDescribe;
    }

    public function describe($x, $y): string
    {
        return $this->traitDescribe($x, $y);
    }
}
