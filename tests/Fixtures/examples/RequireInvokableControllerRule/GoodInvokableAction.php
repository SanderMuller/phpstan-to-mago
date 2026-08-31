<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The invokable shape the rule asks for, plus the two near misses that must stay silent: a non-public method
 * carrying a route, which the analyzer refuses before it looks at the route at all, and a public method with
 * no route on it.
 */
final class GoodInvokableController extends AbstractController
{
    #[Route('/orders')]
    public function __invoke(): void {}

    /**
     * @Route("/private")
     */
    private function helper(): void {}

    public function notAnAction(): void {}
}

/** Not a controller, so its named action is none of the rule's business. */
final class NotAControllerEither
{
    #[Route('/elsewhere')]
    public function list(): void {}
}
