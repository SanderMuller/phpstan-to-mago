<?php

declare(strict_types=1);

namespace Examples\Rector\Php80;

/** A version-specific rule that does not say which PHP version it needs. */
final class BadPhp80Rector
{
    public function refactor(): void {}
}

/** A second one, so the pair shows the rule reports per declaration rather than once per file. */
final class AlsoMissingTheContractPhp80Rector
{
    public function refactor(): void {}
}
