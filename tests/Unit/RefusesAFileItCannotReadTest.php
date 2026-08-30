<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * A path that names no file is refused by name, without a PHP warning.
 *
 * The warning is the part that matters, not the tidiness. An emitted plugin talks to mago over a binary
 * protocol on stdout, and anything else written there corrupts the frame: a worker that printed one failed
 * with `invalid extension frame magic: 0a576172`, which is `\nWar` -- the first bytes of `Warning:`. The
 * transpiler and the worker are different processes, so this particular warning could not reach that
 * protocol, but the class of bug is the same one and the fix is to never emit the warning.
 *
 * It also reached a consumer as a broken `composer` script: a regeneration command naming a rule from a
 * package the project had not installed printed a `file_get_contents` warning and then refused with
 * `no class found`, which names neither the file nor the reason.
 */
final class RefusesAFileItCannotReadTest extends TestCase
{
    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    public function test_a_missing_file_is_refused_by_name(): void
    {
        $missing = sys_get_temp_dir() . '/phpstan-to-mago-absent-' . bin2hex(random_bytes(6)) . '.php';

        $this->expectException(Refusal::class);
        $this->expectExceptionMessage($missing);

        (new Transpiler($missing))->transpile();
    }

    public function test_a_missing_file_emits_no_php_warning(): void
    {
        $missing = sys_get_temp_dir() . '/phpstan-to-mago-absent-' . bin2hex(random_bytes(6)) . '.php';

        // The assertion is on the *output*, not on the exception. `file_get_contents` on a missing path
        // raises a warning and then returns false, so a refusal alone does not prove the warning is gone --
        // the previous code refused too, one line after printing it.
        $warned = false;
        set_error_handler(static function (int $severity, string $message) use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            (new Transpiler($missing))->transpile();
        } catch (Refusal) {
            // The refusal is the other test's subject.
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($warned, 'Naming a missing file still raises a PHP warning before refusing.');
    }
}
