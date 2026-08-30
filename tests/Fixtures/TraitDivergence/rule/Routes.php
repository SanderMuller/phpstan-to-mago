<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * The same violation a controller can hold directly, moved into a trait two controllers use.
 *
 * `NoRouteTrailingSlashPathRule` gates on the enclosing class being a controller, so this is where the
 * method hook's trait behaviour stops being an attribution difference and starts costing findings: PHPStan
 * asks the question of `FirstController` and `SecondController`, the port asks it of the trait, which
 * extends nothing.
 */
trait ListsProductsTrait
{
    /**
     * @Route("/products/")
     */
    public function list(): void {}
}

final class FirstController extends AbstractController
{
    use ListsProductsTrait;
}

final class SecondController extends AbstractController
{
    use ListsProductsTrait;
}

/** The control. The same violation written in a class, which both engines report. */
final class DirectController extends AbstractController
{
    /**
     * @Route("/orders/")
     */
    public function orders(): void {}
}
