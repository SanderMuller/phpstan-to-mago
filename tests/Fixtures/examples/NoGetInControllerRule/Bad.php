<?php

declare(strict_types=1);

namespace Examples\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class ProductController extends AbstractController
{
    public function index(): void
    {
        $this->get('product.repository');
    }
}
