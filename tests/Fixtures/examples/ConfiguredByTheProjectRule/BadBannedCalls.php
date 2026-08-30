<?php

declare(strict_types=1);

namespace Examples\ProjectConfigured;

final class BadBannedCalls
{
    public function promotedList(): void
    {
        // `dump` is in the project's `banned` list, read from a promoted property.
        dump('x');
    }

    public function derivedMap(): void
    {
        // `VarDump` is in `alsoBanned`, which the rule keeps only as a lower-cased lookup table. The
        // project's container computed that table, and the plugin carries it as its default — so a
        // rendering that dropped the map's keys would leave this call unreported.
        vardump('x');
    }
}
