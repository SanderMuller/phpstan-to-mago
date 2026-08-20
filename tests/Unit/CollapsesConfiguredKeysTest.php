<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\Support;

/**
 * `Support::foldedKeys()` — the stand-in for a rewriting the rule does and the plugin does not.
 *
 * `TraitRequiresInterfaceRule` builds its map keyed by each configured name's *declared* spelling, so two keys
 * naming one trait in different cases become a single pair. The transpiler drops that pass and carries the
 * configured map instead; carrying it as written kept both keys, and the case-insensitive match at the use site
 * then found both — reporting the same finding twice, which is the port being wider than the rule.
 *
 * Not covered by the fires-gate on purpose. A case-colliding configuration also makes the *message* diverge:
 * PHPStan prints the declared spelling and the port prints whichever the configuration wrote, and Mago's class
 * store answers nothing for a trait name so there is no declared spelling to recover. That divergence is
 * recorded rather than tested; this collapse is the part that can be.
 */
final class CollapsesConfiguredKeysTest extends TestCase
{
    public function test_keys_differing_only_in_case_become_one_entry(): void
    {
        $folded = Support::foldedKeys([
            'Examples\\Concerns\\Localised' => 'Examples\\Contracts\\LocalisedContract',
            'examples\\concerns\\localised' => 'Examples\\Contracts\\LocalisedContract',
            'Examples\\Concerns\\Menu' => 'Examples\\Contracts\\MenuContract',
        ]);

        $this->assertCount(2, $folded, 'The two spellings of one trait have to collapse, as the rule collapses them.');
        $this->assertSame('Examples\\Contracts\\MenuContract', $folded['Examples\\Concerns\\Menu']);
    }

    /** A leading separator is not a different name, so it must not survive as a second entry. */
    public function test_a_leading_separator_does_not_make_a_second_entry(): void
    {
        $this->assertCount(1, Support::foldedKeys([
            'Examples\\Concerns\\Localised' => 'Examples\\Contracts\\LocalisedContract',
            '\\Examples\\Concerns\\Localised' => 'Examples\\Contracts\\LocalisedContract',
        ]));
    }

    /** The later pair wins, which is what assigning into the same key does in the rule. */
    public function test_the_later_pair_wins(): void
    {
        $folded = Support::foldedKeys([
            'Examples\\Concerns\\Localised' => 'Examples\\Contracts\\First',
            'examples\\concerns\\localised' => 'Examples\\Contracts\\Second',
        ]);

        $this->assertSame(['Examples\\Contracts\\Second'], array_values($folded));
    }

    /** Nothing to collapse leaves the map exactly as configured. */
    public function test_distinct_keys_are_untouched(): void
    {
        $map = [
            'Examples\\Concerns\\Localised' => 'Examples\\Contracts\\LocalisedContract',
            'Examples\\Concerns\\Menu' => 'Examples\\Contracts\\MenuContract',
        ];

        $this->assertSame($map, Support::foldedKeys($map));
    }
}
