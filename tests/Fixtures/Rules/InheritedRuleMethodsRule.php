<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Rules\Rule;
use Sandermuller\PhpstanToMago\Tests\Fixtures\Rules\Inherited\BaseForbiddenCallRule;

/**
 * A rule that declares neither of its required methods, in the shape `phpat` writes all of its rules.
 *
 * Two lines and an `implements`, with `getNodeType()` and `processNode()` on the base. The walk has to find
 * it — it declares no `getNodeType()` for `RulePaths` to match on — and the transpiler has to resolve both
 * methods through the hierarchy it already resolves a static helper through.
 *
 * @implements Rule<MethodCall>
 */
final class InheritedRuleMethodsRule extends BaseForbiddenCallRule implements Rule {}
