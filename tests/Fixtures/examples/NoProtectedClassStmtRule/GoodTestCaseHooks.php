<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/** `setUp()` and `tearDown()` are named exemptions: PHPUnit declares them protected. */
final class WidgetTest
{
    protected function setUp(): void {}

    protected function tearDown(): void {}
}
