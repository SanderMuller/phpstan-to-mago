<?php

declare(strict_types=1);

namespace Examples\Events;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class OrderShipper
{
    public function __construct(private readonly EventDispatcher $eventDispatcher) {}

    public function ship(object $event): void
    {
        $this->eventDispatcher->dispatch($event);
    }
}

/**
 * A dispatcher that forwards to itself, which the rule does *not* report however many arguments it passes.
 *
 * `$this` is a `ThisType` to PHPStan, and `ThisType extends StaticType` without extending `ObjectType` — so
 * the rule's `! $callerType instanceof ObjectType` guard bails before it counts the arguments. Mago types
 * `$this` as a named object like any other, and the port reported this line on Shopware where PHPStan is
 * silent.
 */
final class SelfForwardingDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->dispatch($event, $eventName);

        return $event;
    }
}
