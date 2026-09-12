<?php

declare(strict_types=1);

namespace Examples\PhpUnit;

use PHPUnit\Framework\TestCase;

/**
 * The four ways the rule stays silent.
 *
 * - `CallsParent` reaches its parent in both methods, which is the whole question.
 * - `DirectlyOnTestCase` extends `TestCase` itself, which the rule exempts by name: the declaring class of
 *   the inherited `setUp()` is `TestCase`, and calling an empty parent buys nothing. Remove that exemption and
 *   this class reports.
 * - `OtherMethods` overrides neither `setUp()` nor `tearDown()`, so the name guard declines first.
 */
final class CallsParent extends ParentCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->prepare();
    }

    protected function tearDown(): void
    {
        $this->prepare();
        parent::tearDown();
    }

    private function prepare(): void {}
}

final class DirectlyOnTestCase extends TestCase
{
    protected function setUp(): void
    {
        $this->prepare();
    }

    private function prepare(): void {}
}

final class OtherMethods extends ParentCase
{
    protected function prepareFixtures(): void
    {
        $this->noop();
    }

    private function noop(): void {}
}
