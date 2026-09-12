<?php

declare(strict_types=1);

namespace Examples\Subscribers;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvents;

/**
 * Class-constant keys, which the rule skips — every one of them.
 *
 * `FormEvents::PRE_SUBMIT` is in the rule's own skip list and `RequestEvent::class` is not, and both are
 * silent: the block that tests them ends in an unconditional `continue`, so a class-constant key is skipped
 * whatever the four tests above it answer. Both spellings are here because that is the fold being asserted.
 */
final class ConstantKeyedSubscriber implements EventSubscriberInterface
{
    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'onRequest',
            FormEvents::PRE_SUBMIT => 'onSubmit',
            RequestEvent::NAME => 'onNamed',
            // The priority shape every real subscriber uses, and the case that separates "has a key" from
            // "is not a name": both inner elements are written without one, the search finds them like any
            // other element, and the original skips them on `! $arrayItem->key instanceof Expr`.
            AnotherEvent::class => ['onAnother', 10],
        ];
    }

    public function onRequest(): void {}

    public function onSubmit(): void {}

    public function onNamed(): void {}

    public function onAnother(): void {}
}

/** An event of the project's own, so the skip is not about Symfony's list. */
final class RequestEvent
{
    public const string NAME = 'request.event';
}

/** A second one, for the priority shape above. */
final class AnotherEvent {}
