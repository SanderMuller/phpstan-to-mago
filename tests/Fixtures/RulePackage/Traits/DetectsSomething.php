<?php

declare(strict_types=1);

namespace Fixtures\RulePackage\Traits;

trait DetectsSomething
{
    private function detect(string $name): bool
    {
        return $name !== '';
    }
}
