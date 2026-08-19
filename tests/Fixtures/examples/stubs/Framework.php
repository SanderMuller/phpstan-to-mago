<?php

declare(strict_types=1);

/**
 * The framework classes the examples extend, cut down to what the rules ask about.
 *
 * The rules gate on ancestry and on a method's name, so a stub needs the name, the hierarchy and a
 * signature — never a body. Both tools resolve against these rather than against a real Symfony,
 * Doctrine or PHPUnit install, which keeps an example pair readable and the gate self-contained.
 */

namespace Symfony\Bundle\FrameworkBundle\Controller;

abstract class AbstractController
{
    public function get(string $id): mixed
    {
        return null;
    }

    public function getDoctrine(): mixed
    {
        return null;
    }
}

namespace Symfony\Component\Console\Command;

abstract class Command
{
    public function get(string $id): mixed
    {
        return null;
    }
}

namespace PHPUnit\Framework;

abstract class TestCase
{
    public function createMock(string $class): MockObject
    {
        return new MockObject();
    }
}

class MockObject
{
    public function method(string $name): self
    {
        return $this;
    }

    public function willReturnOnConsecutiveCalls(mixed ...$values): self
    {
        return $this;
    }

    public function willReturnCallback(callable $callback): self
    {
        return $this;
    }
}

namespace Doctrine\Common\DataFixtures;

interface FixtureInterface
{
    public function load(object $manager): void;
}

namespace Doctrine\ORM;

class EntityManager
{
    public function getRepository(string $class): object
    {
        return new \stdClass();
    }
}

namespace PHPUnit\Framework\MockObject;

interface MockObject {}

namespace Symfony\Component\EventDispatcher;

interface EventDispatcherInterface
{
    public function dispatch(object $event, ?string $eventName = null): object;
}

class EventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event, ?string $eventName = null): object
    {
        return $event;
    }
}

namespace Doctrine\ORM;

class EntityRepository {}

namespace Doctrine\Bundle\DoctrineBundle\Repository;

class ServiceEntityRepository {}

namespace Examples\Doubles;

/**
 * A test double of our own, not PHPUnit's `MockObject`.
 *
 * `ExplicitExpectsMockMethodRule` asks whether the receiver's type has an `expects()` method, and the real
 * `PHPUnit\Framework\MockObject\MockObject` shipped in this repo's vendor shadows any stub of that name,
 * so a stub could never answer yes. This class can.
 */
interface Mock
{
    public function expects(object $matcher): self;

    public function method(string $name): self;
}

namespace Vendor;

/**
 * A class outside the first-party namespaces, for `PositionalFlagRule`.
 *
 * The rule gates on the namespace of the class that *declares* the constructor, so naming a bare flag is
 * only asked of code the consumer owns. Without a class here the good example could not exercise that
 * guard, and dropping the guard would leave the pair green.
 */
class Widget
{
    public function __construct(public bool $enabled) {}

    /** For the receiver-based positional-flag rules: a method whose declaring class is not first-party. */
    public function toggle(bool $enabled): void {}
}

namespace Symfony\Contracts\Service\Attribute;

/** Symfony's autowiring attribute, for `NoRequiredOutsideClassRule`. */
#[\Attribute]
class Required {}

namespace Examples\Wiring;

/** An attribute that is not the one that rule looks for. */
#[\Attribute]
class SomeOtherAttribute {}

namespace Doctrine\ODM\MongoDB\Repository;

/** A parent type `RequiredOnlyInAbstractRule` skips, because the pattern is the framework's own there. */
class DocumentRepository {}
