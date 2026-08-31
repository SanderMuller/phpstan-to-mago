<?php declare(strict_types=1);

namespace Control;

/**
 * The four ways a property is or is not typed to the original.
 *
 * `$written` has a native type. `$inherited` redeclares one the parent already has, which the collector
 * guards out of the missing list. `$deferred` has a docblock naming `callable`, which the original treats as
 * covered because it cannot be written natively. `$docblocked` has a `@var int` and is **untyped** — the
 * check is a substring test for `callable` or `resource`, not a test for a docblock type, which its name
 * suggests and its body does not do.
 */
final class Subject extends Base
{
    public string $written = '';

    public $inherited;

    /** @var callable */
    public $deferred;

    /** @var int */
    public $docblocked;
}
