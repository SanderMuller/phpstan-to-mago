<?php declare(strict_types=1);

namespace Control;

/**
 * A grouped declaration whose first default holds a statement boundary.
 *
 * `public $a = 'x;y', $b;` is one `Property` node to the collector. This finds the statement each metadata
 * name belongs to by scanning the source back to the previous `;` or brace, and the quoted one reads as the
 * end of a statement — so the group counts twice.
 *
 * Blanking string literals before the scan fixes this control and costs 23 declarations on one real
 * consumer and 19 on the other, because an apostrophe in a comment opens a quote that never closes. Handling
 * comments as well is the real fix and is more than this case has earned: the over-count needs a grouped
 * declaration *and* a boundary character inside its default, and neither consumer holds one.
 */
final class Subject
{
    public $first = 'x;y', $second;

    public string $typed = '';
}
