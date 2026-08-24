<?php

declare(strict_types=1);

namespace Examples\ImplementsNamed;

use Examples\Stubs\NamedByConstant;
use Examples\Stubs\NamedByLiteral;

/** Only the one the rule names by a written literal, so the second guard stops it. */
final class GoodLiteralOnly implements NamedByLiteral {}

/** Only the one the rule names through a constant, so the first guard stops it. */
final class GoodConstantOnly implements NamedByConstant {}

/** Neither. */
final class Good {}
