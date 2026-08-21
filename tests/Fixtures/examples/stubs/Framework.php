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

namespace Examples\Contracts;

/**
 * A trait and the interface every class-like using it must implement, for `TraitRequiresInterfaceRule`.
 *
 * The package ships no pairs — each project configures its own — so the fires-gate supplies this one to both
 * tools. Without a configured pair both would report nothing, and two tools agreeing on nothing is not
 * evidence that either looked.
 */
trait Localised {}

interface LocalisedContract
{
    public function locale(): string;
}

namespace Illuminate\Foundation\Http;

/**
 * Laravel's form-request base, for the `unvalidatedFormRequestField` check in `CombinedMethodCallRule`.
 *
 * Cut down to what decides the check: `rules()` for a subclass to declare, and the accessors it reads a
 * field through. The three methods a subclass can override to rewrite the validated data are declared
 * *here* on purpose — the rule treats an override by the framework as harmless and one by user code as
 * making the class opaque, so a stub without them would make every example opaque and the pair would prove
 * nothing.
 */
class FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [];
    }

    protected function prepareForValidation(): void {}
}

namespace Illuminate\Support;

/**
 * The debug helper the chained-debug check looks for, for `CombinedMethodCallRule`.
 *
 * The check only flags `->dump()` when the method is declared by a Laravel class, so the receiver has to
 * resolve to one. PHPStan reads the real class through the gate's autoload file; mago reads only the
 * sandbox's source paths, which is what this is here for.
 */
class Collection
{
    public function dump(): static
    {
        return $this;
    }
}

namespace Illuminate\Http;

/** The receiver the unsafe-request-data check requires, for the same reason. */
class Request
{
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}
