<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Sandermuller\PhpstanToMago\Tests\Fixtures\Doubles\ReportingFacade;

/**
 * The alias half a consumer owns, in the shape every Laravel project writes it.
 *
 * `Facade::defaultAliases()` gives the framework's own 46 and a worker can call it, but it cannot see this
 * `merge()` — evaluating a config file needs the application. So the merged entries are read from source,
 * which is where they are, and this file is what proves that half runs.
 */
return [
    'aliases' => Facade::defaultAliases()->merge([
        'Reporting' => ReportingFacade::class,
    ])->all(),
];
