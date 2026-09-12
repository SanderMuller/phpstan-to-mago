<?php

declare(strict_types=1);

namespace Examples\Forms;

use Symfony\Component\Form\AbstractType;

/**
 * Three ways to be silent, and the third is the one that measures a dropped guard.
 *
 * The suffix is present on the first; the second does not extend the form base at all; and the third is an
 * anonymous class, which is the case the emitted plugin's dropped name guard filters out. PHPStan leaves
 * `namespacedName` null for it and returns early — the port never registers the kind, so the guard cannot
 * hold and is dropped. Silence here is what makes that a measurement rather than an argument.
 */
final class LoginFormType extends AbstractType {}

final class NotAForm {}

function anonymousForm(): AbstractType
{
    return new class extends AbstractType {};
}
