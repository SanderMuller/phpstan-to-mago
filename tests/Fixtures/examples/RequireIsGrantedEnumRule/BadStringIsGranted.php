<?php declare(strict_types=1);

namespace Examples\Security;

use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A role written as a string, which the rule asks to be an enum constant instead. */
final class BadStringIsGranted
{
    #[IsGranted('ROLE_ADMIN')]
    public function admin(): void {}

    // The same attribute with the argument named, which php-parser still puts at position 0.
    #[IsGranted(attribute: 'ROLE_EDITOR')]
    public function editor(): void {}
}
