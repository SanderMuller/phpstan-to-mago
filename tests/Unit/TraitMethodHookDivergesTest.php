<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
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

        self::assertSame([
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

        self::assertSame([
            'TraitDivergence\ATrait::inTrait',
            ...self::AGREED,
        ], $fired, 'The mago method hook no longer names the trait a method is declared in.');
    }

    /**
     * The gap itself, stated as the two sets rather than as a count.
     *
     * Read from the same two runs the tests above assert, so it cannot drift from them.
     */
    public function test_the_two_engines_name_a_different_class_for_the_same_trait_method(): void
    {
        $phpstan = $this->firings($this->runPhpstan(...));
        $mago = $this->firings($this->runMago(...));

        self::assertSame(self::AGREED, array_values(array_intersect($phpstan, $mago)));
        self::assertSame(['TraitDivergence\ATrait::inTrait'], array_values(array_diff($mago, $phpstan)));
        self::assertSame(
            ['TraitDivergence\AlsoUsesIt::inTrait', 'TraitDivergence\UsesTheTrait::inTrait'],
            array_values(array_diff($phpstan, $mago)),
        );
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
