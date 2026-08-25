<?php

declare(strict_types=1);

namespace Examples\IllegalConstructor;

final class GoodCaller
{
    /** An ordinary method call. */
    public function reset(Subject $subject): void
    {
        $subject->reset();
    }

    /** A constructor reached the only way that is allowed. */
    public function make(): Subject
    {
        return new Subject(3);
    }
}
