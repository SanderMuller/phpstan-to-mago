<?php

declare(strict_types=1);

namespace Examples\Constructors\Polyfill;

/** A parent that does declare a constructor, so the first declaration below has one to override. */
class Base
{
    public function __construct(public readonly int $size) {}
}

/**
 * One name, two declarations, guarded by a runtime condition — the shape `nikic/php-parser` writes for
 * `TokenPolyfill`, and the one that made this rule's port report where PHPStan does not.
 *
 * The first declaration extends a class with a constructor; the second, written below it, extends nothing.
 * PHPStan asks the scope for the class the node is in, which is the second, so there is no parent and no
 * override. A port that asks the *codebase* for the name gets whichever declaration the metadata holds —
 * here the first — and reports a constructor that overrides nothing.
 *
 * Found by the corpus differential rather than by reading: one disagreement against 111 agreements on
 * `nikic/php-parser`.
 */
if (\PHP_VERSION_ID >= 80000) {
    class Token extends Base {}

    return;
}

class Token
{
    public int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}
