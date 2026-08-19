<?php

declare(strict_types=1);

namespace Examples\DependencyInjection;

final class TagCollectingPass
{
    public function process(object $container): void
    {
        $this->findTaggedServiceIds('app.handler');
    }

    /**
     * @return array<string, mixed>
     */
    public function findTaggedServiceIds(string $tag): array
    {
        return [];
    }
}
