<?php

declare(strict_types=1);

namespace Examples\Subscribers;

/** The same method on a class that implements nothing, which the rule does not look at. */
final class NotASubscriber
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
