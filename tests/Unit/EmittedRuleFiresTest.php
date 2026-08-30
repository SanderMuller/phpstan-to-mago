<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\FiresGate;
use Sandermuller\PhpstanToMago\Tests\Support\LockedCorpus;
use Sandermuller\PhpstanToMago\Transpiler;
use Throwable;

/**
 * The gate that makes `emitted` mean `works`.
 *
 * `.ai/guidelines/verification.md` states the standard: a count is worth stating only alongside "the
 * files parse, no Rust leaked into a `.php` file, every `Support::` helper it calls exists, and the
 * rules actually ran". {@see TranspilesToPhpTest} covers the first three. This covers the fourth, per
 * rule, by running the emitted plugin under the real mago binary and the rule it came from under
 * PHPStan, then comparing.
 *
 * It is red on purpose for the rules that cannot fire yet. That is the point: they were emitted and
 * counted for a long time while reporting nothing, and no static check noticed.
 */
final class EmittedRuleFiresTest extends TestCase
{
    private const string EXAMPLES = __DIR__ . '/../Fixtures/examples';

    /**
     * The rule packages the gate covers.
     *
     * `hihaho/phpstan-rules` joined once it became a dev dependency: before that a hosted runner could not
     * resolve it, so its three emitted rules had only ever been checked by hand. An emitted rule nobody runs
     * is the silence this gate exists to remove, and it does not matter which package it came from.
     *
     * @var list<string>
     */
    private const array CORPORA = [
        __DIR__ . '/../../vendor/symplify/phpstan-rules/src',
        __DIR__ . '/../../vendor/hihaho/phpstan-rules/src',
        // Added when its first rule emitted. An emitted rule outside these corpora is the silence this gate
        // exists to remove: the census counts it and nothing ever runs it.
        __DIR__ . '/../../vendor/tomasvotruba/cognitive-complexity/src',
        // Added for the same reason, and late: the package was a dev dependency for three months while the
        // census spoke for four packages and this gate for three, so its one emitting rule was counted and
        // never run.
        __DIR__ . '/../../vendor/phpstan/phpstan-strict-rules/src',
        // Added when `FetchingDeprecatedConstRule` emitted, for the reason above: an emitted rule outside
        // these corpora is counted by the census and run by nothing.
        __DIR__ . '/../../vendor/phpstan/phpstan-deprecation-rules/src',
        // `tomasvotruba/type-coverage` is deliberately absent, and this is the one exclusion. Its emitting rule
        // is `ParamTypeCoverageRule`, an aggregate: PHPStan reduces a *collection* rather than deciding per
        // node, so registering it here would need a collector service this gate cannot add and a threshold, and
        // the per-file Bad/Good comparison is the wrong instrument for one project-wide percentage.
        // {@see AggregatesTypeCoverageTest} is the right one and already runs it — the real rule under real
        // PHPStan against the transpiler's own emission under real mago, compared by file, line and message,
        // plus the counts. Naming the exclusion rather than leaving the corpus list looking complete.
    ];

    private const string FIXTURES = __DIR__ . '/../Fixtures/Rules';

    private FiresGate $gate;

    protected function setUp(): void
    {
        // Set explicitly rather than inherited: the target is static, so whichever test ran last decides it, and
        // a rule whose hook only the PHP target carries then refuses here instead of emitting.
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        // Keyed by process, because the sandbox holds one directory per rule and two suites running at once
        // wrote into the same ones. That is not a hypothetical: two concurrent runs produced a report where
        // `SingleArgEventDispatchRule` disagreed with PHPStan in one case while PHPStan reported it correctly
        // in another case of the *same* run — internally contradictory, which is what said interference rather
        // than regression. A shared path makes a green suite and a red one equally meaningless.
        $this->gate = new FiresGate(
            dirname(__DIR__, 2),
            self::EXAMPLES,
            sys_get_temp_dir() . '/phpstan-to-mago-gate-' . getmypid(),
        );
    }

