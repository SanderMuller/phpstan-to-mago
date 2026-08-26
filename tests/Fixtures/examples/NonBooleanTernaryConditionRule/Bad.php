<?php

declare(strict_types=1);

namespace Examples\NonBooleanTernary;

final class BadTernaries
{
    /** An int condition, rendered into the message. */
    public function counted(int $count): string
    {
        return $count ? 'some' : 'none';
    }

    /** A generic, which is the shape `Type::__toString()` renders without its parameters. */
    public function listed(): string
    {
        $items = $this->items();

        return $items ? 'some' : 'none';
    }

    /** @return list<string> */
    private function items(): array
    {
        return ['a'];
    }
}
