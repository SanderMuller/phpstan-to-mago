<?php declare(strict_types=1);

namespace Examples\Callables;

/**
 * The name written out, which is what the rule asks for.
 *
 * No ordinary `$holder->$name()` here, though it would be silent under this rule too: `VariableMethodCallRule`
 * reports that one under the *same* identifier, `method.dynamicName`, and the gate compares by identifier — so
 * a near miss that another rule of the package catches makes the pair say nothing about this one.
 */
final class GoodWrittenMethodCallable
{
    public function take(Holder $holder): callable
    {
        return $holder->run(...);
    }
}
