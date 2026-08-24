<?php

declare(strict_types=1);

namespace Examples\Pattern\Php80;

/** Versioned, but the name does not end in `Rector`, so the second pattern stops it. */
final class RenameFooHelper {}

namespace Examples\Pattern\Plain;

/** Ends in `Rector`, but no `\PhpNN\` segment, so the first pattern stops it. */
final class RenameBarRector {}

namespace Examples\Pattern\Php8;

/** One digit rather than two: still `\Php\d+\`, and still not a `Rector`. */
final class Helper {}
