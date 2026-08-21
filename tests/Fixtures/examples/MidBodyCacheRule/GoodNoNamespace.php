<?php

declare(strict_types=1);

// No namespace, so the question the cache memoised answers null and the helper declines.

function goodNoNamespace(array $rows): void
{
    dump($rows);
}

function goodNotADump(array $rows): int
{
    return count($rows);
}
