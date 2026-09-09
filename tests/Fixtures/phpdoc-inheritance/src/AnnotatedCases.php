<?php

declare(strict_types=1);

namespace DocInherit;

use PHPUnit\Framework\TestCase;

/**
 * Every class here names a target nothing declares, because the rule under test reports an *invalid*
 * target — a valid one would leave the row silent for a second reason and the pair would not discriminate.
 *
 * @coversDefaultClass \DocInherit\NothingDeclaresThis
 */
abstract class ParentCase extends TestCase
{
}

// The row under test: does resolution hand this class the parent's tag?
final class InheritsFromParent extends ParentCase
{
    public function test_one(): void {}
}

/**
 * The control: the same annotation on the class itself, which must report.
 *
 * @coversDefaultClass \DocInherit\NothingDeclaresThisEither
 */
final class OwnAnnotation extends TestCase
{
    public function test_two(): void {}
}

/**
 * A method-level tag on a parent, for the method rule rather than the class one.
 *
 * @covers \DocInherit\AlsoNothing
 */
abstract class ParentWithCovers extends TestCase
{
    public function test_inherited(): void {}
}

final class InheritsMethodCovers extends ParentWithCovers
{
}

final class OwnMethodCovers extends TestCase
{
    /**
     * The control for the method rule.
     *
     * @covers \DocInherit\AlsoNothingEither
     */
    public function test_own(): void {}
}
