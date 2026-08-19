<?php

declare(strict_types=1);

namespace Examples\Receivers;

use Vendor\Widget as VendorWidget;

final class Behaved
{
    public function __construct(
        private Widget $sure,
        private Widget|Gadget $union,
        private VendorWidget $vendor,
        private Collector $collector,
    ) {}

    public function go(array $rest): void
    {
        // Named, which is what the rule asks for.
        $this->sure->toggle(enabled: true);
        // Spread, so the argument position says nothing about the parameter position.
        $this->sure->toggle(...$rest);
        // Not a bare bool or null.
        $this->sure->toggle('true');
        // A union receiver is not one class, so no single parameter name can be suggested. Rejected by the
        // chain, but not by the `count($classReflections) !== 1` guard specifically: the helpers behind it are
        // null-tolerant, so the method lookup a step later already answers no. Removing that guard leaves this
        // pair green, which makes it a faithful translation of the original's control flow rather than a
        // load-bearing check here.
        $this->union->toggle(true);
        // The method is declared outside the first-party namespaces.
        $this->vendor->toggle(true);
        // The flag lands on a variadic parameter.
        $this->collector->gather('team', true);
        // One argument more than the method declares.
        $this->sure->reset(true);
    }
}

final class Gadget
{
    public function toggle(bool $enabled): void {}
}

final class Collector
{
    public function gather(string $to, bool ...$flags): void {}
}
