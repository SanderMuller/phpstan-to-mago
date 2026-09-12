<?php

declare(strict_types=1);

namespace Examples\Definedness;

final class CopiesWhatMayNotBeThere
{
    public function fromNeverAssigned(): string
    {
        $copy = $missing;

        return $copy;
    }

    public function fromOneBranchOnly(bool $flag): string
    {
        if ($flag) {
            $sometimes = 'here';
        }

        $copy = $sometimes;

        return $copy;
    }
}
