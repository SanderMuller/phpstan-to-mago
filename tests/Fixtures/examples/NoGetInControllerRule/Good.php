<?php

declare(strict_types=1);

namespace Examples\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class OrderController extends AbstractController
{
    public function __construct(private readonly object $repository) {}

    public function index(): void
    {
        $this->repository->findAll();
    }
}
