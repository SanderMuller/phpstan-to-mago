<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use PHPUnit\Framework\TestCase;

/**
 * An alarm for the one closure in this project that expires on its own.
 *
 * Three rules refuse on a definedness test — `OverwriteVariablesWithForeachRule`,
 * `DisallowedImplicitArrayCreationRule` and `OverwriteVariablesWithForLoopInitRule`, the last behind an
 * `->init` iteration the pass stops at first. The PHP target has no way to answer it: a plugin receives
 * span-keyed types and nothing separating a definitely-defined variable from an undefined one, which is what
 * `carthage-software/mago#2334` asked for.
 *
 * **That issue closed as completed on 2026-09-07, after 1.47.6 shipped on 2026-09-04**, and the commit that
 * closed it — `90d64baf9` — is an ancestor of `main` rather than a branch, eight commits past the 1.47.6
 * tag, so the next release cut from main carries it. That last part is the premise this alarm rests on and
 * the one an installed release cannot tell you; `gh api repos/carthage-software/mago/compare/90d64baf9...main`
 * answers `behind_by=0`.
 *
 * So the refusal is a closure with an expiry date rather than a ceiling, and it is the only one of the four
 * kinds this project records — construct, configuration, cross-file, version — that changes without anyone
 * touching it.
 *
 * Which is why this is a test and not a sentence. Nothing prompts a re-read of a row already marked closed,
 * so a version boundary held only in prose keeps three rules out of the candidate pool for as long as nobody
 * happens to look. This repository's own finding is that the enforced rules hold without you and the
 * remembered ones are the ones you have to run yourself; a closure that expires is exactly the kind that
 * cannot be left to remembering.
 *
 * It fires once, when the capability ships, and says what to do about it.
 */
final class WatchesForDefinednessTest extends TestCase
{
    /**
     * What to do when this fires, kept beside the assertion so the next reader gets it at the moment it
     * matters rather than having to find the log entry behind it.
     *
     * Every claim here cites the ref it was read at rather than the conversation it came from. A message
     * that says "a peer reported" ages badly and cannot be re-checked; three `gh api` calls can.
     */
    private const string WHAT_TO_DO = <<<'TEXT'
        The installed mago SDK now exposes definedness to node hooks, so the version boundary behind three
        refusals has moved. carthage-software/mago#2334 closed 2026-09-07, after 1.47.6 shipped.

        Port these three, whose refusal was never a ceiling:
          - OverwriteVariablesWithForeachRule
          - DisallowedImplicitArrayCreationRule
          - OverwriteVariablesWithForLoopInitRule   (behind an ->init iteration the pass stops at first)

        The mapping, read at mago commit 90d64baf9 rather than taken on report. Reproduce with:

          gh api "repos/carthage-software/mago/contents/composer/src/Sdk/Analyzer/<file>.php?ref=90d64baf9" \
            -q .content | base64 -d

        for VariableDefinedness, NodeAnalysisContext and FileAnalysisRequirement. Then:

          $scope->hasVariableType($n)->yes()
            becomes $context->getVariableDefinedness($n) === VariableDefinedness::Defined

        One to one, so this needs no trinary composition and no ->negate(). The enum has three cases —
        Undefined, PossiblyDefined, Defined — and the accessor is nullable.

        Mind that `null` and `Undefined` are different answers, which the source says outright at
        NodeAnalysisContext.php:75 — `return $definedness[$variable] ?? VariableDefinedness::Undefined`. An
        absent name in a *present* map is Undefined; null is the whole map being absent, meaning the
        requirement was not requested or the target was skipped or unanalysed. Treating null as Undefined
        would report on targets mago never looked at.

        Both Overwrite* rules guard on ->yes() and so suppress unless Defined, which makes null-as-not-Defined
        safe for them by their polarity rather than by design — a rule guarding on ->no() would invert. State
        that bound rather than inherit it.

        Requesting the requirement has a stated cost: the upstream change touched performance.md, and nothing
        here has measured it.

        Then delete this test. An alarm for a boundary that has moved is noise.
        TEXT;

    public function test_the_installed_sdk_still_cannot_answer_definedness(): void
    {
        $cases = array_map(
            static fn (FileAnalysisRequirement $case): string => $case->name,
            FileAnalysisRequirement::cases(),
        );

        $this->assertNotContains('VariableDefinedness', $cases, self::WHAT_TO_DO);
    }
}
