<?php

declare(strict_types=1);

namespace Examples\Cases;

use Sandermuller\PhpstanToMago\Tests\Fixtures\Rules\BaseFixtureCase;

final class Extending extends BaseFixtureCase
{
    // Declared on a class that reaches the base, which is what the gate lets through.
    public function declared(): void {}
}
