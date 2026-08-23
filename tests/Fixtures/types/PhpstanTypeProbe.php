<?php

declare(strict_types=1);

namespace TypeShapes;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\VerbosityLevel;

/**
 * Writes `<callee>\t<describe(typeOnly()) of its one argument>` per probed call.
 *
 * A file rather than a finding, because the string is the whole point and a finding would arrive through
 * whichever formatter is in the way.
 *
 * @implements Rule<FuncCall>
 */
final class PhpstanTypeProbe implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $callee = $node->name->toString();
        if (! str_starts_with($callee, 'probe_') || count($node->getArgs()) !== 1) {
            return [];
        }

        file_put_contents(
            (string) getenv('PROBE_OUT'),
            $callee . "\t" . $scope->getType($node->getArgs()[0]->value)->describe(VerbosityLevel::typeOnly()) . "\n",
            FILE_APPEND,
        );

        return [];
    }
}
