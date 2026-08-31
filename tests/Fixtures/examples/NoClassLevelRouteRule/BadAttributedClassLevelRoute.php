<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/** The attribute half, which the docblock example leaves unexercised. */
#[Route('/orders')]
final class BadAttributedClassLevelRoute extends AbstractController
{
    public function list(): void {}
}
