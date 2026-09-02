<?php

declare(strict_types=1);

namespace Examples\Wiring;

/** Constructor injection only, which is what the rule asks for. */
final class GoodConstructorOnly
{
    public function __construct(private readonly string $locale) {}

    public function setTranslator(string $translator): void {}
}

/** Setter injection only: no constructor, so there is nothing to pick between. */
final class GoodRequiredOnly
{
    /**
     * @required
     */
    public function setTranslator(string $translator): void {}
}

/**
 * Both styles, but the docblock says why — the exception the rule writes in to avoid circular references.
 *
 * This is the guard the trailing `return true` sits behind, so it is what says the last of the four `continue`
 * guards still runs.
 */
final class GoodCircularException
{
    public function __construct(private readonly string $locale) {}

    /**
     * @required
     *
     * Set here rather than in the constructor to break a circular reference.
     */
    public function setTranslator(string $translator): void {}
}

/** A constructor and a non-public `@required` method, which the visibility guard skips. */
final class GoodPrivateRequired
{
    public function __construct(private readonly string $locale) {}

    /**
     * @required
     */
    private function setInternal(string $internal): void {}
}

/** A constructor and a setter with no docblock at all, which the docblock guard skips. */
final class GoodNoDocblock
{
    public function __construct(private readonly string $locale) {}

    public function setPlain(string $plain): void {}
}
