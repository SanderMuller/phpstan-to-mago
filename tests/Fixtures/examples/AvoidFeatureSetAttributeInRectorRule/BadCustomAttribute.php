<?php

declare(strict_types=1);

namespace Examples\Refactors;

use PhpParser\Node;
use Rector\Rector\AbstractRector;

/**
 * Sets attributes of its own to be read later, which is the two-step decoration the rule exists to stop.
 *
 * Two of them, because the rule appends one finding per offending call and returns the list. One would pass
 * a port that reported once per class.
 */
/** Keys held on another class, the way Rector's own `AttributeKey` holds them. */
final class MarkerKeys
{
    public const string ELSEWHERE = 'marker_from_another_class';
}

final class CustomAttributeRector extends AbstractRector
{
    private const string MARKER = 'third_marker';

    private const UNTYPED_MARKER = 'fourth_marker';

    /**
     * A widening `@var` on a constant. PHPStan still reads the value; mago's inferred type honours the
     * docblock and reads plain `string`, so the port declined every key written this way — which is every
     * key `rector-src` uses, and the whole of a 0-of-9 disagreement on it.
     *
     * @var string
     */
    private const DOCBLOCKED_MARKER = 'fifth_marker';

    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function refactor(Node $node): ?Node
    {
        $node->setAttribute('feature_set_marker', true);
        $node->setAttribute('second_marker', 'x');
        $node->setAttribute(self::MARKER, 1);

        $decorate = static function (Node $inner): void {
            $inner->setAttribute('inside_a_closure', true);
            $inner->setAttribute(self::UNTYPED_MARKER, true);
        };
        $decorate($node);

        $node->setAttribute(MarkerKeys::ELSEWHERE, true);
        $node->setAttribute(self::UNTYPED_MARKER, true);
        $node->setAttribute(self::DOCBLOCKED_MARKER, true);

        return $node;
    }
}
