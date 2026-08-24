<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A regex guard, written both ways the corpus writes one.
 *
 * `Strings::match($subject, $pattern) === null` and `preg_match($pattern, $subject)` ask the same question:
 * Nette hands back the capture array or null, and with two arguments and its defaults that is
 * `preg_match()`'s own answer. Read from `Strings::match()` rather than assumed — the `u` modifier it can
 * append sits behind a `$utf8` parameter no caller in the corpus passes.
 *
 * Only the yes-or-no half is translated. Four corpus rules reach a pattern test; three of them then read a
 * capture, which is a second question, and they stay refused on it.
 *
 * @implements Rule<Class_>
 */
final class PatternNamedClassRule implements Rule
{
    public const string ERROR_MESSAGE = 'A versioned class must not be named Rector';

    private const string VERSIONED_REGEX = '#\\\\Php\d+\\\\#';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = (string) $node->namespacedName;

        if (Strings::match($className, self::VERSIONED_REGEX) === null) {
            return [];
        }

        if (preg_match('#Rector$#', $className) !== 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::ERROR_MESSAGE)
                ->identifier('fixture.patternNamedClass')
                ->build(),
        ];
    }
}
