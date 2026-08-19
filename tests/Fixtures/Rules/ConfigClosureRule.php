<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Sandermuller\PhpstanToMago\Runtime\Support;

/**
 * A closure whose single parameter is typed as a specific class.
 *
 * The shape every `symplify/phpstan-rules` Symfony config-closure rule gates on, via the shared
 * `SymfonyClosureDetector`: a config file *is* a closure taking a `ContainerConfigurator`, so ten rules ask
 * this same question before doing anything of their own.
 *
 * `$onlyParam->type instanceof Name` is the interesting half. php-parser gives an `Identifier` for a builtin and
 * a `Name` for a class-like, so the test separates `Widget $x` from `int $x`; Mago separates them by the `Hint`'s
 * child kind, `Identifier` against `LocalIdentifier` — probed across ten written forms, see
 * {@see Support::hintIsName()}.
 *
 * **This pair does not prove that half, and saying so is the point.** `hintIsName()` used to mean "present and
 * not a union or intersection", which answers yes for `int` and for `?Configurator`. Mutation-checked: both are
 * still rejected either way, because the FQN comparison right after it saves them — an `int` hint resolves to
 * nothing and a nullable hint resolves to nothing, so neither equals the configurator's name. What the pair *does*
 * discriminate is any weakening to mere hint-presence, which reports the union closure, because a union hint
 * resolves to its first name. The narrower reading is justified by php-parser's own semantics and the probe
 * table, not by this gate.
 *
 * Deliberately *shorter* than the real rules: each of those follows the detector with a guard of its own, so this
 * one isolates the detector. `NoBundleResourceConfigRule` is gated separately and in full — its guard is
 * `str_contains($scope->getFile(), 'Resources/config')`, which is why the gate copies examples keeping their
 * directories instead of flattening them.
 *
 * @implements Rule<Closure>
 */
final class ConfigClosureRule implements Rule
{
    private const string CONFIGURATOR = 'Examples\Config\Configurator';

    private const string ERROR_MESSAGE = 'This config closure is registered twice';

    public function getNodeType(): string
    {
        return Closure::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->detect($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.configClosure')
                ->build(),
        ];
    }

    private function detect(Closure $closure): bool
    {
        if (count($closure->getParams()) !== 1) {
            return false;
        }

        $onlyParam = $closure->getParams()[0];
        if (! $onlyParam->type instanceof Name) {
            return false;
        }

        $parameterName = $onlyParam->type->toString();

        return $parameterName === self::CONFIGURATOR;
    }
}
