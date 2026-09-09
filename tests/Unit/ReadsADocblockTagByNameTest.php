<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\DocblockTags;

/**
 * A tag name matches exactly, which is the whole difference between this and a substring search.
 *
 * Asking the parser for the covers tag does not match the longer names beside it -- covers-nothing and
 * covers-default-class -- and three rules in the corpus depend on that: a test marked covers-nothing must
 * not read as covering something unnamed, and a class carrying both a default class and a covers list must
 * not have them merged. The controls below are those near-miss names rather than unrelated ones, because an
 * unrelated name passes a substring search too.
 *
 * `PhpUnitAnnotations` records the same distinction from the other side: its `[a-zA-Z]+` is greedy, so the
 * longer name captures whole and never enters its list of annotations that take a value.
 *
 * Tag names are written without their at-sign in this docblock. The package under test parses one anywhere
 * in a comment, not only at the start of a line, so naming a tag here writes a real one and reports it as
 * invalid -- the fourth time that has happened while building this, after the fixture's prose, another
 * test's docblock, and the sentence added to warn about it.
 */
#[CoversClass(DocblockTags::class)]
final class ReadsADocblockTagByNameTest extends TestCase
{
    private const string DOCBLOCK = <<<'DOC'
        /**
         * Prose that mentions covers without an at-sign.
         *
         * @covers \First\Target
         * @coversNothing
         * @coversDefaultClass \Default\Target
         * @covers \Second::method
         * @covers
         * @uses \Third\Target
         */
        DOC;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function tags(): iterable
    {
        // The bare `@covers` contributes an empty value rather than being skipped, because the original
        // reports on it: "@covers value does not specify anything."
        yield 'covers, and not its two longer neighbours' => ['covers', ['\First\Target', '\Second::method', '']];
        yield 'the default class, on its own' => ['coversDefaultClass', ['\Default\Target']];
        yield 'a valueless tag' => ['coversNothing', ['']];
        yield 'another tag entirely' => ['uses', ['\Third\Target']];
        yield 'a tag nothing writes' => ['dataProvider', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('tags')]
    public function test_reads_the_values_of_one_tag(string $tag, array $expected): void
    {
        $this->assertSame($expected, DocblockTags::in(self::DOCBLOCK, $tag));
    }

    public function test_a_missing_docblock_is_not_an_empty_one(): void
    {
        $this->assertSame([], DocblockTags::in(null, 'covers'));
    }

    public function test_prose_mentioning_the_tag_name_is_not_a_tag(): void
    {
        $this->assertSame([], DocblockTags::in("/**\n * This covers a lot of ground.\n */", 'covers'));
    }
}
