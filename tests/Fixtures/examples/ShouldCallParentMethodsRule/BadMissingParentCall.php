<?php

declare(strict_types=1);

namespace Examples\PhpUnit;

use PHPUnit\Framework\TestCase;

abstract class ParentCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}

/**
 * Two overrides that never reach their parent, one per method the rule looks at.
 *
 * The parent has to be something other than `TestCase` itself: the rule exempts a class extending `TestCase`
 * directly, because `TestCase::setUp()` is empty and calling it buys nothing. `ParentCase` sits in between
 * and does real work, which is what makes skipping it a finding.
 */
final class MissingParentCall extends ParentCase
{
    protected function setUp(): void
    {
        $this->prepare();
    }

    protected function tearDown(): void
    {
        $this->prepare();
    }

    private function prepare(): void {}
}

/** A static `setUp()` that belongs to something else entirely. */
final class SharedFixtures
{
    public static function setUp(): void {}
}

/**
 * The control for the `parent::` test, and a violation in its own right.
 *
 * `MissingParentCall` above calls no static method at all, so a fold that accepted *any* static call named
 * `setUp` would still report it — the class test would be dead. This class calls one, statically, with the
 * right name and the wrong class. PHPStan reports it, because it is not the parent.
 */
final class StaticCallOnAnotherClass extends ParentCase
{
    protected function setUp(): void
    {
        SharedFixtures::setUp();
    }
}

/**
 * A `parent::setUp()` the original cannot see, and the plugin must not see either.
 *
 * `hasParentClassCall()` iterates `$stmts` and never recurses, so a call nested in an `if` is not a call as
 * far as the rule is concerned and PHPStan reports the class. The plugin reads the same top level. This row
 * exists so that making the walk recursive — the obvious "improvement" — fails the gate instead of passing
 * it: PHPStan would still report and the plugin would fall silent.
 */
final class GuardedParentCall extends ParentCase
{
    protected function setUp(): void
    {
        if ($this->ready()) {
            parent::setUp();
        }
    }

    private function ready(): bool
    {
        return true;
    }
}
