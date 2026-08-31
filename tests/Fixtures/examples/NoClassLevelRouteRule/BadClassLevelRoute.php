<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * @Route("/products")
 */
final class BadAnnotatedClassLevelRoute extends AbstractController
{
    public function list(): void {}
}
