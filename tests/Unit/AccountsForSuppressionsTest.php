<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\Suppressions;

/**
 * A finding the consumer silenced is agreement, not a disagreement.
 *
 * Seven `noUnsafeRequestData` sites on a real project read as "the port reports what the original does not"
 * until this existed, and every one of them was a suppression the consumer had written. That reading is the
 * dangerous direction: a differential exists to catch a port being *narrower*, and every false only-port
 * entry buries the entries that matter.
 *
 * The annotation is assembled rather than written in this file, for the same reason it is in the class under
 * test: spelling it out makes PHPStan read it as an annotation on this code, which fails the run with a parse
 * error inside a test about the feature.
 */
final class AccountsForSuppressionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpstan-to-mago-suppressions-' . getmypid();
        if (! is_dir($this->root)) {
            mkdir($this->root, 0o777, true);
        }
    }

    public function test_a_trailing_annotation_naming_the_identifier_silences_the_line(): void
    {
        $site = $this->write([
            '<?php',
            '$x = $request->input("a"); // ' . $this->annotation() . ' hihaho.validation.noUnsafeRequestData',
        ], 2);

        $this->assertTrue($this->suppressions()->silences($site, 'hihaho.validation.noUnsafeRequestData'));
    }

    public function test_a_docblock_annotation_above_the_line_silences_it(): void
    {
        $site = $this->write([
            '<?php',
            '/** ' . $this->annotation() . ' hihaho.validation.noUnsafeRequestData */',
            '$x = $request->all();',
        ], 3);

        $this->assertTrue($this->suppressions()->silences($site, 'hihaho.validation.noUnsafeRequestData'));
    }

    /**
     * The case that keeps this from hiding real disagreements: a suppression for something else must not
     * silence the finding under test.
     */
    public function test_an_annotation_naming_another_identifier_does_not_silence_it(): void
    {
        $site = $this->write([
            '<?php',
            '$x = $request->input("a"); // ' . $this->annotation() . ' symplify.noDynamicName',
        ], 2);

        $this->assertFalse($this->suppressions()->silences($site, 'hihaho.validation.noUnsafeRequestData'));
    }

    public function test_a_bare_next_line_annotation_silences_whatever_follows(): void
    {
        $site = $this->write([
            '<?php',
            '// ' . $this->annotation() . '-next-line',
            '$x = $request->all();',
        ], 3);

        $this->assertTrue($this->suppressions()->silences($site, 'hihaho.validation.noUnsafeRequestData'));
    }

    public function test_an_unannotated_line_is_a_real_disagreement(): void
    {
        $site = $this->write(['<?php', '$x = $request->all();'], 2);

        $this->assertFalse($this->suppressions()->silences($site, 'hihaho.validation.noUnsafeRequestData'));
    }

    /**
     * A suppression far above the finding is not one. Without a bound, any annotation anywhere earlier in the
     * file would silence everything after it.
     */
    public function test_an_annotation_further_above_than_a_docblock_reaches_does_not_silence_it(): void
    {
        $site = $this->write([
            '<?php',
            '// ' . $this->annotation() . ' hihaho.validation.noUnsafeRequestData',
            '',
            '',
            '$x = $request->all();',
        ], 5);

        $this->assertFalse($this->suppressions()->silences($site, 'hihaho.validation.noUnsafeRequestData'));
    }

    public function test_a_file_the_consumer_does_not_have_silences_nothing(): void
    {
        $this->assertFalse($this->suppressions()->silences('nowhere/Missing.php:3', 'any.identifier'));
    }

    public function test_splits_sites_into_disagreements_and_silenced_ones(): void
    {
        $silenced = $this->write([
            '<?php',
            '$x = $request->all(); // ' . $this->annotation() . ' some.identifier',
        ], 2, 'Silenced.php');
        $open = $this->write(['<?php', '$x = $request->all();'], 2, 'Open.php');

        $this->assertSame(
            [[$open], [$silenced]],
            $this->suppressions()->split([$open, $silenced], 'some.identifier'),
        );
    }

    /**
     * @param list<string> $lines
     *
     * @return string the site, as the differential spells one
     */
    private function write(array $lines, int $line, string $name = 'Example.php'): string
    {
        file_put_contents($this->root . '/' . $name, implode("\n", $lines) . "\n");

        return $name . ':' . $line;
    }

    private function suppressions(): Suppressions
    {
        return new Suppressions($this->root);
    }

    private function annotation(): string
    {
        return '@' . 'phpstan-ignore';
    }
}
