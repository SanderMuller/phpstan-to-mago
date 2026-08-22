<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\RegisteredRulePackage;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * A rule a project registers, living outside that project's own directory.
 *
 * Both halves of that matter. It arrives through an `includes:` one file deep, so the project's own config
 * never names it, and it sits in a sibling directory, so walking the project finds nothing. Between them
 * they are the case the container answers and a scan cannot.
 *
 * It reports nothing. Discovery asks which rules a project registered, not what they find, and a rule that
 * fires would make the fixture depend on analysed code as well as on configuration.
 *
 * @implements Rule<FuncCall>
 */
final class DiscoveredRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<never>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return [];
    }
}
