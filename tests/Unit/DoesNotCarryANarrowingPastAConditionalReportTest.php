<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Refusal;
use Sandermuller\PhpstanToMago\Translator;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * What a conditional report's condition narrows does not survive the block.
 *
 * A rule that asks two different questions about one local writes two conditional reports over it. The first
 * condition narrows the local to a node kind; the second reads the same local as another kind. Until the
 * restore was added, the first narrowing stood for the rest of the body, so the second condition's field read
 * resolved against the wrong kind — `Vocabulary::FIELDS` is keyed by node kind and
 * {@see Translator::rememberNarrowedKind()} is what picks the key.
 *
 * **The pair is ordered both ways on purpose, because the defect follows the order.** With the function test
 * first the refusal was `no node predicate for instanceof PhpParser\Node\Identifier on a name-expr`; with the
 * method test first it was `instanceof PhpParser\Node\Name on a member selector` — the mirror image, the
 * second helper's test read against the first helper's kind. Either rule alone translated cleanly, which is
 * what makes the pair the measurement rather than either file.
 *
 * `AssertSameWithCountRule` in `phpstan/phpstan-phpunit` is the corpus rule that writes this shape, testing
 * its second argument for `count(...)` and then for `->count()`. These fixtures are the same shape at thirty
 * lines, so a failure here names the mechanism rather than a rule.
 *
 * **A refusal is the good outcome and it was not the only one available.** A field read against the wrong
 * kind can also resolve, and then the plugin asks the wrong question and reports — the silent failure
 * `rememberNarrowedKind()`'s docblock records from the other side, where a missing `instanceof Identifier`
 * would have compared null against a literal and reported every import the original allows.
 */
#[CoversClass(Translator::class)]
final class DoesNotCarryANarrowingPastAConditionalReportTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function orderings(): iterable
    {
        yield 'the function test first' => ['CountThenMethodRule', ['probe.fn', 'probe.method']];
        yield 'the method test first' => ['MethodThenCountRule', ['probe.method', 'probe.fn']];
    }

    /** @param list<string> $identifiers the rule's own reports, in the order it writes them */
    #[DataProvider('orderings')]
    public function test_a_second_conditional_report_reads_its_own_kind(string $rule, array $identifiers): void
    {
        // The census's own target, so a verdict does not depend on which test ran before this one --
        // `Transpiler::$target` and `$survey` are static.
        Transpiler::$target = 'php';
        Transpiler::$survey = false;

        $file = dirname(__DIR__) . '/Fixtures/narrowing/' . $rule . '.php';

        try {
            $plugin = (new Transpiler($file))->transpile();
        } catch (Refusal $refusal) {
            self::fail(
                "{$rule} refused with \"{$refusal->getMessage()}\". A narrowing from the first conditional "
                . 'report is being applied to the second one`s read of the same local.',
            );
        }

        // Read off the descriptor's own `identifiers`, not searched for in the rendered text: both reports
        // are reached, which is what says the two reads resolved against different kinds rather than one of
        // them being folded away. A substring search over the emitted file would also match an identifier
        // that survived only inside a comment.
        $this->assertSame($identifiers, $plugin['identifiers']);
    }
}
