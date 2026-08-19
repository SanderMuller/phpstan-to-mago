<?php

declare(strict_types=1);

namespace Examples\Controller;

use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class CreditController extends AbstractController
{
    public function __construct(private readonly EntityManager $entityManager) {}

    public function index(): void
    {
        $this->entityManager->getRepository('Invoice');
    }
}
