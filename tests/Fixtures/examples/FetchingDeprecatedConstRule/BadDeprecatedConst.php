<?php

declare(strict_types=1);

namespace Examples\Deprecations;

final class BadDeprecatedConst
{
    /**
     * Two constants PHP itself deprecated, read from inside a namespace.
     *
     * The namespace is deliberate. Mago resolves an unqualified constant to `Examples\Deprecations\E_STRICT`
     * first, which the codebase does not hold, so a lookup that stopped there would find nothing and this
     * rule would report nothing in any namespaced file -- which is all real code.
     */
    public function read(): string
    {
        return FILTER_SANITIZE_STRING . E_STRICT;
    }
}
