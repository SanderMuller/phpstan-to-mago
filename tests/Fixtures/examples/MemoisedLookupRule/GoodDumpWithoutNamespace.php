<?php

declare(strict_types=1);

// No namespace, so the memoised lookup answers null and the rule declines.

function goodDumpWithoutNamespace(array $rows): void
{
    dump($rows);
}

function goodNotADump(array $rows): int
{
    return count($rows);
}
