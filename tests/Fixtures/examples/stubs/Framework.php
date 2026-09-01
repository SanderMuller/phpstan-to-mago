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

/**
 * The class the assert rules ask about, and the reason `TestCase` now has a parent.
 *
 * `AssertRuleHelper::isMethodOrStaticCallOnAssert()` asks whether a call's receiver is a
 * `PHPUnit\Framework\Assert`, which the stub could not answer while `TestCase` extended nothing — PHPStan
 * resolved the stub, found no `assertSame()` at all, and the rule could not fire in the sandbox.
 */
abstract class Assert
{
    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void {}

    public static function assertNull(mixed $actual, string $message = ''): void {}

    public static function assertTrue(mixed $condition, string $message = ''): void {}

    public static function assertFalse(mixed $condition, string $message = ''): void {}

    public static function assertCount(int $expectedCount, mixed $haystack, string $message = ''): void {}

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void {}
}

abstract class TestCase extends Assert
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

/**
 * A second pair, because one pair cannot tell "reports per violated pair" from "reports once per class".
 *
 * The rule accumulates a finding per configured pair a class-like violates, and every finding lands on the
 * class's own line — so two violations are two findings at one span. An example using one trait agrees with
 * a port that reports once, and the splitting-across-lines that rescued the attribute pair is not available
 * here: the span is the class, however the `use` statements are laid out.
 */
trait Auditable {}

interface AuditableContract
{
    public function auditKey(): string;
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

namespace Examples\Stubs;

/**
 * Two interfaces an example implements, for `ImplementsNamedInterfaceRule`.
 *
 * One is named in the rule by a written literal and one by a constant on another class, which are the two
 * spellings `implementsInterface()` has to fold. They sit here rather than beside the rule because mago
 * resolves only the sandbox's own source paths, and both tools have to see the same hierarchy.
 */
interface NamedByLiteral {}

interface NamedByConstant {}

namespace Examples\Stubs\Mocked;

/**
 * Three class-likes for `MockedClassKindRule`, one per answer its predicates give.
 *
 * The abstract one and the interface are what make `namedClassIsAbstract()` and `namedClassIsInterface()`
 * load-bearing: a pair holding only the concrete class passes with both predicates stubbed to false.
 */
class Concrete {}

abstract class Pending {}

interface Contract {}

namespace Examples\Stubs\Domain\Entity;

/**
 * Three class-likes under an `\Entity\` namespace, for `NoDocumentMockingRule`.
 *
 * The rule skips a mocked class that is abstract or an interface, and those two skips are the whole reason
 * its helper could not be inlined before. A pair holding only the concrete one proves neither.
 */
class Invoice {}

abstract class Ledger {}

interface Receipt {}

namespace Examples\Stubs\Domain\Plain;

/** Neither `\Document\` nor `\Entity\` in the name, so the rule never asks what kind of class it is. */
class Note {}

namespace Symfony\Component\Routing\Attribute;

/**
 * The attribute half of the route question. `SymfonyControllerAnalyzer` accepts either this or a `@Route`
 * docblock, and the two reach the answer through different helpers — so an example carrying only the docblock
 * leaves the attribute one unexercised.
 */
#[\Attribute]
final class Route
{
    public function __construct(public string $path = '') {}
}

namespace Symfony\Component\Security\Http\Attribute;

/** The attribute `RequireIsGrantedEnumRule` fires on. Its first argument is what the rule reads. */
#[\Attribute]
final class IsGranted
{
    public function __construct(public mixed $attribute = null) {}
}

namespace Symfony\Component\HttpFoundation;

/** The one parameter type `NoControllerMethodInjectionRule` allows an action to take. */
final class Request
{
    public function get(string $key): mixed
    {
        return null;
    }
}
