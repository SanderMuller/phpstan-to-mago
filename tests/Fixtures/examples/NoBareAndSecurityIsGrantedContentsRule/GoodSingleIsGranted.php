<?php

declare(strict_types=1);

namespace Examples\Security;

use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GoodSingleIsGranted
{
    /** What the rule asks for: one check per attribute. */
    #[IsGranted('is_granted("ROLE_ADMIN")')]
    public function admin(): void {}

    /** An `or` join, which the rule leaves alone because splitting it would change the meaning. */
    #[IsGranted('is_granted("ROLE_ADMIN") or is_granted("ROLE_BILLING")')]
    public function either(): void {}

    /**
     * Joined with `and`, but one side is an expression the rule cannot split into attributes.
     *
     * This is the guard the runtime split exists for: every piece has to be an `is_granted` or `has_role`
     * call before the rule asks for two attributes.
     */
    #[IsGranted('is_granted("ROLE_ADMIN") and user.isVerified()')]
    public function verified(): void {}
}
