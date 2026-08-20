<?php

declare(strict_types=1);

namespace Examples\Paths;

final class GoodEveryGuard
{
    /** The file exists, which is the whole question the rule asks. */
    public function existing(): string
    {
        return __DIR__ . '/BadMissingFile.php';
    }

    /** A wildcard cannot be resolved to one path, and the rule skips it. */
    public function glob(): string
    {
        return __DIR__ . '/*.php';
    }

    /** Not one of the suffixes the rule checks. */
    public function otherSuffix(): string
    {
        return __DIR__ . '/nothing-here.txt';
    }

    /** The left operand is not `__DIR__`, so there is no absolute path to check. */
    public function relative(): string
    {
        return 'config' . '/nothing-here.php';
    }

    /**
     * A different binary operator over the same two operands.
     *
     * php-parser has a node class per operator, so a rule registered for `Concat` never sees this. Mago has one
     * `Binary` kind with the operator as a child, so the emitted hook does — and without the operator gate it
     * would report here, where PHPStan is silent. This is what makes that gate tested rather than asserted.
     */
    public function comparison(): bool
    {
        return __DIR__ === '/nothing-here.php';
    }
}
