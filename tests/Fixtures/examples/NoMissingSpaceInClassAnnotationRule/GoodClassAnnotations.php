<?php declare(strict_types=1);

namespace Examples\Annotations;

use PHPUnit\Framework\TestCase;

/*
 * @covers\Examples\Annotations\Subject
 *
 * An ordinary block comment, which is not a docblock. PHPStan reads `getDocComment()`, which is only ever a
 * `Doc` node, so nothing in here is looked at at all.
 */

/**
 * @covers \Examples\Annotations\Subject
 * @coversNothing
 * @group slow
 * @author nobody
 */
final class GoodClassAnnotations extends TestCase
{
    public function test_it(): void {}
}
