<?php

declare(strict_types=1);

namespace Examples\Enum;

/**
 * A real PHP enum, which `EnumAnalyzer` excludes: it requires `instanceof Class_`.
 *
 * The plugin registers the Enum target, so that exclusion has to be a runtime guard. Folded away as "never
 * happens", it would report here and the port would be wider than the rule.
 */
enum GoodRealEnumIsExcluded: string
{
    public const string ONE = 'same';

    public const string TWO = 'same';

    case First = 'first';
}
