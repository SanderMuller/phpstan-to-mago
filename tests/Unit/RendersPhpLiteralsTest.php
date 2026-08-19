<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\PhpBackend;

/**
 * `PhpBackend::bytes()` — the one place a literal crosses from Rust's spelling into PHP's.
 *
 * Every case here stands for a way the two spellings differ. The interesting one is the class name: this
 * method used to call `stripcslashes()`, which undoes a doubled backslash *and* interprets a C escape, so
 * `Doctrine\Bundle\DoctrineBundle\...` arrived as `DoctrineBundleDoctrineBundle...` and an emitted rule
 * reported a class that does not exist. It landed on the right line with the wrong text, so nothing short of
 * comparing message text noticed.
 */
final class RendersPhpLiteralsTest extends TestCase
{
    private PhpBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new PhpBackend();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literals(): iterable
    {
        // Escaped for a Rust byte string, which is what most callers hand over.
        yield 'a doubled backslash collapses to one' => ['Doctrine\\\\Bundle\\\\X', "'Doctrine\\\\Bundle\\\\X'"];
        yield 'an escaped double quote loses its backslash' => ['say \\"hi\\" now', "'say \"hi\" now'"];
        yield 'both at once' => ['extend \\"A\\\\B\\"', "'extend \"A\\\\B\"'"];

        // Written as-is, which the class-name callers hand over.
        yield 'a raw class name keeps every separator' => ['Doctrine\\Bundle\\X', "'Doctrine\\\\Bundle\\\\X'"];
        yield 'a raw backslash before a letter survives' => ['A\\nB', "'A\\\\nB'"];

        // PHP's own quoting.
        yield 'an apostrophe is escaped for a single-quoted string' => ["it's", "'it\\'s'"];
        yield 'plain text is untouched' => ['Avoid static access', "'Avoid static access'"];
        yield 'an empty literal stays empty' => ['', "''"];
    }

    #[DataProvider('literals')]
    public function test_renders_a_literal_as_a_php_single_quoted_string(string $literal, string $expected): void
    {
        $this->assertSame($expected, $this->backend->bytes($literal));
    }

    /**
     * The rendered literal has to be PHP that parses, and parse back to the intended string.
     *
     * Asserting on the rendered text alone would pass a literal that is quoted wrongly, so this reads the
     * value back. Parsed with the same parser the transpiler reads rules with rather than evaluated: the
     * question is what the emitted file means when PHP reads it, and a parser answers that without running
     * anything.
     */
    #[DataProvider('literals')]
    public function test_the_rendered_literal_parses_back_to_the_intended_string(string $literal, string $expected): void
    {
        $this->assertSame(
            $this->valueOf($expected),
            $this->valueOf($this->backend->bytes($literal)),
            'The rendered literal does not read back as the string it stands for.',
        );
    }

    /** The string a rendered PHP literal denotes, read through php-parser. */
    private function valueOf(string $rendered): string
    {
        $statements = (new ParserFactory())->createForHostVersion()->parse('<?php return ' . $rendered . ';');

        $this->assertIsArray($statements, "The rendered literal {$rendered} is not parseable PHP.");
        $return = $statements[0] ?? null;
        $this->assertInstanceOf(Return_::class, $return);
        $this->assertInstanceOf(String_::class, $return->expr);

        return $return->expr->value;
    }

    public function test_a_namespaced_class_name_keeps_its_separators_either_way_it_arrives(): void
    {
        // Deliberately a namespace no class occupies. A real one would let Rector rewrite the literal into
        // a `::class` constant, and the point here is a *string* that arrives with separators in it.
        $written = 'Acme\Bundle\AcmeBundle\Repository\ServiceEntityRepository';

        // A caller that escaped for Rust first, and one that did not, must agree — the regression that
        // made an emitted rule name a class nobody could find.
        $this->assertSame(
            $this->backend->bytes(str_replace('\\', '\\\\', $written)),
            $this->backend->bytes($written),
        );

        $this->assertSame($written, $this->valueOf($this->backend->bytes($written)));
    }
}
