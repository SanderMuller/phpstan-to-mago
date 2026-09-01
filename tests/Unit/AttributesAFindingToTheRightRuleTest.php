<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;

/**
 * Which rule a reported code belongs to, when one identifier is a prefix of another.
 *
 * The corpus differential filters both engines' findings by identifier, and a substring test is true of a
 * shorter identifier for a code carrying a longer one. Five such pairs exist across the corpora — four
 * `phpunit.covers*` and one in `symplify` — and the `symplify` pair actually fired: the namespace rule's
 * finding was filed under the *name* rule, landed on the same site as that rule's own finding, and was
 * counted as an agreement. One rule's corpus number stood on another rule's work.
 *
 * Pinned here rather than only in the instrument, because the failure is silent in both directions: the rule
 * that gained the finding reads as agreeing and the one that lost it reads as under-reporting, and neither
 * says anything is wrong.
 */
final class AttributesAFindingToTheRightRuleTest extends TestCase
{
    /** @var list<string> */
    private const array IDENTIFIERS = [
        'symplify.requireAttributeName',
        'symplify.requireAttributeNamespace',
        'phpunit.covers',
        'phpunit.coversClass',
    ];

    public function test_the_longer_identifier_wins_over_the_prefix_it_contains(): void
    {
        $this->assertSame(
            'symplify.requireAttributeNamespace',
            CorpusDifferential::identifierFor(
                'transpiled/require-attribute-namespace-rule/symplify.requireAttributeNamespace',
                self::IDENTIFIERS,
            ),
        );

        $this->assertSame(
            'phpunit.coversClass',
            CorpusDifferential::identifierFor('transpiled/x/phpunit.coversClass', self::IDENTIFIERS),
        );
    }

    public function test_the_prefix_still_wins_its_own_code(): void
    {
        $this->assertSame(
            'symplify.requireAttributeName',
            CorpusDifferential::identifierFor(
                'transpiled/require-attribute-name-rule/symplify.requireAttributeName',
                self::IDENTIFIERS,
            ),
        );
    }

    /**
     * A code the identifier only *starts* still matches, which is why the test is a substring one.
     *
     * `NoDebugInNamespaceRule` reports under `'hihaho.debug.noDebugIn' . $namespace`, so the identifier the
     * manifest carries is a prefix of every code the rule can report.
     */
    public function test_a_computed_code_matches_the_identifier_it_starts_with(): void
    {
        $this->assertSame(
            'hihaho.debug.noDebugIn',
            CorpusDifferential::identifierFor(
                'transpiled/no-debug-in-namespace-rule/hihaho.debug.noDebugInApp',
                ['hihaho.debug.noDebugIn'],
            ),
        );
    }

    public function test_a_code_under_test_by_nothing_belongs_to_no_rule(): void
    {
        $this->assertNull(CorpusDifferential::identifierFor('analysis/unknown-diagnostic', self::IDENTIFIERS));
    }
}
