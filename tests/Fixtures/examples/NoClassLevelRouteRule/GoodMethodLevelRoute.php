<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/** Routes on the methods, which is what the rule asks for. */
final class GoodMethodLevelRoute extends AbstractController
{
    #[Route('/products')]
    public function list(): void {}

    /**
     * @Route("/products/{id}")
     */
    public function show(): void {}
}

/**
 * @Route("/not-a-controller")
 */
final class NotAControllerAtAll
{
    public function list(): void {}
}
