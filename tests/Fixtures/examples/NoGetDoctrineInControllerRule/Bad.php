<?php

declare(strict_types=1);

namespace Examples\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class InvoiceController extends AbstractController
{
    public function index(): void
    {
        $this->getDoctrine();
    }
}
