<?php declare(strict_types=1);

namespace Control;

/**
 * A backed enum, which PHP gives three methods nobody wrote.
 *
 * `cases()` on any enum, plus `from()` and `tryFrom()` on a backed one. The collectors here visit
 * `ClassMethod` nodes, and the language writes none for these — so the original counts only the method the
 * file declares.
 */
enum Suit: string
{
    case Hearts = 'H';
    case Spades = 'S';

    public function label(): string
    {
        return $this->name;
    }
}
