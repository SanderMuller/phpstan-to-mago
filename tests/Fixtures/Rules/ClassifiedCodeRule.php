<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Classifies what it found, then reports under a code carrying the classification.
 *
 * The shape `hihaho/phpstan-rules` uses for its debug rules: a helper answers *which* namespace matched, and
 * that answer lands in the message and in the report identifier. One report site, two possible codes —
 * `report()` takes its code per call, so a computed one is as valid as a literal.
 *
 * @implements Rule<FuncCall>
 */
final class ClassifiedCodeRule implements Rule
{
    public const string ERROR_MESSAGE = 'No debug statements in the %s namespace';

    /** @var array<string, true> */
    private const array DEBUG_FUNCTIONS = [
        'dump' => true,
        'dd' => true,
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        if (! isset(self::DEBUG_FUNCTIONS[$node->name->name])) {
            return [];
        }

        $area = $this->matchArea($scope);
        if ($area === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(self::ERROR_MESSAGE, $area))
                ->identifier("fixture.noDebugIn{$area}")
                ->build(),
        ];
    }

    private function matchArea(Scope $scope): ?string
    {
        if ($this->namespaceStartsWith($scope, 'App')) {
            return 'App';
        }

        if ($this->namespaceStartsWith($scope, 'Tests')) {
            return 'Tests';
        }

        return null;
    }

    private function namespaceStartsWith(Scope $scope, string $prefix): bool
    {
        $namespace = $scope->getNamespace();
        if ($namespace === null) {
            return false;
        }

        if ($prefix === $namespace) {
            return true;
        }

        return str_starts_with($namespace, rtrim($prefix, '\\') . '\\');
    }
}
