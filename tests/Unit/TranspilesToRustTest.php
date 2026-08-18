<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * The Rust target, kept as the anchor that a refactor has not changed behaviour.
 *
 * Generated Rust only runs compiled inside Mago's analyzer crate, so it cannot ship as a package and is
 * not the target anyone should reach for. It stays because both targets share the whole body translation:
 * if a change to that shows up nowhere in the PHP snapshots, it will show up here.
 */
final class TranspilesToRustTest extends TestCase
{
    private const string RULES = __DIR__ . '/../Fixtures/Rules';

    private const string EXPECTED = __DIR__ . '/../Fixtures/expected-rust';

    protected function setUp(): void
    {
        Transpiler::$target = 'analyzer';
        Transpiler::$survey = false;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedRules(): iterable
    {
        yield 'guard chain' => ['ForbiddenStaticConstFetchRule'];
        yield 'loop with a formatted message' => ['UppercaseConstantRule'];
    }

    #[DataProvider('supportedRules')]
    public function test_emits_the_reviewed_rule(string $rule): void
    {
        $emitted = (new Transpiler(self::RULES . '/' . $rule . '.php'))->transpile()['rust'];

        $this->assertSame(file_get_contents(self::EXPECTED . '/' . $rule . '.rs'), $emitted . "\n", "Emitted Rust for {$rule} differs from the reviewed snapshot.");
    }
}
