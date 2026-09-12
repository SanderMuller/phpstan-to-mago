<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * The linter target, which nothing in the suite watched until this file existed.
 *
 * The guidelines have named this gap for as long as they have existed — "a one-token change to `$reportSpan`
 * alters five `.rs` files and nothing the suite sees" — and the standing answer was to emit all three targets
 * by hand and `diff -r` after every step. That works and it is not a check: it runs when someone remembers.
 *
 * The three rules are the ones {@see TranspilesToRustTest} already pins for the analyzer target, so the pair
 * of files for each rule now shows what the two Rust targets share and where they part. They part almost
 * everywhere: the analyzer emits a `Provider` and a hook method, and the linter emits a `LintRule` with its
 * own config struct, `RuleMeta`, `targets()` and a `check()` that destructures the node kind first. A body
 * change shows in both; a change to either scaffold shows in one.
 *
 * `good_example` and `bad_example` are `"<?php\n"` in these snapshots because the transpiler is called
 * without `--examples`, which is the API path this test uses. That is worth pinning rather than working
 * around: it is what a consumer calling the class directly gets.
 */
final class TranspilesToLintTest extends TestCase
{
    private const string RULES = __DIR__ . '/../Fixtures/Rules';

    private const string EXPECTED = __DIR__ . '/../Fixtures/expected-lint';

    protected function setUp(): void
    {
        Transpiler::$target = 'linter';
        Transpiler::$survey = false;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedRules(): iterable
    {
        yield 'guard chain' => ['ForbiddenStaticConstFetchRule'];
        yield 'loop with a formatted message' => ['UppercaseConstantRule'];
        yield 'message with a quoted class name' => ['QuotedClassNameMessageRule'];
    }

    #[DataProvider('supportedRules')]
    public function test_emits_the_reviewed_rule(string $rule): void
    {
        $emitted = (new Transpiler(self::RULES . '/' . $rule . '.php'))->transpile()['rust'];

        $this->assertSame(file_get_contents(self::EXPECTED . '/' . $rule . '.rs'), $emitted . "\n", "Emitted lint rule for {$rule} differs from the reviewed snapshot.");
    }

    /**
     * The scaffold itself, asserted apart from the byte comparison above.
     *
     * A snapshot that is only compared whole says nothing about *why* it is right, and updating one to make
     * a run green is a single keystroke. These are the parts a reader would check by hand: the file is Rust
     * rather than PHP, it implements the linter's trait rather than the analyzer's, and it carries the
     * identifier the rule reports under.
     */
    public function test_the_emitted_file_is_a_lint_rule_rather_than_an_analyzer_provider(): void
    {
        $emitted = (new Transpiler(self::RULES . '/UppercaseConstantRule.php'))->transpile()['rust'];

        $this->assertStringContainsString('impl LintRule for UppercaseConstantRule', $emitted);
        $this->assertStringContainsString('fn check<', $emitted);
        $this->assertStringContainsString('code: "fixture.uppercaseConstant"', $emitted);
        $this->assertStringNotContainsString('impl Provider for', $emitted, 'The analyzer scaffold leaked into the linter target.');
        $this->assertStringNotContainsString('<?php', str_replace('"<?php\\n"', '', $emitted), 'PHP leaked into a Rust file outside the example fields.');
    }
}
