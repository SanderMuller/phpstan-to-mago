<?php

declare(strict_types=1);

namespace Examples\ImplementsNamed;

use Examples\Stubs\NamedByConstant;
use Examples\Stubs\NamedByLiteral;

/** Implements both, so both guards hold and the rule reports. */
final class Bad implements NamedByConstant, NamedByLiteral {}
