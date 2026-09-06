<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A rule that reports early for one case and falls through to a trailing report for another.
 *
 * The emitter reads one flag for "a report was written where it was found", and read it as "there is
 * nothing left to say at the end" — true for a rule with one report, false for this shape. No rule in the
 * corpus wrote it, so the emitted plugin was silent on the trailing case and nothing noticed. Snapshotted
 * for the second report: a plugin with one is the defect, and it parses and runs either way.
 *
 * Both branches have to be reachable for that to mean anything. A guard the hook makes constant folds to
 * `if (false)` and takes its report with it, which is a different thing to look at.
 *
 * @implements Rule<Class_>
 */
final class EarlyThenTailReportRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @param Class_ $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        if (str_starts_with($node->name->toString(), 'Abstract')) {
            return [RuleErrorBuilder::message('Named for an abstraction.')
                ->identifier('fixture.earlyThenTail.abstract')
                ->build()];
        }

        if (str_ends_with($node->name->toString(), 'Repository')) {
            return [];
        }

        return [RuleErrorBuilder::message('Named for nothing in particular.')
            ->identifier('fixture.earlyThenTail.other')
            ->build()];
    }
}
