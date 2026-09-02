<?php

declare(strict_types=1);

namespace Examples\Listeners;

use Examples\Contracts\LocalisedContract;

/** What the rule asks for: the class declares a contract, so the events are visible in the class itself. */
final class GoodProductListener implements LocalisedContract
{
    public function postPersist(): void {}

    public function locale(): string
    {
        return 'nl';
    }
}

/** A `*Listener` with no Doctrine event method, so there is nothing wired in config to find. */
final class GoodAuditListener
{
    public function record(): void {}
}

/** A Doctrine event method on a class not named `*Listener`, which the suffix guard skips. */
final class ProductSubscriber
{
    public function postPersist(): void {}
}
