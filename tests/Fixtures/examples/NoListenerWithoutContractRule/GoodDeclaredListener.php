<?php

declare(strict_types=1);

namespace Examples\Events;

use Examples\Contracts\LocalisedContract;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Security\Http\Firewall\AbstractListener;

/** The attribute, which is one of the two things the rule accepts. */
#[AsEventListener]
final class GoodAttributeListener
{
    public function onKernelRequest(): void {}
}

/** A declared contract, which is the other. */
final class GoodContractListener implements LocalisedContract
{
    public function locale(): string
    {
        return 'nl';
    }
}

/** Invokable, so the rule reads it as a listener wired by its own signature. */
final class GoodInvokableListener
{
    public function __invoke(): void {}
}

/** A security listener, which the rule allows by its parent. */
final class GoodSecurityListener extends AbstractListener
{
    public function onKernelRequest(): void {}
}

/** A form listener, which the rule allows by a parameter type. */
final class GoodFormListener
{
    public function onPreSubmit(PreSubmitEvent $event): void {}
}

/** A Doctrine lifecycle method, which the Doctrine sibling handles and this rule skips. */
final class GoodDoctrineListener
{
    public function postPersist(): void {}
}

/** Not named `*Listener`, so there is nothing to judge. */
final class RequestSubscriber
{
    public function onKernelRequest(): void {}
}
