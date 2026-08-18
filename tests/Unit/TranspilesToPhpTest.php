<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

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
     * @return iterable<string, array{string}>
     */
    public static function supportedRules(): iterable
    {
        yield 'guard chain' => ['ForbiddenStaticConstFetchRule'];
        yield 'loop with a formatted message' => ['UppercaseConstantRule'];
    }

    #[DataProvider('supportedRules')]
    public function test_emits_the_reviewed_plugin(string $rule): void
    {
        $emitted = $this->transpile($rule);

        $this->assertSame(file_get_contents(self::EXPECTED . '/' . $rule . '.php'), $emitted . "\n", "Emitted plugin for {$rule} differs from the reviewed snapshot.");
    }

    #[DataProvider('supportedRules')]
    public function test_emitted_plugin_is_valid_php(string $rule): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ptm') . '.php';
        file_put_contents($file, $this->transpile($rule));

        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        unlink($file);

        $this->assertSame(0, $status, "Emitted plugin for {$rule} does not parse: " . implode("\n", $output));
    }

    /**
     * A bare `snake_case` identifier is well formed PHP, so parsing is not enough: it reads as a
     * constant. Untranslated Rust is what produces those, and it must never reach a `.php` file.
     */
    #[DataProvider('supportedRules')]
    public function test_emitted_plugin_contains_no_rust(string $rule): void
    {
        $emitted = $this->transpile($rule);

        foreach (['support::', 'b"', 'Ok(', '&node.', '.iter()', '.as_slice()', '|item|'] as $marker) {
            $this->assertStringNotContainsString($marker, $emitted, "Rust leaked into {$rule}: {$marker}");
        }
    }

    /**
     * A missing helper is otherwise only found at analysis time, as a worker crash that names the rule
     * but not the helper.
     */
    #[DataProvider('supportedRules')]
    public function test_every_helper_it_calls_exists(string $rule): void
    {
        preg_match_all('/Support::([a-zA-Z]+)\(/', $this->transpile($rule), $matches);

        foreach (array_unique($matches[1]) as $helper) {
            $this->assertTrue(method_exists(Support::class, $helper), "Emitted plugin for {$rule} calls Support::{$helper}(), which does not exist.");
            $this->assertTrue((new ReflectionMethod(Support::class, $helper))->isPublic(), "Support::{$helper}() is not public.");
        }
    }

    public function test_refuses_a_construct_outside_the_vocabulary(): void
    {
        $this->expectException(Refusal::class);
        $this->expectExceptionMessage('->isString()');

        $this->transpile('UnsupportedRule');
    }

    private function transpile(string $rule): string
    {
        return (new Transpiler(self::RULES . '/' . $rule . '.php'))->transpile()['rust'];
    }
}
