<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

/**
 * What the method hook does with a trait, pinned on both engines.
 *
 * Thirty-two emitted plugins register `NodeKind::Method`, and every one of them answers a different question
 * from PHPStan inside a trait: PHPStan visits a trait method once per *using* class and names that class,
 * mago fires once at the declaration and names the trait. Measured at 34.7% of firings on
 * `Illuminate/Database/Eloquent`; VERIFICATION.md carries the numbers.
 *
 * This is a characterisation test, not a gate. It asserts the divergence rather than agreement, because the
 * divergence is what ships, and it goes red three useful ways: mago changes, PHPStan changes, or someone
 * closes the gap without saying so. The reason it existed unnoticed is that no example pair holds a trait,
 * so nothing before this ran both engines over one.
 *
 * The five non-trait shapes are asserted too, and they are the control: without them a failure here could be
 * any disagreement about methods rather than this one.
 */
#[CoversNothing]
#[Group('engine')]
final class TraitMethodHookDivergesTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/TraitDivergence';

    /** The five shapes both engines attribute the same way, in the sorted order the runs are read in. */
    private const array AGREED = [
        'TraitDivergence\AbstractClass::abstractMethod',
        'TraitDivergence\AbstractClass::inAbstract',
        'TraitDivergence\AnEnum::inEnum',
        'TraitDivergence\AnInterface::inInterface',
        'TraitDivergence\PlainClass::inClass',
    ];

    public function test_phpstan_visits_a_trait_method_once_per_using_class(): void
    {
        $fired = $this->firings($this->runPhpstan(...));

        $this->assertSame([
            'TraitDivergence\AbstractClass::abstractMethod',
            'TraitDivergence\AbstractClass::inAbstract',
            'TraitDivergence\AlsoUsesIt::inTrait',
            'TraitDivergence\AnEnum::inEnum',
            'TraitDivergence\AnInterface::inInterface',
            'TraitDivergence\PlainClass::inClass',
            'TraitDivergence\UsesTheTrait::inTrait',
        ], $fired, 'PHPStan no longer attributes a trait method to each using class.');
    }

    public function test_mago_visits_a_trait_method_once_at_its_declaration(): void
    {
        $fired = $this->firings($this->runMago(...));

        $this->assertSame([
            'TraitDivergence\ATrait::inTrait',
            'TraitDivergence\AbstractClass::abstractMethod',
            'TraitDivergence\AbstractClass::inAbstract',
            'TraitDivergence\AnEnum::inEnum',
            'TraitDivergence\AnInterface::inInterface',
            'TraitDivergence\AnUnusedTrait::inUnusedTrait',
            'TraitDivergence\PlainClass::inClass',
            'TraitDivergence\UsedOnlyByATrait::inChainedTrait',
        ], $fired, 'The mago method hook no longer names the trait a method is declared in.');
    }

    /**
     * The gap itself, stated as the two sets rather than as a count.
     *
     * Read from the same two runs the tests above assert, so it cannot drift from them.
     *
     * Three mago-only entries, and they are one mechanism at three depths. `ATrait::inTrait` is reported
     * once where PHPStan reports it per using class. `AnUnusedTrait::inUnusedTrait` has no using class at
     * all, so PHPStan never reaches the body — the degenerate case, and the one that reads as a false
     * positive in a differential. `UsedOnlyByATrait::inChainedTrait` has a user that is itself a trait
     * nothing uses, so the chain never arrives at a class and the silence is the same.
     *
     * That third case is here because counting `use` statements does not see it. On `laravel/framework`, 9 of
     * `NoDynamicNameRule`'s 15 port-only findings sit in traits with no user, and all 7 of
     * `ForbiddenStaticClassConstFetchRule`'s do — six with no user and one, `BroadcastsEvents`, whose only
     * user is the trait `BroadcastsEventsAfterCommit`. A check that counted that as a user would have called
     * the seventh unexplained.
     */
    public function test_the_two_engines_name_a_different_class_for_the_same_trait_method(): void
    {
        $phpstan = $this->firings($this->runPhpstan(...));
        $mago = $this->firings($this->runMago(...));

        $this->assertSame(self::AGREED, array_values(array_intersect($phpstan, $mago)));
        $this->assertSame([
            'TraitDivergence\ATrait::inTrait',
            'TraitDivergence\AnUnusedTrait::inUnusedTrait',
            'TraitDivergence\UsedOnlyByATrait::inChainedTrait',
        ], array_values(array_diff($mago, $phpstan)), 'A trait with no using class is no longer mago-only, or the trait it is declared in stopped being named.');
        $this->assertSame(['TraitDivergence\AlsoUsesIt::inTrait', 'TraitDivergence\UsesTheTrait::inTrait'], array_values(array_diff($phpstan, $mago)));
    }

    /**
     * What the divergence costs a real shipped rule, counted at one span.
     *
     * The three tests above measure the hook. This measures a plugin: `NoRouteTrailingSlashPathRule` emits
     * today, and it gates on the enclosing class being a controller — one of the seven corpus rules on this
     * hook that do. In a trait the port asks that question of the trait, which extends nothing, so before
     * `Declares::traitUsers()` the rule went *silent* there rather than merely reporting once instead of
     * twice: one finding of PHPStan's three.
     *
     * It is now two of three, and the assertion says which one is missing rather than only how many. What
     * closes the last one is the emitted body reporting per using class, which is a code-generation change;
     * answering the guard differently cannot produce a second report.
     *
     * Two users here is not a corner: 51 of Shopware's 60 used traits have two or more, one of them 1185.
     * VERIFICATION.md carries the distribution and the question it raises about what agreement would emit.
     *
     * This is the control that fix needs and the corpus differential cannot be: that instrument keys findings
     * on `file:line`, so N reports at one span and one report at one span compare equal there. Here the count
     * is the assertion, so a fix that works changes this test and a fix that does nothing does not.
     */
    public function test_a_shipped_rule_reports_a_trait_route_once_where_phpstan_reports_it_per_user(): void
    {
        $sandbox = $this->ruleSandbox();

        $this->assertSame([
            'Routes.php:41',
            'Routes.php (in context of class Examples\Controllers\FirstController):22',
            'Routes.php (in context of class Examples\Controllers\SecondController):22',
        ], $this->phpstanRouteFindings($sandbox), 'PHPStan no longer reports a trait-declared route once per using controller.');

        // Line 22 is the trait's route and 41 the class-declared control. The port reaches the trait now and
        // reports it once; PHPStan reports it twice, once per using controller. That one finding is the whole
        // of what is left, and it is under-reporting rather than over-reporting.
        //
        $found = $this->magoRouteFindings($sandbox);

        // The output goes in the message, because the first CI run of this test failed with an empty list and
        // nothing to say why. A findings list that came back empty can mean the plugin declined, the worker
        // never started, or the binary is not there, and those want different fixes — an assertion that only
        // prints `[]` sends the reader to the wrong one.
        $this->assertSame(['Routes.php:22', 'Routes.php:41'], $found, "The emitted plugin no longer reports the trait-declared route alongside the class-declared one.\n"
        . "mago output:\n" . $this->magoRouteOutput);

        // The message carries what the second report would have said, which is the deliberate divergence
        // here: one readable finding naming its users, rather than N identical lines. Asserted, because the
        // whole point of choosing it is that a reader can act on it.
        $this->assertStringContainsString('(via Examples\Controllers\FirstController, Examples\Controllers\SecondController)', implode("\n", $this->magoRouteMessages), 'The trait finding no longer names the classes that made its guard pass.');
    }

    /** @return list<string> */
    private function phpstanRouteFindings(string $sandbox): array
    {
        file_put_contents($sandbox . '/phpstan.neon', <<<NEON
            parameters:
                level: 0
                paths:
                    - src
                scanFiles:
                    - stubs/Framework.php
                    - stubs/Helpers.php
            services:
                -
                    class: Symplify\PHPStanRules\Rules\Symfony\NoRouteTrailingSlashPathRule
                    tags: [phpstan.rules.rule]
            NEON);

        $output = $this->capture([
            $this->root() . '/vendor/bin/phpstan',
            'analyse', '-c', 'phpstan.neon', '--no-progress', '--error-format=json',
        ], $sandbox);

        /** @var array{files?: array<string, array{messages: list<array{message: string, line: int}>}>} $decoded */
        $decoded = json_decode($output, true) ?? [];

        $found = [];
        foreach ($decoded['files'] ?? [] as $file => $info) {
            foreach ($info['messages'] as $message) {
                if (str_contains($message['message'], 'trailing slash')) {
                    $found[] = basename($file) . ':' . $message['line'];
                }
            }
        }

        return $found;
    }

    /** The raw mago output of the last {@see magoRouteFindings()} call, for saying what a failure saw. */
    private string $magoRouteOutput = '';

    /**
     * The decoded messages of that call, for asserting text.
     *
     * Decoded rather than matched against the raw output: JSON escapes a backslash, so a class name reads
     * `Examples\\Controllers\\FirstController` there and matches nothing spelled the way the plugin emits it.
     *
     * @var list<string>
     */
    private array $magoRouteMessages = [];

    /**
     * @return list<string>
     *
     * Read from `--reporting-format=json`, not from the listing. The compact listing puts the file, line and
     * message on one line locally and splits them across two under CI, so a parser written against what a
     * terminal shows found the message and no location and reported *nothing* — including the class-declared
     * control, which made a working plugin look dead. The JSON is the same everywhere, and it is the shape
     * `CorpusDifferential` already reads for the same reason.
     */
    private function magoRouteFindings(string $sandbox): array
    {
        $output = $this->capture(
            [$this->root() . '/vendor/bin/mago', 'analyze', '--reporting-format=json'],
            $sandbox,
        );
        $this->magoRouteOutput = $output;
        $this->magoRouteMessages = [];

        /** @var array{issues?: list<array{message?: string, annotations?: list<array{span?: array{file_id?: array{name?: string}, start?: array{line?: int}}}>}>} $decoded */
        $decoded = json_decode($output, true) ?? [];

        $found = [];
        foreach ($decoded['issues'] ?? [] as $issue) {
            if (! str_contains($issue['message'] ?? '', 'trailing slash')) {
                continue;
            }

            $this->magoRouteMessages[] = $issue['message'] ?? '';
            $span = $issue['annotations'][0]['span'] ?? [];
            $file = $span['file_id']['name'] ?? '';
            // Plus one: mago's JSON span counts lines from zero and its own listing counts from one, which is
            // also why PHPStan's numbers sit one higher in the assertion above. `CorpusDifferential` makes the
            // same correction at the same field, and the two sides are only comparable once it is made.
            $line = ((int) ($span['start']['line'] ?? 0)) + 1;
            $found[] = basename($file) . ':' . $line;
        }

        sort($found);

        return $found;
    }

    /**
     * The rule fixture, with the rule transpiled into it and the example stubs beside it.
     *
     * The stubs sit next to `src` rather than in it for the reason the per-rule gate gives: mago needs them
     * in its source paths to resolve `AbstractController`, and PHPStan must scan them without analysing them.
     */
    private function ruleSandbox(): string
    {
        $sandbox = sys_get_temp_dir() . '/trait-rule-' . bin2hex(random_bytes(6));
        mkdir($sandbox . '/src', 0o777, true);
        mkdir($sandbox . '/stubs', 0o777, true);
        copy(self::FIXTURES . '/rule/Routes.php', $sandbox . '/src/Routes.php');

        $stubs = glob($this->root() . '/tests/Fixtures/examples/stubs/*.php');
        foreach ($stubs === false ? [] : $stubs as $stub) {
            copy($stub, $sandbox . '/stubs/' . basename($stub));
        }

        $rule = $this->root()
            . '/vendor/symplify/phpstan-rules/src/Rules/Symfony/NoRouteTrailingSlashPathRule.php';

        // Through `php` rather than the shebang. The file is executable in git, but the transpile failing for
        // any reason at all used to surface here as an empty findings list two steps later.
        $transpiled = $this->capture(
            ['php', $this->root() . '/bin/phpstan-to-mago', '--target=php', '--out=gen', $rule],
            $sandbox,
        );

        $plugin = $sandbox . '/gen/generated-php/NoRouteTrailingSlashPathRule.php';
        if (! is_file($plugin)) {
            throw new RuntimeException("The rule did not transpile, so there is no plugin to run:\n" . $transpiled);
        }

        mkdir($sandbox . '/plugins', 0o777, true);
        copy($plugin, $sandbox . '/plugins/rule.php');

        $autoload = $this->root() . '/vendor/autoload.php';
        file_put_contents($sandbox . '/worker.php', <<<PHP
            <?php
            require '{$autoload}';
            require __DIR__ . '/plugins/rule.php';
            (new Mago\Sdk\Worker(new Mago\Sdk\Extension(
                'gate/trait', 'Trait control', '0.0.0',
                analyzerPlugins: [new \Transpiled\NoRouteTrailingSlashPathRule()],
            )))->run();
            PHP);
        file_put_contents($sandbox . '/mago.toml', <<<'TOML'
            [source]
            paths = ["src", "stubs"]

            [extension-hosts.gate]
            command = ["php", "worker.php"]
            TOML);
        symlink($this->root() . '/vendor', $sandbox . '/vendor');

        return $sandbox;
    }

    /**
     * @param list<string> $command
     */
    private function capture(array $command, string $sandbox): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $sandbox, Subprocess::environment());
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout === '' ? $stderr : $stdout;
    }

    /**
     * @param callable(string, string): void $run takes the sandbox and the log path
     *
     * @return list<string>
     */
    private function firings(callable $run): array
    {
        $sandbox = $this->sandbox();
        $log = $sandbox . '/fired.txt';
        $run($sandbox, $log);

        if (! is_file($log)) {
            throw new RuntimeException('The probe wrote nothing, so the run did not reach the hook.');
        }

        $lines = explode("\n", (string) file_get_contents($log));
        $fired = array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));
        sort($fired);

        return $fired;
    }

    private function runPhpstan(string $sandbox, string $log): void
    {
        file_put_contents($sandbox . '/phpstan.neon', <<<NEON
            parameters:
                level: 0
                paths:
                    - src
            services:
                -
                    class: TraitDivergence\PhpstanProbe
                    tags: [phpstan.rules.rule]
            NEON);

        $this->execute([
            $this->root() . '/vendor/bin/phpstan',
            'analyse',
            '-c', 'phpstan.neon',
            '--no-progress',
            '-a', self::FIXTURES . '/PhpstanProbe.php',
        ], $sandbox, $log);
    }

    private function runMago(string $sandbox, string $log): void
    {
        $host = self::FIXTURES . '/mago-host.php';
        file_put_contents($sandbox . '/mago.toml', <<<TOML
            [source]
            paths = ["src"]

            [extension-hosts.probe]
            command = ["php", "{$host}"]
            workers = 1
            TOML);

        $this->execute([$this->root() . '/vendor/bin/mago', 'analyze'], $sandbox, $log);
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command, string $sandbox, string $log): void
    {
        $environment = [
            ...Subprocess::environment(),
            'TRAIT_PROBE_OUT' => $log,
            'TRAIT_PROBE_AUTOLOAD' => $this->root() . '/vendor/autoload.php',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $sandbox, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Both tools exit non-zero when they report, and the fixture is written to report nothing at all --
        // but the analyzers this repository installs run over it too. The log is what says the run worked.
        proc_close($process);
    }

    /** A fresh directory per run, because a log appended to twice would read as a doubled firing. */
    private function sandbox(): string
    {
        $sandbox = sys_get_temp_dir() . '/trait-divergence-' . bin2hex(random_bytes(6)) . '/src';
        mkdir($sandbox, 0o777, true);
        copy(self::FIXTURES . '/src/Subjects.php', $sandbox . '/Subjects.php');

        return dirname($sandbox);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
