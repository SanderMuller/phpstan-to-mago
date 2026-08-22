<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Error;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Runtime\Support;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * The gate for the PHP target.
 *
 * Every assertion here stands for a bug that shipped past a weaker check at some point, which is why
 * "it emitted" is not one of them. Emitting is easy; emitting something that parses, contains no Rust,
 * only calls helpers that exist, and matches a reviewed snapshot is the claim worth making.
 */
final class TranspilesToPhpTest extends TestCase
{
    private const string RULES = __DIR__ . '/../Fixtures/Rules';

    private const string EXPECTED = __DIR__ . '/../Fixtures/expected';

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function supportedRules(): iterable
    {
        foreach (self::fixtures() as $label => [$rule]) {
            yield $label => [$rule, self::RULES . '/' . $rule . '.php'];
        }

        // The dual-hook shape — a node hook and an after-analysis hook in one plugin — has no snapshot.
        // `CombinedMethodCallRule` was it, and `hihaho/phpstan-rules` v3.15.2 rewrote the positional-flag
        // half into a fold this transpiler refuses, which takes the whole rule with it. No other rule in any
        // installed package emits both hooks, and no fixture can reach the shape: it is triggered by a
        // `Vocabulary::CROSS_FILE_CHECKS` entry keyed to a corpus trait, and pointing a fixture at an
        // existing pass would emit a plugin that reports something the fixture does not check.
        //
        // So the shape is exercised without being pinned: `OnlyAllowFacadeAliasInBlade` below snapshots the
        // after-hook-alone emission, `FormRequestFields` and `FacadeAliases` have their own tests, and the
        // fires-gate runs both passes. What is unpinned is specifically a plugin registering both hooks at
        // once, and it regains a snapshot the moment a corpus rule of that shape emits again.

        // The other end of the same mechanism: a rule whose *every* check is a whole-project pass, so there is
        // no node to dispatch on and the plugin is the after hook alone. Pinned because what is interesting is
        // absent — no node hook, no targets, no requirements — and an emitter that registered them anyway
        // would declare targets it never looks at.
        yield 'a rule that is only a whole-project pass' => [
            'OnlyAllowFacadeAliasInBlade',
            __DIR__ . '/../../vendor/hihaho/phpstan-rules/src/Rules/OnlyAllowFacadeAliasInBlade.php',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    private static function fixtures(): iterable
    {
        yield 'guard chain' => ['ForbiddenStaticConstFetchRule'];
        yield 'loop with a formatted message' => ['UppercaseConstantRule'];
        yield 'message with a quoted class name' => ['QuotedClassNameMessageRule'];
        // PHP only: the namespace is read from the file's own text, which `support.rs` has no counterpart
        // for, so the Rust targets refuse this rule rather than emitting a call that cannot compile.
        yield 'gated on the declared namespace' => ['NamespacePrefixRule'];
        yield 'membership in a constant set' => ['ConstantSetRule'];
        yield 'a report code carrying a classification' => ['ClassifiedCodeRule'];
        yield 'a loop inside an inlined predicate helper' => ['AnyConstantHelperRule'];
        yield 'a reflection question answered by the codebase' => ['AsksTheCodebaseRule'];
        yield 'a helper that forwards to the one that decides' => ['ForwardingHelperRule'];
        yield 'the names a property declaration declares' => ['PropertyNameRule'];
        yield 'membership in a table the constructor built' => ['ConstructedLookupRule'];
        // PHP only, and the widest emission in the suite: a producer handing back a record, a producer handing
        // back one value, and reflection on a class named at analysis time. Snapshotted because the emitted
        // output is the contract — the fires-gate proves it *runs*, and this proves a refactor did not change
        // what it emits.
        yield 'a record producer feeding a message' => ['PositionalFlagRule'];
        // The receiver-typed half of the same family: the class comes from the receiver's inferred type rather
        // than from a written name, and the type is null-stripped first. One per hook, because `?->` is a node
        // kind of its own in Mago and the `MethodCall` hook does not fire for it.
        yield 'a class read from the receiver type' => ['PositionalFlagOnReceiverRule'];
        yield 'the same, on a nullsafe call' => ['PositionalFlagOnNullsafeReceiverRule'];
        // A closure and its declared parameters, which every Symfony config-closure rule gates on.
        yield 'a closure with one class-typed parameter' => ['ConfigClosureRule'];
        // PHP only, for the same reason as the namespace rule above. Snapshotted because the interesting part
        // is what is *absent*: a cache around a pure question, and the key binding that serves it, are dropped
        // and the guard is the question itself.
        yield 'a value producer behind a cache' => ['MemoisedLookupRule'];
        // PHP only, and the one snapshot of the per-check emission: two independent checks of one node, each
        // in its own method so its guards decline that check rather than the rule, and a prologue local passed
        // into both. The fires-gate proves it runs; this proves a refactor did not change the shape.
        yield 'two independent checks of one node' => ['TwoChecksRule'];
        // PHP only. The class test is asked at runtime rather than folded away, and the plugin registers every
        // class-like kind — which is what PHPStan's `InClassNode` visits. Snapshotted because the two earlier
        // attempts at deciding that breadth both went silent on an enum, and the emitted targets are where the
        // decision is visible.
        yield 'a class test compounded with another condition' => ['CompoundClassGuardRule'];
        // PHP only, and the snapshot of the widest hook shape: one rule over several node kinds, registering a
        // target per kind its branches name and emitting a method per branch. Pinned because the emission is
        // where the target set and the per-branch split are visible, and the gate can only prove it fires.
        yield 'one rule over several node kinds' => ['EveryExpressionRule'];
        // PHP only. What is interesting is what is *absent*: a cache declared part-way through a helper, its
        // keyed fill, and the read that follows all collapse to the question the cache stood for.
        yield 'a cache declared part-way through a helper' => ['MidBodyCacheRule'];
        // Six helpers deep, which the flat cap of 4 refused. Snapshotted rather than merely asserted to emit,
        // because what matters is that the innermost predicate is what the plugin ends up testing — a chain
        // that inlines to the wrong depth would still emit.
        yield 'a predicate reached through six helpers' => ['DeepHelperChainRule'];
    }

    #[DataProvider('supportedRules')]
    public function test_emits_the_reviewed_plugin(string $rule, string $file): void
    {
        $emitted = $this->transpile($file);

        $this->assertSame(file_get_contents(self::EXPECTED . '/' . $rule . '.php'), $emitted . "\n", "Emitted plugin for {$rule} differs from the reviewed snapshot.");
    }

    #[DataProvider('supportedRules')]
    public function test_emitted_plugin_parses(string $rule, string $file): void
    {
        // Parsed with the same parser the transpiler reads rules with, rather than shelling out to
        // `php -l`: no subprocess, and the failure names the offending line.
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $statements = $parser->parse($this->transpile($file));
        } catch (Error $error) {
            self::fail("Emitted plugin for {$rule} does not parse: " . $error->getMessage());
        }

        $this->assertNotNull($statements, "Emitted plugin for {$rule} produced no statements.");
    }

    /**
     * A bare `snake_case` identifier is well formed PHP, so parsing is not enough: it reads as a
     * constant. Untranslated Rust is what produces those, and it must never reach a `.php` file.
     */
    #[DataProvider('supportedRules')]
    public function test_emitted_plugin_contains_no_rust(string $rule, string $file): void
    {
        $emitted = $this->transpile($file);

        foreach (['support::', 'b"', 'Ok(', '&node.', '.iter()', '.as_slice()', '|item|'] as $marker) {
            $this->assertStringNotContainsString($marker, $emitted, "Rust leaked into {$rule}: {$marker}");
        }
    }

    /**
     * A missing helper is otherwise only found at analysis time, as a worker crash that names the rule
     * but not the helper.
     *
     * Every runtime class the plugin imports, not `Support` alone. A plugin whose only call was
     * `FacadeAliases::report()` asserted nothing at all and PHPUnit reported it risky, which is the same
     * finding from the other side: the check was narrower than the claim its name makes.
     */
    #[DataProvider('supportedRules')]
    public function test_every_helper_it_calls_exists(string $rule, string $file): void
    {
        $emitted = $this->transpile($file);

        preg_match_all('/^use (Sandermuller\\\\PhpstanToMago\\\\Runtime\\\\([A-Za-z]+));$/m', $emitted, $imported);
        $this->assertNotSame([], $imported[1], "Emitted plugin for {$rule} imports no runtime helper class.");

        foreach ($imported[1] as $index => $class) {
            preg_match_all('/\b' . preg_quote($imported[2][$index], '/') . '::([a-zA-Z]+)\(/', $emitted, $calls);
            foreach (array_unique($calls[1]) as $helper) {
                $this->assertTrue(
                    method_exists($class, $helper),
                    "Emitted plugin for {$rule} calls {$imported[2][$index]}::{$helper}(), which does not exist.",
                );
                $this->assertTrue(
                    (new ReflectionMethod($class, $helper))->isPublic(),
                    "{$imported[2][$index]}::{$helper}() is not public.",
                );
            }
        }
    }

    /**
     * The same check over every rule in the vendored corpus that emits, not only the fixtures.
     *
     * A fixture is written to pin a shape, so it exercises the helpers that shape needs — and nothing else. The
     * corpus asks for whatever it asks for: `Support::fileContains()` and `fileStartsWith()` were mapped by the
     * transpiler and never written, so a rule using `str_contains($scope->getFile(), ..)` emitted a plugin that
     * loaded and then killed the worker with "Call to undefined method". No fixture asked, so the per-fixture gate
     * above could not see it, and the corpus rule that did ask was not reaching the fires-gate either, because the
     * sandbox flattened its examples and its guard tests the file's path. The gate copies directories now, so that
     * second hole is closed too — but this check is the one that does not depend on anybody writing an example.
     *
     * Cheap enough to run over the whole corpus: this transpiles, it does not analyse.
     */
    public function test_every_helper_the_corpus_calls_exists(): void
    {
        $rules = glob(dirname(__DIR__, 2) . '/vendor/symplify/phpstan-rules/src/Rules/{,*/,*/*/}*Rule.php', GLOB_BRACE);
        $emitted = 0;

        foreach ($rules === false ? [] : $rules as $file) {
            try {
                $plugin = (new Transpiler($file))->transpile()['rust'];
            } catch (Refusal) {
                continue; // a refusal is a documented outcome, not a failure
            }

            ++$emitted;
            preg_match_all('/Support::([a-zA-Z]+)\(/', $plugin, $matches);
            foreach (array_unique($matches[1]) as $helper) {
                $this->assertTrue(
                    method_exists(Support::class, $helper),
                    basename($file) . " emits a call to Support::{$helper}(), which does not exist. The plugin would "
                    . 'load and then kill the worker.',
                );
            }

            // The hook's target has to name a case the SDK really has. The SDK renames exactly one — `Class_`,
            // because `::class` is special — and a list of reserved words guessing at that convention emitted
            // `NodeKind::Foreach_`, which does not exist. A plugin naming a missing case dies on load.
            // Compared against the enum's own case names. Reading them beats a dynamic constant fetch, which
            // throws on a missing case and would make this pass or error rather than fail with a reason.
            $declared = array_map(static fn (NodeKind $kind): string => $kind->name, NodeKind::cases());
            preg_match_all('/NodeKind::([A-Za-z_]+)/', $plugin, $kinds);
            foreach (array_unique($kinds[1]) as $case) {
                $this->assertContains(
                    $case,
                    $declared,
                    basename($file) . " targets NodeKind::{$case}, which the SDK does not declare.",
                );
            }
        }

        // Guards the guard: if the glob or the transpiler stops producing plugins, the loop above passes by
        // never looking, which is the failure mode this whole file exists to rule out.
        $this->assertGreaterThan(15, $emitted, 'The corpus produced almost no plugins, so this proved nothing.');
    }

    /**
     * A report anchored on a loop item cannot be emitted after the loop.
     *
     * `->line($member->getLine())` becomes the variable the emitted `foreach` binds, so a report after that loop
     * names something no longer bound. PHP leaves the loop variable set, so the finding would land on the last
     * member seen rather than crash — a wrong span through a path that reads correctly.
     *
     * Every rule in the corpus reports inside the loop that anchored it, so nothing there exercises this. The
     * fixture exists to, and writing it is what found the defect: the trailing report was built from its own
     * template and ignored the anchor outright, so a rule in this shape silently reported on the class instead.
     */
    public function test_refuses_a_report_anchored_on_a_loop_item_it_has_left(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('anchored on a loop item');

        $this->transpile(self::RULES . '/AnchorEscapesLoopRule.php');
    }

    public function test_refuses_a_construct_outside_the_vocabulary(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('->isString()');

        $this->transpile(self::RULES . '/UnsupportedRule.php');
    }

    private function transpile(string $file): string
    {
        return (new Transpiler($file))->transpile()['rust'];
    }
}
