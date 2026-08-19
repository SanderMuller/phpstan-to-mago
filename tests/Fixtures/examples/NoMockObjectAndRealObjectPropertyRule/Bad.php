<?php

declare(strict_types=1);

namespace Examples\Tests;

use PHPUnit\Framework\MockObject\MockObject;

final class MixedProperty
{
    private MockObject|Gateway $gateway;
}

final class Gateway {}
