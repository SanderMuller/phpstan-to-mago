<?php

declare(strict_types=1);

namespace Maybe;

use Nowhere\Missing;

interface Target {}

class Resolvable implements Target {}

final class Descends extends Resolvable
{
    public function probe_descends(): void
    {
        probe_descends();
    }
}

final class Unrelated
{
    public function probe_unrelated(): void
    {
        probe_unrelated();
    }
}

/** Extends a class nothing in the source or the includes declares. */
final class Unresolvable extends Missing
{
    public function probe_unresolvable(): void
    {
        probe_unresolvable();
    }
}

function probe_descends(): void {}
function probe_unrelated(): void {}
function probe_unresolvable(): void {}
