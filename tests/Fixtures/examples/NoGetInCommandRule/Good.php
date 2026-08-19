<?php

declare(strict_types=1);

namespace Examples\Command;

use Symfony\Component\Console\Command\Command;

final class ExportCommand extends Command
{
    public function __construct(private readonly object $exporter) {}

    public function run(): void
    {
        $this->exporter->export();
    }
}
