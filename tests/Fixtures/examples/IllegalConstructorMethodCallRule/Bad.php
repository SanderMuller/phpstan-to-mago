<?php

declare(strict_types=1);

namespace Examples\IllegalConstructor;

final class Subject
{
    public function __construct(public int $value = 0) {}

    public function reset(): void {}
}

final class BadCaller
{
    /** Calling `__construct()` on an object that already exists, which is what the rule forbids. */
    public function reinitialise(Subject $subject): void
    {
        $subject->__construct(1);
    }

    /**
     * The same call, differently cased.
     *
     * PHP compares method names case-insensitively and the rule folds — `->toLowerString() !== '__construct'`
     * — so PHPStan reports this line. The port emitted the case-*sensitive* selector comparison and was
     * silent on it. The file is in pint's `notPath`, because `magic_method_casing` rewrites the spelling and
     * the suite then stays green with the case no longer exercised.
     */
    public function shouted(Subject $subject): void
    {
        $subject->__CONSTRUCT(2);
    }
}
