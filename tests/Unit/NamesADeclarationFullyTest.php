<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\SourceIndex;

/**
 * Where a class-like is declared, so a vocabulary table can be keyed on a name that cannot collide.
 *
 * `Vocabulary::CROSS_FILE_CHECKS` used to key on a short class name, guarded by a substring check against the
 * rule's path. That made a collision across two packages unlikely rather than impossible, and the failure it
 * left open is the bad kind: two corpora shipping a trait with the same short name and method would get each
 * other's hand-written pass — a wrong emission, not a refusal.
 *
 * This transpiler does not run php-parser's `NameResolver`, so a `ClassLike` node carries no
 * `namespacedName`; the namespace has to be read from the file that declared it.
 */
final class NamesADeclarationFullyTest extends TestCase
{
    public function test_reads_the_namespace_a_class_is_declared_in(): void
    {
        $ast = $this->parse('<?php namespace App\Rules; final class Thing {}');

        $this->assertSame('App\Rules', SourceIndex::namespaceOf($ast, 'Thing'));
    }

    public function test_reads_the_namespace_a_trait_is_declared_in(): void
    {
        $ast = $this->parse('<?php namespace App\Traits; trait Helps {}');

        $this->assertSame('App\Traits', SourceIndex::namespaceOf($ast, 'Helps'));
    }

    public function test_a_file_without_a_namespace_has_none(): void
    {
        $ast = $this->parse('<?php final class Thing {}');

        $this->assertNull(SourceIndex::namespaceOf($ast, 'Thing'));
    }

    /**
     * The case the short-name key could not tell apart: one file, two namespaces, the same short name. A
     * lookup answering with whichever came first would hand a caller the wrong declaration.
     */
    public function test_picks_the_namespace_that_declares_the_name_asked_for(): void
    {
        $ast = $this->parse(
            '<?php namespace One { trait Helps {} } namespace Two { trait Other {} }',
        );

        $this->assertSame('One', SourceIndex::namespaceOf($ast, 'Helps'));
        $this->assertSame('Two', SourceIndex::namespaceOf($ast, 'Other'));
    }

    public function test_a_name_the_file_does_not_declare_has_no_namespace(): void
    {
        $ast = $this->parse('<?php namespace App\Rules; final class Thing {}');

        $this->assertNull(SourceIndex::namespaceOf($ast, 'Missing'));
    }

    /**
     * @return Stmt[]
     */
    private function parse(string $code): array
    {
        return (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
    }
}
