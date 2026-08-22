<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;
use Sandermuller\PhpstanToMago\Tests\Support\CoverageControl;

/**
 * The parameter aggregate, control by control, against the rule it came from.
 *
 * Every row here was a disagreement first. `ParamTypeDeclarationCollector` looks like four lines of counting
 * and is not: its node type is `FunctionLike`, PHPStan analyses a trait's body once per using class, and
 * `CollectorDataNormalizer` sums the records without deduplicating. A reimplementation that counts each
 * declaration once agreed exactly on a two-file fixture and then answered 3079 where the original said 4057.
 *
 * So these are not fixtures written to pass. Each is the smallest project that separates one counting rule
 * from the next, and the number on the left is whatever the real rule says about it.
 *
 * @see TypeCoverage::timesCounted()
 */
final class CountsParametersLikeTheCollectorTest extends TestCase
{
    private const string CONTROLS = __DIR__ . '/../Fixtures/aggregate/controls';

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function controls(): iterable
    {
        // A trait method is analysed once per using class, so its parameters arrive three times.
        yield 'a trait method and three using classes' => ['three-users', 6];
        // And zero times for a trait nobody uses: the body is never analysed in any class's context.
        yield 'a trait nobody uses' => ['no-users', 0];
        // Once, for the one class at the end of the chain — not once per link in it.
        yield 'a trait used by a trait used by a class' => ['trait-in-trait', 2];
        // Once, for the class that writes the `use`. A subclass does not count its parent's trait again.
        yield 'a trait on an abstract base with two subclasses' => ['inherited-trait', 2];
        // The interface's own declaration counts; the trait's is skipped for the class that implements it and
        // counted for the class that does not. Two of the three declarations, so four.
        yield 'a trait method whose name an interface declares' => ['locked-by-interface', 4];
        // The class's own method wins, so the trait's version is never analysed there.
        yield 'a trait method the using class overrides' => ['overridden-trait-method', 2];
        // The LSP guard tests `$node instanceof ClassMethod`, so it skips the method record and nothing else:
        // the closure and the arrow function inside that method are their own `FunctionLike` nodes and still
        // count. Two for the interface, two for the closure, one for the arrow function.
        yield 'a closure inside a method the guard skips' => ['closure-in-locked-method', 5];
        // An anonymous class has no name for the codebase to look up, and the guard still has to fire: only
        // the interface's own two parameters count.
        yield 'an anonymous class implementing an interface' => ['anonymous-class', 2];
        // `@param callable` skips the whole declaration, so only the other method's two parameters count.
        yield 'a docblock declaring a callable parameter' => ['docblock-callable', 2];
        // A declaration with no parameters contributes no record, and a variadic is taken back out of the
        // count rather than counted as untyped.
        yield 'no parameters, and a variadic one' => ['variadic-and-empty', 1];
        // `use T { m as other; }` leaves the class's own `m` winning that name while the trait's `m` is still
        // analysed in the class's context under the alias. Two for the plain user, two for the renaming user's
        // own method, two for the trait's inside it. Asking only about the original name counted the last of
        // those zero times, which was -2 on a real project's enum directory.
        yield 'a trait method reached under an alias' => ['aliased-trait-method', 6];
        // And the guard is asked about the *alias*. PHPStan reads the method node's name, which inside a
        // renamed trait method is the new one, so an interface declaring the original does not lock it: the
        // interface's own two, one each for the inner trait's two users, and the trait's two for the renaming
        // class only. Asking about the original instead skipped that last pair.
        yield 'an aliased trait method an interface declares' => ['aliased-trait-locked-by-interface', 6];
        // The discriminating one, and the reason the row above is not arithmetic. Here the interface declares
        // *only* the alias: asking the guard about the alias predicts 8, asking about the original predicts
        // 10, and the original counts 8. Written before the run rather than read off it.
        yield 'an interface declaring only the alias' => ['aliased-trait-locked-by-alias', 8];
    }

    #[DataProvider('controls')]
    public function test_counts_what_the_real_rule_counts(string $control, int $expected): void
    {
        [$original, $port] = (new CoverageControl(self::CONTROLS . '/' . $control))->totals();

        // The expected number is asserted against the original as well, so a `type-coverage` release that
        // changes the counting fails here instead of silently redefining what the port has to match.
        $this->assertSame($expected, $original, "The real rule no longer counts {$control} as {$expected}.");
        $this->assertSame($original, $port, "The port disagrees with the real rule on {$control}.");
    }
}
