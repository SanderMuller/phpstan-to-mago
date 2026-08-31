<?php declare(strict_types=1);

namespace Examples\Annotations;

use PHPUnit\Framework\TestCase;

/**
 * @covers\Examples\Annotations\Subject
 * @group(slow)
 */
final class BadClassAnnotations extends TestCase
{
    public function test_it(): void {}
}
