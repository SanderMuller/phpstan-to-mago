<?php

declare(strict_types=1);

namespace Examples\Controllers;

/** The same class without a constructor, which is what the rule asks for. */
abstract class GoodAbstractBaseController
{
    public function autowire(string $locale): void {}
}

/** Abstract, and named `Controller`, but declaring only an inherited-looking method — no constructor. */
abstract class AnotherAbstractController
{
    abstract public function handle(): void;
}

/** A constructor, but the class is not abstract — the rule's first guard. */
final class ConcreteController
{
    public function __construct(private readonly string $locale) {}
}

/** Abstract with a constructor, but not named `*Controller` — the rule's suffix guard. */
abstract class AbstractService
{
    public function __construct(private readonly string $locale) {}
}
