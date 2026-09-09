<?php

declare(strict_types=1);

namespace ReferenceShapes;

/**
 * Every position PHP writes `&` in, each beside a by-value control that differs in nothing else.
 *
 * Names are unique per site because the probe keys its rows on the variable or identifier it finds, and two
 * sites sharing a name would collapse into one row.
 */
final class ReferenceShapes
{
    /** @var list<int> */
    private array $store = [];

    /**
     * The bare pair carry no *native* hint on purpose -- that is the shape under test -- so their types are
     * declared in the docblock, which leaves the syntax mago reads untouched.
     *
     * @param int        $refBare
     * @param int        $valueBare
     * @param list<int>  $refHinted
     * @param list<int>  $valueHinted
     */
    public function paramByRef(&$refBare, $valueBare, array &$refHinted, array $valueHinted): void {}

    public function paramVariadic(int &...$refVariadic): void {}

    public function paramVariadicByValue(int ...$valueVariadic): void {}

    /** @return list<int> */
    public function &returnsByRef(): array
    {
        return $this->store;
    }

    /** @return list<int> */
    public function returnsByValue(): array
    {
        return $this->store;
    }

    public function assignments(): void
    {
        $assignTarget = 1;
        $refAssign = &$assignTarget;
        $valueAssign = $assignTarget;
        // A different unary prefix in the same position. Without it a predicate that only asks whether a
        // `UnaryPrefixOperator` is present passes every row here, and the mutation check proved it does.
        $negatedAssign = -$assignTarget;
    }

    public function arrayItems(): void
    {
        $refItem = 1;
        $valueItem = 1;
        $refArray = [&$refItem];
        $valueArray = [$valueItem];
        $negatedItem = 1;
        $negatedArray = [-$negatedItem];
    }

    /** @param list<int> $xs */
    public function loops(array $xs): void
    {
        foreach ($xs as &$refValue) {
        }

        foreach ($xs as $valueValue) {
        }
    }
}
