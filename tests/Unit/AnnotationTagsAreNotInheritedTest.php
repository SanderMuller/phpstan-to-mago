<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\Subprocess;

/**
 * Whether PHPStan hands a class its parent's covers tag, which decides whether one primitive is enough.
 *
 * Naming a tag in this docblock writes a real one: an at-sign followed by `covers` at the start of a
 * line is parsed by the package under test and reported as invalid. That cost three runs here -- once in
 * the fixture, once in this docblock, and once in the sentence warning about it. Tags are named in prose
 * without the marker throughout.
 *
 * Five refusals in the census turn on reading annotation *tags* — the two `CoversExists` rules,
 * `SeeAnnotationToTestRule`, `NoJustPropertyAssignRule` and `DataProviderDeclarationRule`. The docblock
 * *text* is already available: `Runtime\Support::docblockText()` finds a declaration's own
 * `DocBlockComment` trivia, and two annotation rules emit through it today.
 *
 * **But that primitive is narrower than the one those five rules use.** `getResolvedPhpDoc()` resolves, and
 * `CoversHelper::getCoverAnnotations()` loops over the doc nodes — plural — while `docblockText()`
 * returns one declaration's own docblock by design: a member cannot inherit its neighbour's. If PHPStan
 * merged a parent's tags, a port on that primitive would report nothing where the original reports, which is
 * the silent-narrowing shape rather than a failure.
 *
 * Measured instead of read, because the observable behaviour is what a port has to match: **an arbitrary tag is reported only on the
 * declaration that writes it.** Three pairs below, both rules, each negative row
 * beside a positive control that must fire. So `docblockText()` is equivalent for this family and the gap
 * does not exist.
 *
 * Pinned as a test rather than recorded as a figure because it is a fact about an upstream package, and the
 * whole reason this repository keeps a corpus census is that those move. A phpstan-phpunit release that
 * starts resolving inherited tags turns the family's foundation from equivalent to narrow, silently, and this
 * is what would say so.
 */
#[Group('engine')]
#[CoversNothing]
final class AnnotationTagsAreNotInheritedTest extends TestCase
{
    /** @var array<int, string>|null line to identifier */
    private static ?array $findings = null;

    public function test_a_class_reports_its_own_covers_default_class(): void
    {
        $this->assertSame('phpunit.coversClass', $this->at(15), 'The control for the class rule stopped firing, so the negative rows below prove nothing.');
        $this->assertSame('phpunit.coversClass', $this->at(30));
    }

    public function test_a_class_does_not_report_its_parents_covers_default_class(): void
    {
        $this->assertNull(
            $this->at(20),
            'PHPStan now hands a child class its parent`s @coversDefaultClass. `Support::docblockText()` reads '
            . 'one declaration`s own docblock, so any tag reader built on it is now narrower than the rules '
            . 'that need it and would go silent rather than fail.',
        );
    }

    public function test_a_method_reports_its_own_covers_and_not_an_inherited_one(): void
    {
        $this->assertSame('phpunit.covers', $this->at(40), 'The control for the method rule stopped firing.');
        $this->assertSame('phpunit.covers', $this->at(56));
        $this->assertNull($this->at(45), 'PHPStan now resolves an inherited method-level @covers.');
    }

    private function at(int $line): ?string
    {
        return $this->findings()[$line] ?? null;
    }

    /** @return array<int, string> */
    private function findings(): array
    {
        if (self::$findings !== null) {
            return self::$findings;
        }

        $root = dirname(__DIR__, 2);
        $fixture = $root . '/tests/Fixtures/phpdoc-inheritance';
        $sandbox = sys_get_temp_dir() . '/phpstan-to-mago-phpdoc-' . getmypid();
        if (! is_dir($sandbox) && ! mkdir($sandbox, 0o777, true)) {
            self::fail('Could not create ' . $sandbox);
        }

        // The package's own rules arrive through `extension-installer`, so naming its neon here as well
        // makes PHPStan warn that the file is included twice and report nothing at all — a silent zero that
        // reads exactly like "not inherited", which is the answer this test exists to establish. It cost one
        // run to notice, and only because the control was silent too.
        file_put_contents($sandbox . '/phpstan.neon', <<<NEON
            parameters:
                level: 5
                paths:
                    - {$fixture}/src
                tmpDir: {$sandbox}/cache
            NEON);

        $process = proc_open(
            [$root . '/vendor/bin/phpstan', 'analyse', '-c', $sandbox . '/phpstan.neon', '--no-progress', '--error-format=json'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $sandbox,
            Subprocess::environment(),
        );
        if (! is_resource($process)) {
            self::fail('Could not start PHPStan');
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        /** @var array{files?: array<string, array{messages: list<array{line: int, identifier?: string}>}>}|null $decoded */
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            self::fail("PHPStan produced no JSON, so nothing was measured:\n" . substr($output, 0, 2000));
        }

        $findings = [];
        foreach ($decoded['files'] ?? [] as $file) {
            foreach ($file['messages'] as $message) {
                $findings[$message['line']] = (string) ($message['identifier'] ?? '');
            }
        }

        return self::$findings = $findings;
    }
}
