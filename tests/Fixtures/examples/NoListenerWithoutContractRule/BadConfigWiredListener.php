<?php

declare(strict_types=1);

namespace Examples\Events;

/** A `*Listener` with no contract, no attribute and no form event, so it can only be wired in config. */
final class BadConfigWiredListener
{
    public function onKernelRequest(): void {}
}

/** A second one, so the pair shows the rule reports per declaration rather than once per file. */
final class AlsoConfigWiredListener
{
    public function onKernelResponse(): void {}
}
