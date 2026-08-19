<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\FiresGate;
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

    private const string CORPUS = __DIR__ . '/../../vendor/symplify/phpstan-rules/src';

    private const string FIXTURES = __DIR__ . '/../Fixtures/Rules';

    private FiresGate $gate;

    protected function setUp(): void
    {
        // Set explicitly rather than inherited: the target is static, so whichever test ran last decides it, and
        // a rule whose hook only the PHP target carries then refuses here instead of emitting.
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        $this->gate = new FiresGate(
            dirname(__DIR__, 2),
            self::EXAMPLES,
            sys_get_temp_dir() . '/phpstan-to-mago-gate',
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
        $rules = self::corpusRules();
        foreach (self::fixtureRules() as $rule => $entry) {
            $rules[$rule] ??= $entry;
        }

        foreach ($rules as $rule => [$file, $class]) {
            if (glob(self::EXAMPLES . '/' . $rule . '/Bad*.php') === []) {
                continue;
            }

            yield $rule => [$rule, $file, $class];
        }
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
     * A dropped guard has to name the proof that lets it be dropped.
     *
     * Dropping a guard widens the rule, so it is only sound where the case the guard filters out cannot
     * reach the hook at all. Three drops in the corpus are sound that way, and each was checked by putting
     * the filtered case in a rule's *good* example and watching the port stay silent. A drop with no reason
     * is refused at translation time; this asserts the emitted side of that contract, so a new drop cannot
     * arrive carrying the old generic comment.
     */
    public function test_every_dropped_guard_names_why_it_cannot_hold(): void
    {
        $proven = [
            'Mago parses `f(...)` as a partial application, which never reaches a call hook',
            'an anonymous class is a separate node kind, so the class declaration hook never fires for one',
            'the class declaration hook fires for classes, never for an interface',
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
        Transpiler::$survey = true;

        /** @var array<string, array{string, string}> $rules */
        $rules = [];
        $found = glob(self::CORPUS . '/Rules/{,*/,*/*/}*Rule.php', GLOB_BRACE);
        foreach ($found === false ? [] : $found as $file) {
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
