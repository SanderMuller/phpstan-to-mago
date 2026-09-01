<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * The shape the rule asks for, plus every near miss its guards turn on: a `Request` parameter, which is
 * allowed by name; a parameterless action; a private method, which the visibility guard skips; and a magic
 * method that is not `__invoke`.
 */
final class ConstructorInjectionController extends AbstractController
{
    public function __construct(private readonly Mailer $mailer) {}

    public function send(Request $request): void {}

    public function list(): void {}

    private function helper(Mailer $mailer): void {}

    public function __call(string $name, array $arguments): void {}
}

/** Not a `*Controller`, so its injected action is none of the rule's business. */
final class MailerService
{
    public function send(Mailer $mailer): void {}
}
