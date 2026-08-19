<?php

declare(strict_types=1);

namespace Examples\Command;

use Symfony\Component\Console\Command\Command;

final class ImportCommand extends Command
{
    public function run(): void
    {
        $this->get('importer');
    }
}
