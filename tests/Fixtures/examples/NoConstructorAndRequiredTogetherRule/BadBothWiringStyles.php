<?php

declare(strict_types=1);

namespace Examples\Wiring;

/** Constructor injection and setter injection in one class, which the rule asks to be one or the other. */
final class BadBothWiringStyles
{
    public function __construct(private readonly string $locale) {}

    /**
     * @required
     */
    public function setTranslator(string $translator): void {}
}

/** A second one, so the pair shows the rule reports per declaration rather than once per file. */
final class AlsoBothWiringStyles
{
    public function __construct(private readonly string $locale) {}

    /**
     * @required
     */
    public function setFormatter(string $formatter): void {}
}
