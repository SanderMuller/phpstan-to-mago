<?php

declare(strict_types=1);

namespace Examples\Mapping;

final class Doubler
{
    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    public function double(array $values): array
    {
        return array_map(static fn (int $value): int => $value * 2, $values);
    }

    /**
     * `array_map(...)` is a first-class callable, which the original rule bails on through
     * `isFirstClassCallable()`. The transpiler drops that guard, claiming Mago parses this as a partial
     * application that never reaches a call hook. If that claim is wrong, the port reports here.
     */
    public function mapper(): callable
    {
        return array_map(...);
    }

    /**
     * The array callable is here, but written as a named argument so it is not the *first* argument.
     *
     * The rule reads `$node->getArgs()[0]->value` and asks whether it is an array literal. PHPStan hands back
     * the arguments as written rather than in declared order — nothing in this rule calls
     * `ArgumentsNormalizer` — so argument zero is `array: $values` and the rule stays quiet, even though the
     * call does pass an array callable. The port reads the written order too, which is why both agree.
     *
     * Here as a control rather than as a wish: three rules in the corpus refuse because they *do* normalize,
     * and the helper that would serve them must not be reached for the ones that do not. If argument reading
     * is ever made order-aware by default, this case reports and the pair fails.
     *
     * @param list<int> $values
     *
     * @return list<int>
     */
    public function namedOutOfOrder(array $values): array
    {
        return array_map(array: $values, callback: [$this, 'twice']);
    }

    public function twice(int $value): int
    {
        return $value * 2;
    }
}
