<?php

declare(strict_types=1);

namespace Examples\Subscribers;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** A string event name, which the rule asks to be a class constant instead. */
final class StringKeyedSubscriber implements EventSubscriberInterface
{
    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => 'onRequest',
        ];
    }

    public function onRequest(): void {}
}
