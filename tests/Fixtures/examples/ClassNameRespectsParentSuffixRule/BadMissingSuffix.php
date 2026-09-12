<?php

declare(strict_types=1);

namespace Examples\ClassNameRespectsParentSuffix;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A *class* ancestor: descending from `AbstractController` owes the `Controller` suffix.
 */
final class ProductListing extends AbstractController {}

/**
 * An *interface* ancestor: implementing `EventSubscriberInterface` owes the `EventSubscriber` suffix.
 *
 * The pair above and this one vary one axis — whether the ancestor is reached by `extends` or by
 * `implements` — because PHPStan's `ClassReflection::is()` covers both and a port built on a
 * parent-class walk would answer only the first. Without this case a class-only ancestry bug passes
 * the gate green.
 */
final class OrderNotifier implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [];
    }
}
