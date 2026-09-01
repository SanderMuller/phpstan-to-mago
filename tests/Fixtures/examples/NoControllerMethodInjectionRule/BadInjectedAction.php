<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class Mailer
{
    public function send(string $to): void {}
}

/** A service taken through the action rather than the constructor, which is what the rule reports. */
final class InjectedActionController extends AbstractController
{
    public function send(Mailer $mailer): void {}

    // Reported too, and separately: the rule reports once per offending parameter.
    public function resend(Mailer $mailer): void {}
}