    /**
     * The rules this gate covers, with the file and class each one comes from.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function coveredRules(): iterable
    {
        // The vendored corpus is the shipped claim, and every emitted rule in it owes the gate a pair. The
        // local fixtures join whenever one is written for them: a fixture exists to pin a specific shape, and
        // a shape worth pinning is worth running.
        foreach (self::gatedRules() as $rule => [$file, $class]) {
            if (glob(self::EXAMPLES . '/' . $rule . '/Bad*.php') === []) {
                continue;
            }

            yield $rule => [$rule, $file, $class];
        }
    }

    /**
     * Every rule this gate knows about, from all three sources.
     *
     * One list rather than three, because both directions of the pair check read it and a rule visible to
     * one and not the other reads as an orphaned example directory. That is how `ConfiguredByTheProjectRule`
     * first failed: covered by the data provider, invisible to the orphan check, and reported as a pair
     * nothing emits while its four cases were passing.
     *
     * @return array<string, array{string, string}>
     */
    private static function gatedRules(): array
    {
        $rules = self::corpusRules();
        foreach (self::fixtureRules() as $rule => $entry) {
            $rules[$rule] ??= $entry;
        }

        // The rule a project configures rather than a package. It sits outside `Fixtures/Rules` on purpose:
        // that glob emits what it finds with no project behind it, and this rule refuses that way —
        // correctly, because without `--from-config` there is nowhere for its values to come from.
        // `FiresGate::FROM_PROJECT` names the project it is emitted against.
        $rules['ConfiguredByTheProjectRule'] = [
            __DIR__ . '/../Fixtures/RegisteredRulePackage/ConfiguredByTheProjectRule.php',
            'Sandermuller\\PhpstanToMago\\Tests\\Fixtures\\RegisteredRulePackage\\ConfiguredByTheProjectRule',
        ];

        return $rules;
    }

    /**
     * The repository's own fixture rules, keyed the same way as the corpus.
     *
     * @return array<string, array{string, string}>
     */
    private static function fixtureRules(): array
    {
        $found = glob(self::FIXTURES . '/*Rule.php');
        $rules = [];
        foreach ($found === false ? [] : $found as $file) {
            $rule = basename($file, '.php');
            $rules[$rule] = [$file, 'Sandermuller\\PhpstanToMago\\Tests\\Fixtures\\Rules\\' . $rule];
        }

        return $rules;
    }

    #[DataProvider('coveredRules')]
    public function test_the_emitted_plugin_reports_the_bad_example(string $rule, string $file, string $class): void
    {
        $findings = $this->gate->magoFindings($rule, $file);
        $reported = array_intersect($this->gate->examples($rule, 'Bad'), array_keys($findings));

        $this->assertNotSame(
            [],
            $reported,
            "The emitted {$rule} reported nothing on its bad example. It parses and loads, so this is the "
            . 'failure that static checks cannot see: the plugin ran and found nothing.',
        );
    }

    #[DataProvider('coveredRules')]
    public function test_the_emitted_plugin_stays_silent_on_the_good_example(string $rule, string $file, string $class): void
    {
        $findings = $this->gate->magoFindings($rule, $file);
        $reported = array_intersect($this->gate->examples($rule, 'Good'), array_keys($findings));

        $this->assertSame(
            [],
            $reported,
            "The emitted {$rule} reported its good example, so the port is wider than the rule.",
        );
    }

    /**
     * The example pair has to make PHPStan itself report, or the comparison proves nothing.
     *
     * Checked separately from the comparison because two tools reporting nothing is equally consistent
     * with "the code is clean" and "the second tool never looked". Without this the gate would pass
     * every dead rule whose PHPStan side also failed to load.
     */
    #[DataProvider('coveredRules')]
    public function test_phpstan_reports_the_bad_example(string $rule, string $file, string $class): void
    {
        $findings = $this->gate->phpstanFindings($rule, $file, $class);
        $reported = array_intersect($this->gate->examples($rule, 'Bad'), array_keys($findings));

        $this->assertNotSame(
            [],
            $reported,
            "PHPStan reported nothing for {$rule} on its own bad example, so the example does not exercise "
            . 'the rule and any agreement measured against it would be agreement on zero.',
        );
    }

    #[DataProvider('coveredRules')]
    public function test_the_emitted_plugin_agrees_with_phpstan(string $rule, string $file, string $class): void
    {
        // Compared over the example files only. The stubs sit in mago's source paths so it can resolve
        // ancestry, while PHPStan merely scans them, so a rule that legitimately matches a stub would show
        // up on one side and not the other — a difference in what each tool was asked to look at, not in
        // what the two rules decide.
        $examples = [...$this->gate->examples($rule, 'Bad'), ...$this->gate->examples($rule, 'Good')];

        $this->assertSame(
            $this->onlyExamples($this->gate->phpstanFindings($rule, $file, $class), $examples),
            $this->onlyExamples($this->gate->magoFindings($rule, $file), $examples),
            "The emitted {$rule} and PHPStan disagree on its example pair.",
        );
    }

    /**
     * @param array<string, list<string>> $findings
     * @param list<string> $examples
     *
     * @return array<string, list<string>>
     */
    private function onlyExamples(array $findings, array $examples): array
    {
        return array_intersect_key($findings, array_flip($examples));
    }

