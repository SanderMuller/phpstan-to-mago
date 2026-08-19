<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\TestCase;

final class GoodCountTest extends TestCase
{
    public function test_it_counts(): void
    {
        $this->atLeast(1);
    }

    public function atLeast(int $count): object
    {
        return new \stdClass();
    }
}
