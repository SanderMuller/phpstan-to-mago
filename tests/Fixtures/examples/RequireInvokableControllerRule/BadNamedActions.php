<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Two named action methods, one reached through each half of the route question, so the attribute branch and
 * the docblock branch are both exercised.
 */
final class BadNamedActionsController extends AbstractController
{
    #[Route('/orders')]
    public function list(): void {}

    /**
     * @Route("/orders/{id}")
     */
    public function show(): void {}
}
