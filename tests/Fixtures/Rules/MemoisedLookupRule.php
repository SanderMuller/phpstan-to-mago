<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A value producer behind a per-process cache, in the read-first spelling.
 *
 * `hihaho/phpstan-rules` caches this way in two places. The cache is invisible to the answer, so the
 * generated plugin computes the question and drops the cache — this fixture is what proves the recogniser
 * covers the spelling that returns the assignment rather than filling and re-reading.
 *
 * @implements Rule<FuncCall>
 */
final class MemoisedLookupRule implements Rule
{
    public const string ERROR_MESSAGE = 'No dump() inside a namespace';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $error = $this->debugCallError($node, $scope);

        return $error instanceof IdentifierRuleError ? [$error] : [];
    }

    private function debugCallError(FuncCall $node, Scope $scope): ?IdentifierRuleError
    {
        if (! $node->name instanceof Name) {
            return null;
        }

        if ($node->name->toString() !== 'dump') {
            return null;
        }

        $namespace = $this->cachedNamespace($scope);

        if ($namespace === null) {
            return null;
        }

        return RuleErrorBuilder::message(self::ERROR_MESSAGE)
            ->identifier('fixture.memoisedLookup')
            ->build();
    }

    /**
     * The namespace, resolved once per file.
     *
     * The key binding serves the cache and nothing else, which is what makes dropping both sound.
     */
    private function cachedNamespace(Scope $scope): ?string
    {
        /** @var array<string, string|null> $cache */
        static $cache = [];

        $file = $scope->getFile();

        if (array_key_exists($file, $cache)) {
            return $cache[$file];
        }

        return $cache[$file] = $scope->getNamespace();
    }
}
