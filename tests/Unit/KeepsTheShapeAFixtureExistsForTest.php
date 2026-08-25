<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Example files whose whole point is a spelling a formatter would rather change.
 *
 * Three times in one week a fixture was written to exercise a shape, and pint rewrote the shape away —
 * `array($this, 'handle')` to `[..]`, `__CONSTRUCT` to `__construct`, a backtick to `shell_exec()`. Each
 * time the suite stayed green, because the pair still passed: the case had simply stopped existing.
 *
 * `notPath` in `pint.json` stops the rewrite. This stops the *silence* if one is ever removed from that
 * list, or if the file is edited by hand — a fixture that no longer holds its shape has to fail rather
 * than pass for nothing.
 */
final class KeepsTheShapeAFixtureExistsForTest extends TestCase
{
    private const string EXAMPLES = __DIR__ . '/../Fixtures/examples';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        // `array(..)` is a different node kind to Mago than `[..]`, and the rule missed it on Shopware.
        yield 'the legacy array spelling' => [
            'ForbiddenArrayMethodCallRule/BadArrayCallable.php',
            "array(\$this, 'handle')",
        ];
        // The rule folds case with `toLowerString()`, and the port compared the selector as written.
        yield 'a differently cased magic method' => [
            'IllegalConstructorMethodCallRule/Bad.php',
            '__CONSTRUCT(',
        ];
        // The construct the rule forbids, which pint rewrites into the one it recommends.
        yield 'the backtick operator' => [
            'DisallowedBacktickRule/Bad.php',
            '`ls -la`',
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_example_still_holds_the_shape_it_was_written_for(string $file, string $shape): void
    {
        $path = self::EXAMPLES . '/' . $file;

        $this->assertFileExists($path);
        $this->assertStringContainsString(
            $shape,
            (string) file_get_contents($path),
            "{$file} no longer contains {$shape}, so the case it exists to exercise is gone and its pair "
            . 'passes for nothing.',
        );
    }
}
