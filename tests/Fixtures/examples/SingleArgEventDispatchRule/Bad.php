<?php

declare(strict_types=1);

namespace Examples\Events;

use Symfony\Component\EventDispatcher\EventDispatcher;

final class OrderPlacer
{
    public function __construct(private readonly EventDispatcher $eventDispatcher) {}

    public function place(object $event): void
    {
        $this->eventDispatcher->dispatch($event, 'order.placed');
    }
}
