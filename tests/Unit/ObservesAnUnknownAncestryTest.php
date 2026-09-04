<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\HierarchyKnowledge;

/**
 * Whether a plugin can tell "this class does not descend from that" apart from "cannot tell".
 *
 * PHPStan's `TrinaryLogic` has no Mago equivalent, and rules read all three of its answers:
 * `->isSuperTypeOf(..)->yes()` to report only on a certain match, `->no()` to report only on a certain
 * mismatch, and `->no() === false` to mean "yes or maybe". A port that answers those with one boolean is
 * wrong for at least one of them, because the two directions want opposite defaults — `->yes()` wants unknown
 * to suppress a report and `->no()` wants unknown to permit one.
 *
 * Modelling three values is only worth anything if the third is observable, and there was reason to think it
 * might not be: Mago skips the body of a class whose parent or interface it cannot resolve, so the hook might
 * never fire where the ancestry is unknown. It does fire — confirming from a second direction what a fires-gate
 * pair established earlier — and `ClassLikeMetadata::hasIncompleteHierarchy()` is what makes the state
 * readable.
 *
 * **The trap this exists to record.** `Codebase::getClassAncestors()` returns the *unresolved* name itself, so
 * an ancestry test written as "is the target in this list" answers a confident **no** for a class whose
 * hierarchy is incomplete. That is the silent-narrowing shape: the rule loads, runs, and reports nothing where
 * the original would have reported. Every three-valued answer has to read `hasIncompleteHierarchy()`, not just
 * the list.
 */
#[Group('engine')]
final class ObservesAnUnknownAncestryTest extends TestCase
{
    /** @var array<string, string>|null */
    private static ?array $rows = null;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function classes(): iterable
    {
        // Descends from the target through a class the engine fully understands: a certain yes.
        yield 'a resolvable descendant' => [
            'probe_descends',
            'class=Maybe\Descends	incomplete=no	unresolved=[]	ancestors=[maybe\resolvable,maybe\target]	hasTarget=yes	missingExists=no',
        ];
        // Descends from nothing at all, and the engine knows it: a certain no.
        yield 'a resolvable non-descendant' => [
            'probe_unrelated',
            'class=Maybe\Unrelated	incomplete=no	unresolved=[]	ancestors=[]	hasTarget=no	missingExists=no',
        ];
        // The third state. `hasTarget=no` here is *not* a no — the ancestry is incomplete, the missing name is
        // in the list where a real ancestor would be, and `Nowhere\Missing` does not exist as far as the
        // codebase is concerned. A port reading only `hasTarget` would call this a no.
        yield 'a descendant of a class nothing declares' => [
            'probe_unresolvable',
            'class=Maybe\Unresolvable	incomplete=yes	unresolved=[nowhere\missing]	ancestors=[nowhere\missing]	hasTarget=no	missingExists=no',
        ];
    }

    #[DataProvider('classes')]
    public function test_reports_what_the_codebase_knows(string $callee, string $expected): void
    {
        $rows = self::rows();

        $this->assertArrayHasKey($callee, $rows, "The hook did not fire in {$callee}'s class, so nothing was observed there.");
        $this->assertSame($expected, $rows[$callee]);
    }

    /**
     * The three states are distinct, which is the whole claim.
     *
     * Asserted separately from the rows because the rows could all change together — an SDK rename, a
     * different ancestor spelling — while the distinction survives, and it is the distinction the decision
     * rests on.
     */
    public function test_an_unknown_ancestry_is_distinguishable_from_a_known_absent_one(): void
    {
        $rows = self::rows();

        $this->assertStringContainsString('incomplete=no', $rows['probe_unrelated']);
        $this->assertStringContainsString('incomplete=yes', $rows['probe_unresolvable']);

        // And both answer `hasTarget=no`, which is exactly why one boolean cannot carry the difference.
        $this->assertStringContainsString('hasTarget=no', $rows['probe_unrelated']);
        $this->assertStringContainsString('hasTarget=no', $rows['probe_unresolvable']);
    }

    /**
     * @return array<string, string>
     */
    private function rows(): array
    {
        return self::$rows ??= (new HierarchyKnowledge(
            __DIR__ . '/../Fixtures/hierarchy',
            dirname(__DIR__, 2),
        ))->rows();
    }
}
