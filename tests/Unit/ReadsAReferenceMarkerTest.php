<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\ReferenceKnowledge;

/**
 * Which `&` positions mago's tree holds, and which a port has to read out of the text.
 *
 * php-parser answers `->byRef` with a boolean field on eight node classes, and several rules in the corpus
 * read it — `NoReferenceRule` reads it on seven kinds. Mago has no such field, and the routes to it are not
 * one route: the ampersand is a `UnaryPrefixOperator` in three positions and absent from the tree in two,
 * where the only evidence is the source text in front of the name.
 *
 * **A port written to either route alone is wrong for the other, and wrong by going quiet.** A structural
 * predicate on a parameter finds nothing and reports a by-reference parameter as by-value; a text predicate
 * looking for a leading `&` is wrong the moment a type hint stands in front of it — `array &$xs` does not
 * begin with an ampersand. Both mistakes are silent.
 *
 * Every row has a by-value control differing in nothing but the ampersand, in the same file, so a predicate
 * that passes for the wrong reason cannot pass here. `paramVariadic` is the row that discriminates the naive
 * text test: `int &...$refVariadic` puts a hint, an ampersand and an ellipsis in the same window.
 *
 * The two positions with no node need the same window `Runtime\Declarations::isVariadic()` already reads for
 * `...`, which is the precedent rather than a coincidence: neither marker is a node.
 *
 * @see ObservesAnUnknownAncestryTest for the same probe shape aimed at ancestry
 */
#[Group('engine')]
final class ReadsAReferenceMarkerTest extends TestCase
{
    /** @var array<string, string>|null */
    private static ?array $rows = null;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function positions(): iterable
    {
        // `$r = &$a` — the ampersand is a node, one level below the immediate children. Reading only
        // `getChildren()` stops at an `Expression` whose text is `&$a` and reads as no node at all.
        yield 'an assignment by reference' => ['Assignment:$refAssign', "node=yes\tbefore="];
        yield 'an assignment by value' => ['Assignment:$valueAssign', "node=no\tbefore="];
        // The row that makes the operator's *text* load-bearing. A predicate asking only whether a
        // `UnaryPrefixOperator` is present answers yes here, and mutating the `&` comparison away failed
        // nothing until this row existed.
        yield 'an assignment of a negation' => ['Assignment:$negatedAssign', "node=no\tbefore="];

        // `[&$a]` and `foreach (.. as &$v)` carry it the same way.
        yield 'an array element by reference' => ['ArrayElement:$refItem', "node=yes\tbefore=&"];
        yield 'an array element by value' => ['ArrayElement:$valueItem', "node=no\tbefore="];
        yield 'an array element of a negation' => ['ArrayElement:$negatedItem', "node=no\tbefore=-"];
        yield 'a foreach value by reference' => ['ForeachValueTarget:$refValue', "node=yes\tbefore=&"];
        yield 'a foreach value by value' => ['ForeachValueTarget:$valueValue', "node=no\tbefore="];

        // A parameter carries nothing. The child set is identical to the by-value control's, so `before` is
        // the only evidence — and on the hinted rows it is not a prefix of the parameter's own text.
        yield 'a bare parameter by reference' => ['FunctionLikeParameter:$refBare', "node=no\tbefore=&"];
        yield 'a bare parameter by value' => ['FunctionLikeParameter:$valueBare', "node=no\tbefore="];
        yield 'a hinted parameter by reference' => ['FunctionLikeParameter:$refHinted', "node=no\tbefore=array &"];
        yield 'a hinted parameter by value' => ['FunctionLikeParameter:$valueHinted', "node=no\tbefore=array"];
        yield 'a variadic parameter by reference' => ['FunctionLikeParameter:$refVariadic', "node=no\tbefore=int &..."];
        yield 'a variadic parameter by value' => ['FunctionLikeParameter:$valueVariadic', "node=no\tbefore=int ..."];

        // Returning by reference is the fifth position, and it is a gap read too — between the `function`
        // keyword and the method's name.
        yield 'a method returning by reference' => ['Method:returnsByRef', "node=no\tbefore=public function &"];
        yield 'a method returning by value' => ['Method:returnsByValue', "node=no\tbefore=public function"];
    }

    #[DataProvider('positions')]
    public function test_reports_where_the_ampersand_lives(string $site, string $expected): void
    {
        $rows = $this->rows();

        $this->assertArrayHasKey($site, $rows, "The probe never saw {$site}, so nothing was observed there.");
        $this->assertSame($expected, $rows[$site]);
    }

    /** @return array<string, string> */
    private function rows(): array
    {
        return self::$rows ??= (new ReferenceKnowledge(
            __DIR__ . '/../Fixtures/reference',
            dirname(__DIR__, 2),
        ))->rows();
    }
}