    /**
     * Every rule the transpiler emits needs an example pair.
     *
     * Without this the gate would pass a rule by never looking at it, which is the same silence the
     * gate exists to remove. A new emission is only finished once it brings its own examples.
     */
    public function test_every_emitted_rule_has_an_example_pair(): void
    {
        $mismatch = LockedCorpus::mismatch();
        if ($mismatch !== null) {
            self::markTestSkipped($mismatch);
        }

        $missing = [];
        foreach (self::corpusRules() as $rule => [$file]) {
            if (! $this->gate->hasExamples($rule)) {
                $missing[] = $rule;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These rules are emitted with no example pair under tests/Fixtures/examples, so nothing '
            . "proves they run:\n  " . implode("\n  ", $missing),
        );
    }

    /**
     * And every example pair needs a rule that still emits.
     *
     * The other direction, and it was a hole. `coveredRules()` yields nothing for a rule that stopped
     * emitting, so its four gate cases disappear rather than fail — the suite goes from 302 to 298 and
     * stays green. Measured that way: making the nested-guard fold return null took `NoDocumentMockingRule`
     * back to a refusal and nothing said so.
     *
     * A pair that names a rule nothing emits is either a rule that regressed or a directory left behind, and
     * both are worth a failure. Fixture rules are excluded because a fixture may be written to prove a
     * *refusal*.
     */
    public function test_every_example_pair_has_a_rule_that_emits(): void
    {
        $mismatch = LockedCorpus::mismatch();
        if ($mismatch !== null) {
            self::markTestSkipped($mismatch);
        }

        $emitting = array_keys(self::gatedRules());
        $fixtures = array_keys(self::fixtureRules());

        $orphaned = [];
        $directories = glob(self::EXAMPLES . '/*', GLOB_ONLYDIR);
        foreach ($directories === false ? [] : $directories as $directory) {
            $rule = basename($directory);
            if ($rule === 'stubs' || in_array($rule, $emitting, true) || in_array($rule, $fixtures, true)) {
                continue;
            }

            $orphaned[] = $rule;
        }

        // Asserted as an exact set rather than as "none", because both directions matter. A pair added for a
        // rule that does not emit is a case that never runs; a rule here that starts emitting again leaves a
        // stale entry, and the equality is what makes that fail too.
        //
        // `CombinedMethodCallRule` and `PositionalFlagArgumentNullsafeMethodCallRule` sat here because both
        // refused on `flagRecord()` being assigned inside a loop, and both left the way the comment promised
        // — by emitting, once a record folded across a loop became locals rather than expressions. Their
        // pairs had been running nothing until then, which is what this check exists to say out loud.
        //
        // What is left was written before the rule that would use it, and is still waiting: the arithmetic
        // family needs the operand-binding shape as well as the ported helper.
        $expected = [
            'OperandsInArithmeticDivisionRule',
        ];
        sort($orphaned);

        $this->assertSame(
            $expected,
            $orphaned,
            "The set of example pairs with no emitting rule changed:\n  " . implode("\n  ", $orphaned),
        );
    }

    /**
     * A rule that accumulates findings has to be shown accumulating, and one span can hold two.
     *
     * `TraitRequiresInterfaceRule` loops over its configured trait-to-interface pairs and adds a finding for
     * each one a class-like violates — and reports every one of them at the *class*, not at the `use`
     * statement that caused it. So two violations are two findings at the same file and line.
     *
     * Its pair could not see that. One configured pair means one finding per class, which a port reporting
     * once per class produces too, and the differential agrees either way. The trick that rescued
     * `RequireAttributeNameRule` — writing the construct across lines so the findings land on different ones
     * — is not available here, because the span is the class however the source is laid out. The only shape
     * that separates the two readings is the count at one span, asserted here.
     *
     * Found by the `phpstan-src-e7` session auditing for exactly this after the attribute case. The general
     * form: an example pair proves nothing about a rule that reports N times per node unless some example
     * makes N greater than one.
     */
    public function test_a_rule_that_reports_per_pair_reports_twice_at_one_span(): void
    {
        $mismatch = LockedCorpus::mismatch();
        if ($mismatch !== null) {
            self::markTestSkipped($mismatch);
        }

        $rule = 'TraitRequiresInterfaceRule';
        [$file, $class] = self::corpusRules()[$rule] ?? [null, null];
        if ($file === null || $class === null) {
            self::markTestSkipped('the corpus no longer emits ' . $rule);
        }

        $mago = $this->gate->magoFindings($rule, $file);
        $phpstan = $this->gate->phpstanFindings($rule, $file, $class);

        $this->assertCount(
            2,
            $phpstan['BadTwoTraitsOneClass.php'] ?? [],
            'The real rule no longer reports one finding per violated pair, so this example proves nothing.',
        );
        $this->assertSame($phpstan['BadTwoTraitsOneClass.php'], $mago['BadTwoTraitsOneClass.php'] ?? []);
    }

    /**
     * A dropped guard has to name the proof that lets it be dropped.
     *
     * Dropping a guard widens the rule, so it is only sound where the case the guard filters out cannot
     * reach the hook at all. Most drops in the corpus were checked by putting the filtered case in a rule's
     * *good* example and watching the port stay silent. One cannot be: a method outside a class-like is not
     * something PHP can express, so "there is always an enclosing class" is proof by construction rather than
     * by example, and it is listed as such. A drop with no reason is refused at translation time; this asserts
     * the emitted side of that contract, so a new drop cannot arrive carrying the old generic comment.
     */
    public function test_every_dropped_guard_names_why_it_cannot_hold(): void
    {
        $proven = [
            'Mago parses `f(...)` as a partial application, which never reaches a call hook',
            'an anonymous class is a separate node kind, so the class declaration hook never fires for one',
            // Same proof, one hook wider: `ExplicitClassPrefixSuffixRule` registers all four class-like kinds,
            // and `NodeKind::AnonymousClass` is none of them. `GoodNames.php` holds one, so the silence is
            // measured rather than argued.
            'an anonymous class is a separate node kind, so this hook only fires for a named class-like',
            'the class declaration hook fires for classes, never for an interface',
            'a class-like found by a subtree search is always named: Mago models an anonymous class as its own '
            . 'node kind, which a search for classes, interfaces, traits and enums never returns',
            // Proof by construction: PHP has no method outside a class-like, so no example can hold the case.
            'a declaration hook fires inside a class-like, so there is always an enclosing class',
            // The guards ahead of it establish the index; the good examples hold each case they filter — a
            // named argument, a spread, a non-bool, a call past the end of the parameter list.
            'an index produced behind guards is never null once those guards have run',
        ];

        $unproven = [];
        foreach (self::corpusRules() as $rule => [$file]) {
            $emitted = (new Transpiler($file))->transpile()['rust'];
            if (preg_match_all('/guard dropped: (.+)$/m', $emitted, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $reason) {
                if (! in_array(trim($reason), $proven, true)) {
                    $unproven[] = $rule . ': ' . trim($reason);
                }
            }
        }

        $this->assertSame(
            [],
            $unproven,
            "A guard was dropped for a reason nobody has proved:\n  " . implode("\n  ", $unproven),
        );
    }

    /**
     * Every rule the transpiler **emits**, keyed by class name, with its file and its class.
     *
     * Emission is decided by transpiling, not by a hard-coded list: a refused rule owes the gate no
     * example, and a rule that starts or stops being emitted has to change this set rather than a list
     * someone has to remember to edit. The corpus holds roughly 96 rules and emits 20 of them.
     *
     * @return array<string, array{string, string}>
     */
    private static function corpusRules(): array
    {
        /** @var array<string, array{string, string}>|null $cached */
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $target = Transpiler::$target;
        $survey = Transpiler::$survey;
        Transpiler::$target = 'php';
        // Emit mode, not survey. Survey assumes a hook exists so it can report what a body would need behind
        // one, which means a rule with no hook mapping comes back *emitted* — and this set is what decides
        // which rules owe the gate an example pair. Adding `phpstan-strict-rules` demanded pairs for three
        // rules that cannot run at all: `Empty_`, `ShellExec` and `Variable` have no hook.
        Transpiler::$survey = false;

        /** @var array<string, array{string, string}> $rules */
        $rules = [];
        $found = [];
        foreach (self::CORPORA as $corpus) {
            // Not only `*Rule.php`: `hihaho/phpstan-rules` names two of its rules without the suffix, and a
            // glob that misses them would pass them by never looking.
            $paths = glob($corpus . '/Rules/{,*/,*/*/}*.php', GLOB_BRACE);
            foreach ($paths === false ? [] : $paths as $path) {
                $found[] = $path;
            }
        }

        foreach ($found as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/^namespace (.+);$/m', $source, $namespace) !== 1) {
                continue;
            }

            try {
                (new Transpiler($file))->transpile();
            } catch (Throwable) {
                // Refused. A refusal is a result, not a gap, so it owes the gate nothing.
                continue;
            }

            $rule = basename($file, '.php');
            $rules[$rule] = [$file, $namespace[1] . '\\' . $rule];
        }

        Transpiler::$target = $target;
        Transpiler::$survey = $survey;
        ksort($rules);

        return $cached = $rules;
    }
}
