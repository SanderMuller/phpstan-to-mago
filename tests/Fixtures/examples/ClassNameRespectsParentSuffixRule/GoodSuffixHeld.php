<?php

declare(strict_types=1);

namespace Examples\ClassNameRespectsParentSuffix;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** The suffix its class ancestor asks for, so the rule is satisfied. */
final class ProductController extends AbstractController {}

/** The same, through an interface ancestor. */
final class OrderEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [];
    }
}

/**
 * No listed ancestor at all, so no suffix is owed.
 *
 * The control for the table walk itself: a rule that reported here would be reporting on ancestry it
 * never matched.
 */
final class PlainHelper {}

/**
 * Abstract, which the rule skips before it ever reads the table.
 *
 * Named to violate the `Controller` suffix on purpose, so the guard is what keeps it quiet rather than
 * the name.
 */
abstract class AbstractProductThing extends AbstractController {}
