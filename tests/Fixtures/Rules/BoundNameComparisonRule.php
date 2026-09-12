<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A name compared against a literal through a local, rather than inline.
 *
 * `$node->name === 'x'` translated and `$name = (string) $node->name; $name === 'x'` did not, because a
 * declaration's name resolves to `local-name` and the name-comparison branch listed every other name kind.
 * The two spellings ask the same question, and `NoParamTypeRemovalRule` and `NoReturnSetterMethodRule` both
 * write the second one.
 *
 * The cast is deliberate. `(string) $node->name` is how the corpus spells it, and the emitted comparison is
 * the same `Support::declarationName()` the inline form produces — the cast has nothing to do at runtime
 * because the name is already a string by the time the plugin has it.
 *
 * @implements Rule<ClassMethod>
 */
final class BoundNameComparisonRule implements Rule
{
    public const string ERROR_MESSAGE = 'Name the constructor __construct';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classMethodName = (string) $node->name;

        if ($classMethodName === '__construct') {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.boundNameComparison')
                ->build(),
        ];
    }
}
