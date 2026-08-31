<?php declare(strict_types=1);

namespace Examples\Annotations;

use PHPUnit\Framework\TestCase;

final class BadMethodAnnotations extends TestCase
{
    /**
     * @dataProvider(provide)
     * @depends(test_other)
     */
    public function test_it(): void {}

    public function test_other(): void {}
}
