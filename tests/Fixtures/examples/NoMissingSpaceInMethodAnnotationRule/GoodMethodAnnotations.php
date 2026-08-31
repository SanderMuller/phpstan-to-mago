<?php declare(strict_types=1);

namespace Examples\Annotations;

use PHPUnit\Framework\TestCase;

final class GoodMethodAnnotations extends TestCase
{
    /**
     * @dataProvider provide
     * @depends test_other
     * @coversNothing
     */
    public function test_it(): void {}

    /* @dataProvider(provide) */
    public function test_block_comment(): void {}

    public function test_other(): void {}
}
