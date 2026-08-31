<?php

declare(strict_types=1);

namespace Examples\Controllers;

/** An abstract `*Controller` with a constructor, which every child then has to remember to call. */
abstract class BadAbstractBaseController
{
    public function __construct(private readonly string $locale) {}
}
