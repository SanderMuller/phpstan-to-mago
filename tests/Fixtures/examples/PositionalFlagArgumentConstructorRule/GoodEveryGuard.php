<?php

declare(strict_types=1);

namespace App\Widgets;

use Vendor\Widget;

final class GoodEveryGuard
{
    public function __construct(public bool $enabled = false, public ?string $label = null) {}

    public function named(): self
    {
        return new self(enabled: true);
    }

    public function notABool(): self
    {
        return new self(false, 'label');
    }

    public function spread(): self
    {
        $arguments = [true];

        return new self(...$arguments);
    }

    public function noArguments(): self
    {
        return new self();
    }

    public function thirdParty(): Widget
    {
        // The constructor is declared outside the first-party namespaces, so the rule does not ask about it.
        return new Widget(true);
    }
}
