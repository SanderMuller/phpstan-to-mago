<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A rule constant whose value is `Foo::class` rather than a quoted string.
 *
 * PHP resolves it to the fully-qualified name at compile time, so it is a string constant and the transpiler
 * carries it as one. It refused before, saying the constant was not a string — which was false about the
 * language rather than about the rule, and the kind of refusal that sizes work wrongly.
 *
 * The constant names an *imported* short name, so resolution goes through the file's own `use` map. A fixture
 * writing the fully-qualified name out in full would pass without exercising that, and the map is where the
 * resolver's subtlety lives.
 *
 * @implements Rule<Class_>
 */
final class ClassConstantIsAStringRule implements Rule
{
    public const string ERROR_MESSAGE = 'Do not implement the rule interface directly';

    private const string BASE_CLASS = Rule::class;

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $scope->getClassReflection();
        if (! $classReflection instanceof ClassReflection) {
            return [];
        }

        if (! in_array(self::BASE_CLASS, $classReflection->getParentClassesNames(), true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.classConstantIsAString')
                ->build(),
        ];
    }
}
