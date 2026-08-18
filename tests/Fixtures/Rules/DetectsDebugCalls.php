<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The helper of {@see TraitErrorHelperRule}: it decides *and* builds the finding.
 *
 * The dominant shape in a real rule package, and the one the transpiler refused for the longest. The
 * rule is a shim; everything worth translating is here.
 */
trait DetectsDebugCalls
{
    private function debugCallError(string $funcName): ?IdentifierRuleError
    {
        if ($funcName === 'dd') {
            return RuleErrorBuilder::message('Do not use a debug function')
                ->identifier('fixture.debugCall')
                ->build();
        }

        if ($funcName === 'dump') {
            return RuleErrorBuilder::message('Do not use a debug function')
                ->identifier('fixture.debugCall')
                ->build();
        }

        return null;
    }
}
