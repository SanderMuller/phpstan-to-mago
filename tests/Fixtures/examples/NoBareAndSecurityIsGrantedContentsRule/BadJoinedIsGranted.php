<?php

declare(strict_types=1);

namespace Examples\Security;

use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BadJoinedIsGranted
{
    /** Two checks joined with `and`, which the rule asks to be two attributes. */
    #[IsGranted('is_granted("ROLE_ADMIN") and is_granted("ROLE_BILLING")')]
    public function admin(): void {}

    /** The same joined with `&&`, so the pair covers both spellings the rule looks for. */
    #[IsGranted('is_granted("ROLE_ADMIN") && has_role("ROLE_BILLING")')]
    public function billing(): void {}
}
