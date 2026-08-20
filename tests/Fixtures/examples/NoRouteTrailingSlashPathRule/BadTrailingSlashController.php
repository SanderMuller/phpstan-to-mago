<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class BadTrailingSlashController extends AbstractController
{
    /**
     * @Route("/products/")
     */
    public function list(): void {}
}
