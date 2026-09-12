<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Cli;
use Sandermuller\PhpstanToMago\Transpiler;

/**
 * `->maybe()` is answered for `hasVariableType()` and refused for every other query.
 *
 * **The corpus cannot catch this one, which is why it is a test rather than a snapshot.** Exactly one
 * `->maybe()` exists across the seven packages -- `DisallowedImplicitArrayCreationRule`'s, on
 * `hasVariableType()` -- so the emit diff is blind to a `maybe` on anything else. It stayed blind while the
 * bug was live: teaching the dispatcher to pass `maybe` through widened it into arms written for two values,
 * every one of which ends in `negateUnless($tail === 'yes', ..)`. A `->isBoolean()->maybe()` would have
 * rendered as `!type_is_boolean(..)`, which is `no`-or-`maybe` — the silent collapse the `->no()` refusal
 * beside it already exists to stop, arriving through the door that refusal does not cover.
 *
 * The pair is the point. One rule that must refuse and one beside it that must still emit, differing only in
 * the tail: a version of the guard that refuses too much passes the first row and fails the second.
 */
#[CoversNothing]
final class RefusesAMaybeTailItCannotRenderTest extends TestCase
{
    private const string RULE = <<<'PHP'
        <?php

        namespace Probe;

        use PhpParser\Node;
        use PHPStan\Analyser\Scope;
        use PHPStan\Rules\Rule;
        use PHPStan\Rules\RuleErrorBuilder;

        final class TailProbeRule implements Rule
        {
            public function getNodeType(): string
            {
                return Node\Expr\MethodCall::class;
            }

            public function processNode(Node $node, Scope $scope): array
            {
                if (! $scope->getType($node->var)->isBoolean()->TAIL()) {
                    return [];
                }

                return [RuleErrorBuilder::message('x')->identifier('probe.tail')->build()];
            }
        }
        PHP;

    protected function setUp(): void
    {
        Transpiler::$target = 'php';
        Transpiler::$survey = false;
    }

    public function test_a_maybe_on_a_query_with_no_third_state_is_refused(): void
    {
        $output = $this->transpile('maybe');

        $this->assertStringContainsString('REFUSE', $output);
        $this->assertStringContainsString('->isBoolean()->maybe()', $output);
    }

    /**
     * The control: the same rule with the tail the arms were written for still emits.
     *
     * Without this row a guard that refused every tail would pass the assertion above.
     */
    public function test_the_same_query_still_emits_on_yes(): void
    {
        $this->assertStringContainsString('EMIT', $this->transpile('yes'));
    }

    private function transpile(string $tail): string
    {
        $directory = sys_get_temp_dir() . '/ptm-tail-' . $tail . '-' . getmypid();
        @mkdir($directory, 0o777, true);
        $rule = $directory . '/TailProbeRule.php';
        file_put_contents($rule, str_replace('TAIL', $tail, self::RULE));

        ob_start();
        Cli::run([$rule, '--out=' . $directory . '/out'], $directory);
        $output = (string) ob_get_clean();

        @unlink($rule);

        return $output;
    }
}
