<?php

declare(strict_types=1);

namespace Examples\Scopes;

/**
 * A plain function, which the hook fires for and the guard declines. This is the file that separates a folded
 * guard from a translated one: fold `isInClass()` to true and this gets reported.
 */
function outside(): void {}
