<?php

declare(strict_types=1);

namespace Examples\Cached;

final class BadDumpInNamespace
{
    public function report(array $rows): void
    {
        dump($rows);
    }
}
