<?php

declare(strict_types=1);

/**
 * Global functions the examples call.
 *
 * Separate from `Framework.php` because that file declares unbracketed namespaces, and PHP does not let a
 * file mix those with the bracketed form a global declaration needs after them.
 */

/**
 * Laravel's global request helper, for `CombinedFuncCallRule`.
 *
 * That rule asks the reflection provider whether the called name resolves to a function named `request`, so
 * the name alone is not enough: with no declaration anywhere both tools decline the check, and the example
 * pair would be green for the wrong reason.
 */
function request(?string $key = null, mixed $default = null): mixed
{
    return $default;
}
