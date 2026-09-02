<?php

declare(strict_types=1);

namespace Examples\Listeners;

/** A Doctrine lifecycle method on a `*Listener` that declares no contract, which is wired in config instead. */
final class BadProductListener
{
    public function postPersist(): void {}
}

/** A second one, on an ODM event, so the pair covers both lists the analyser reads. */
final class BadDocumentListener
{
    public function postCollectionLoad(): void {}
}
