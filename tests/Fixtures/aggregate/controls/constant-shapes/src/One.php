<?php declare(strict_types=1);

namespace Control;

/**
 * Every shape the constant collector treats differently, in one class.
 *
 * `WRITTEN` carries a native type. `PLAIN` carries none. `GROUPED_A, GROUPED_B` is one `ClassConst` statement
 * and counts once. `INHERITED` redeclares the parent's, which the collector treats as typed without reading a
 * type at all. `ON_INTERFACE` and `FROM_TRAIT` are listed on this class by the codebase and counted on their
 * own declarations instead.
 */
final class One extends Base implements Contract
{
    use Shared;

    public const string WRITTEN = 'a';

    public const PLAIN = 'b';

    public const GROUPED_A = 'c', GROUPED_B = 'd';

    public const INHERITED = 'redeclared';
}
