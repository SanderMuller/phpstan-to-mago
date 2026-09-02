<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\PhpBackend;

/**
 * `PhpBackend::conditional()` — an inlined helper's guard chain, folded into one PHP expression.
 *
 * A guard that bails to `false` is `!(c) ? false : rest`, which reads better as `(c) && (rest)`. Taking the
 * `!(` off the front and the `)` off the back is how that used to be done, and those two are not always the
 * same pair of parentheses: `!(a) || !(b)` starts and ends exactly the same way, and unwrapping it gave
 * `a) || !(b`, rebuilt as `(a) || !(b) && (rest)` — De Morgan applied to one operand with the connective
 * left alone. The guard then answered the opposite of the rule for every subject where `a` holds.
 *
 * Found on `NoRoutingPrefixRule`, whose helper bails on
 * `! $name instanceof Identifier || $name->toString() !== 'import'`. No rule emitting at the time hit it,
 * which is why a test pins it rather than a snapshot: the shape is one vendor release away from arriving in
 * a rule that does emit, and it fails silently.
 */
final class NegatesAWholeGuardTest extends TestCase
{
    private PhpBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new PhpBackend();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function guards(): iterable
    {
        yield 'one negated call folds to a conjunction' => [
            '!(a())',
            'false',
            '(a()) && (rest)',
        ];

        yield 'one negated call bailing to true folds to a disjunction' => [
            '!(a())',
            'true',
            '!(a()) || (rest)',
        ];

        // The regression. Both ends match the old test and the parentheses do not pair, so the fold has to
        // decline and keep the ternary — which is correct for every shape, just longer.
        yield 'a disjunction of two negations keeps the ternary' => [
            '!(a()) || !(b())',
            'false',
            '(!(a()) || !(b()) ? false : rest)',
        ];

        yield 'a disjunction of two negations bailing to true keeps the ternary' => [
            '!(a()) || !(b())',
            'true',
            '(!(a()) || !(b()) ? true : rest)',
        ];

        // A negation whose operand is itself a disjunction *does* pair, so the fold still applies where it
        // is sound. Without this the fix would read as "give up on anything with an `||` in it".
        yield 'a negated disjunction still folds' => [
            '!(a() || b())',
            'false',
            '(a() || b()) && (rest)',
        ];

        yield 'a condition that is not a negation keeps the ternary' => [
            'a()',
            'false',
            '(a() ? false : rest)',
        ];
    }

    #[DataProvider('guards')]
    public function test_a_guard_folds_only_when_the_negation_covers_all_of_it(
        string $condition,
        string $then,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->backend->conditional($condition, $then, 'rest'));
    }
}
