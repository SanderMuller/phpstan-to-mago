<?php

declare(strict_types=1);

namespace Examples\Constructors;

/** The override that calls the parent, which is the whole of what the rule asks for. */
final class CallingRegistry extends Registry
{
    public function __construct()
    {
        parent::__construct();

        $this->entries[] = 'extra';
    }
}
