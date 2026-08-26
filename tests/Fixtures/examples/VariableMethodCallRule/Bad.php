<?php

declare(strict_types=1);

namespace Examples\VariableMethodCall;

interface Alpha
{
    public function known(): void;
}

interface Beta {}

final class Thing implements Alpha
{
    public function known(): void {}
}

final class BadCaller
{
    /** A plain class receiver, which both tools render as its name. */
    public function plain(Thing $thing, string $method): void
    {
        $thing->$method();
    }

    /**
     * An intersection receiver, which is the divergence the renderer exists for.
     *
     * `Type::__toString()` prints only the first member; PHPStan prints both joined with `&`, and the message
     * is what a reader sees. 6395 of the 22868 differing types on a real corpus are this shape.
     *
     * @param Alpha&Beta $both
     */
    public function intersected(object $both, string $method): void
    {
        $both->$method();
    }

    /** A nullable class receiver: the member order PHPStan uses, class first. */
    public function nullable(?Thing $thing, string $method): void
    {
        $thing->$method();
    }
}
