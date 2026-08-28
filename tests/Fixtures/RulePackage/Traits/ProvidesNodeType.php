<?php

declare(strict_types=1);

namespace Fixtures\RulePackage\Traits;

use PhpParser\Node\Expr\MethodCall;

/**
 * The half of a rule that says which node it wants, kept where `phpat` keeps it.
 */
trait ProvidesNodeType
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }
}
