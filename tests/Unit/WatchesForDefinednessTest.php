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
 * **That issue closed as completed on 2026-09-07, after 1.47.6 shipped on 2026-09-04.** So the refusal is a
 * closure with an expiry date rather than a ceiling, and it is the only one of the four kinds this project
 * records — construct, configuration, cross-file, version — that changes without anyone touching it.
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
    public function test_the_installed_sdk_still_cannot_answer_definedness(): void
    {
        $cases = array_map(
            static fn (FileAnalysisRequirement $case): string => $case->name,
            FileAnalysisRequirement::cases(),
        );

        $this->assertNotContains(
            'VariableDefinedness',
            $cases,
            "The installed mago SDK now exposes definedness to node hooks, so the version boundary behind\n"
            . "three refusals has moved. carthage-software/mago#2334 closed 2026-09-07, after 1.47.6.\n\n"
            . "Port these three, whose refusal was never a ceiling:\n"
            . "  - OverwriteVariablesWithForeachRule\n"
            . "  - DisallowedImplicitArrayCreationRule\n"
            . "  - OverwriteVariablesWithForLoopInitRule   (behind an ->init iteration the pass stops at)\n\n"
            . "The mapping a peer read off the merged commit, and which nothing here has verified against a\n"
            . "release: `\$scope->hasVariableType(\$n)->yes()` becomes\n"
            . "`\$context->getVariableDefinedness(\$n) === VariableDefinedness::Defined`.\n\n"
            . "Read the shipped API before building on that sentence, and mind one thing it carries: `null`\n"
            . "and `Undefined` are different answers. `null` means the requirement was not requested, or the\n"
            . "target was skipped or unanalysed — treating it as `Undefined` would report on targets mago\n"
            . "never looked at. Both Overwrite* rules guard on `->yes()` and so suppress unless `Defined`,\n"
            . "which makes null-as-not-Defined safe for them by their polarity rather than by design; a rule\n"
            . "guarding on `->no()` would invert. State that bound rather than inherit it.\n\n"
            . 'Then delete this test: an alarm for a boundary that has moved is noise.',
        );
    }
}
