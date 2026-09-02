<?php

declare(strict_types=1);

namespace Examples\Rector\Php81;

use Rector\VersionBonding\Contract\MinPhpVersionInterface;

/** What the rule asks for: the contract is there, so the version is declared. */
final class GoodPhp81Rector implements MinPhpVersionInterface
{
    public function provideMinPhpVersion(): int
    {
        return 80100;
    }
}

/** A `Php\d+` namespace, but the name does not end in `Rector`, so there is no rule to judge. */
final class Php81Helper
{
    public function help(): void {}
}
