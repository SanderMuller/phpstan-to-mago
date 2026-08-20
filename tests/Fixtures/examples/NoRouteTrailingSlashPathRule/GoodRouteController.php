<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class GoodRouteController extends AbstractController
{
    /**
     * @Route("/products")
     */
    public function noTrailingSlash(): void {}

    /**
     * The root path is the one trailing slash the rule allows.
     *
     * @Route("/")
     */
    public function root(): void {}

    /** No route annotation at all, so there is no path to judge. */
    public function notARoute(): void {}

    /**
     * @Route("/private/")
     */
    private function notPublic(): void {}
}
