<?php

declare(strict_types=1);

namespace Examples\Visibility;

/** The class a `@mixin` points at, declaring the method as public. */
class VisibilityMixinSource
{
    public function mixedIn(): void {}
}

/**
 * A parent that declares nothing and has a public `mixedIn()` anyway.
 *
 * @mixin VisibilityMixinSource
 */
class MixedInParent {}

/**
 * Narrowing the visibility of a method only a `@mixin` puts on the parent.
 *
 * The original reads the parent reflection, which answers through PHPStan's core mixin extension, so it
 * reports here. A port that resolves only written and inherited methods finds no parent method, takes the
 * `continue`, and stays silent — a false negative, and the opposite direction to the false positive the same
 * gap caused in `NoProtectedClassStmtRule`.
 *
 * Legal PHP for the same reason the sibling fixture is: there is no real parent declaration to narrow.
 */
final class BadMixedInVisibility extends MixedInParent
{
    protected function mixedIn(): void {}
}
