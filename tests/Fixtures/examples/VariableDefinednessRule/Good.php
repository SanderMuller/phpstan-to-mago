<?php

declare(strict_types=1);

namespace Examples\Definedness;

final class CopiesWhatIsAlwaysThere
{
    public function fromParameter(string $given): string
    {
        $copy = $given;

        return $copy;
    }

    public function fromBothBranches(bool $flag): string
    {
        if ($flag) {
            $always = 'yes';
        } else {
            $always = 'no';
        }

        $copy = $always;

        return $copy;
    }
}
