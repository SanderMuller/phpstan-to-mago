<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The one corpus the fires gate excludes must have its own instrument per rule, and this checks that.
 *
 * `EmittedRuleFiresTest::CORPORA` leaves out `tomasvotruba/type-coverage` deliberately: every rule it emits
 * is an aggregate, so the per-file Bad/Good comparison is the wrong instrument for one project-wide
 * percentage. The exclusion is documented and names the `Aggregates*Test` classes that cover it instead.
 *
 * **The comment named three tests for five rules.** `AggregatesReturnCoverageTest` had been written and not
 * added to the list, and `PropertyTypeCoverageRule` had no test at all — emitted, counted by the census,
 * outside the gate's corpora and outside every aggregate test. Nothing ran it, which is the silence the
 * gate's own notes say the corpus list exists to remove, arriving through the exemption instead of through
 * an omission.
 *
 * It shipped a defect. The emitted plugin counted a trait's property against the class using it when the two
 * shared a file, over-reporting where the real rule reports nothing — recorded in VERIFICATION.md.
 *
 * So the exemption is asserted rather than described. A prose exemption has no expected value and cannot
 * fail; this one fails when a sixth coverage rule arrives without a test, and would have failed for
 * `PropertyTypeCoverageRule`.
 */
final class EveryAggregateRuleHasItsOwnTest extends TestCase
{
    private const string EXCLUDED_CORPUS = __DIR__ . '/../../vendor/tomasvotruba/type-coverage/src/Rules';

    /**
     * The rule each aggregate test covers, keyed by the rule.
     *
     * Kept as a map rather than derived from the class names, because the naming is not mechanical:
     * `ParamTypeCoverageRule` is covered by `AggregatesTypeCoverageTest` and not by an
     * `AggregatesParamCoverageTest`, which is the sort of near-miss a derived check would report as absent
     * or, worse, satisfy with the wrong file.
     */
    private const array COVERED_BY = [
        'ConstantTypeCoverageRule' => 'AggregatesConstantCoverageTest',
        'DeclareCoverageRule' => 'AggregatesDeclareCoverageTest',
        'ParamTypeCoverageRule' => 'AggregatesTypeCoverageTest',
        'PropertyTypeCoverageRule' => 'AggregatesPropertyCoverageTest',
        'ReturnTypeCoverageRule' => 'AggregatesReturnCoverageTest',
    ];

    public function test_every_rule_in_the_excluded_corpus_is_named_by_an_aggregate_test(): void
    {
        $paths = glob(self::EXCLUDED_CORPUS . '/*Rule.php');
        $rules = array_map(
            static fn (string $path): string => basename($path, '.php'),
            $paths === false ? [] : $paths,
        );
        sort($rules);

        $this->assertNotSame([], $rules, 'The excluded corpus has no rules, so this is asserting nothing.');

        $unmapped = array_values(array_diff($rules, array_keys(self::COVERED_BY)));
        $this->assertSame(
            [],
            $unmapped,
            "These rules are emitted by the corpus the fires gate excludes and no aggregate test claims\n"
            . "them, so nothing runs them:\n  " . implode("\n  ", $unmapped) . "\n\n"
            . "Write an Aggregates<X>CoverageTest that runs the real rule under PHPStan against this\n"
            . "transpiler's emission under mago and compares file, line, message and counts, then add it\n"
            . 'to COVERED_BY. The last rule to reach this state shipped an over-reporting defect.',
        );
    }

    public function test_every_named_aggregate_test_exists_and_names_its_rule(): void
    {
        foreach (self::COVERED_BY as $rule => $test) {
            $file = __DIR__ . '/' . $test . '.php';

            $this->assertFileExists(
                $file,
                "{$rule} is exempted from the fires gate on the strength of {$test}, which does not exist. "
                . 'An exemption citing a test that is not there is the shape that let PropertyTypeCoverageRule '
                . 'ship untested.',
            );

            // Existing is not covering. The test has to name the rule it is credited with, which is what
            // separates a real instrument from a file with a plausible name.
            $this->assertStringContainsString(
                $rule,
                (string) file_get_contents($file),
                "{$test} is credited with {$rule} and does not mention it.",
            );
        }
    }
}
