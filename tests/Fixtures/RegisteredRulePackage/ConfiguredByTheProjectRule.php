<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\RegisteredRulePackage;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A rule whose values come from the project that registers it, not from a package that ships it.
 *
 * The shape `hihaho/phpstan-rules` leaves to its consumers: no neon in the package names this rule, so
 * there is nothing to read its wiring from, and a consumer that wants it registers *and* configures it.
 * Under `--from-config` the container holds both facts, which is what makes the values readable at all.
 *
 * Two kinds of value on purpose. `$banned` is a promoted parameter, so a property holds it. `$bannedLookup`
 * is derived in the body from a parameter that is *not* promoted, so nothing holds the parameter and only
 * the computed table can be read — and it is keyed, which is the case that renders wrongly if keys are
 * dropped.
 *
 * @implements Rule<FuncCall>
 */
final class ConfiguredByTheProjectRule implements Rule
{
    /** @var array<string, true> */
    private array $bannedLookup;

    /**
     * @param list<string> $banned
     * @param list<string> $alsoBanned
     */
    public function __construct(
        private array $banned,
        array $alsoBanned,
    ) {
        $this->bannedLookup = array_fill_keys(array_map(strtolower(...), $alsoBanned), true);
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $name = $node->name->toString();
        if (! isset($this->bannedLookup[strtolower($name)]) && ! $this->isBannedPrefix($name)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('Do not call %s().', $name))
                ->identifier('fixture.configuredByTheProject')
                ->build(),
        ];
    }

    /**
     * Reads the promoted list, so both configured values are load-bearing rather than one being carried.
     */
    private function isBannedPrefix(string $name): bool
    {
        foreach ($this->banned as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
