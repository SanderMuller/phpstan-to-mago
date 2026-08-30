<?php

declare(strict_types=1);

namespace Examples\ProjectConfigured;

/**
 * The functions the examples call, declared here so both tools resolve them.
 *
 * The rule reads the written name rather than a resolved one, so these only have to exist.
 */
function dump(string $value): void {}

function vardump(string $value): void {}
