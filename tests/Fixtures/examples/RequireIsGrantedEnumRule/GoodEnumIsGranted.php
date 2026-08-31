<?php declare(strict_types=1);

namespace Examples\Security;

use Symfony\Component\Security\Http\Attribute\IsGranted;

enum Role: string
{
    case Admin = 'ROLE_ADMIN';
}

/**
 * The enum constant the rule asks for, plus an attribute of another name carrying a string — the rule gates
 * on the attribute's *resolved* name, so an example without that near miss says nothing about the gate.
 */
#[\Attribute]
final class Marker
{
    public function __construct(public string $note = '') {}
}

final class GoodEnumIsGranted
{
    #[IsGranted(Role::Admin)]
    public function admin(): void {}

    #[Marker('ROLE_ADMIN')]
    public function marked(): void {}
}
