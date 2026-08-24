<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Rules\Enum;

/**
 * A class of constants naming interfaces, which is how the rule packages spell the same thing.
 *
 * Reproducing that shape is the fixture: the transpiler has to read the value out of *another* class's
 * source, not out of the rule it is translating. The interface it names is declared under
 * `examples/stubs`, where both tools can resolve it.
 */
final class FixtureInterfaceName
{
    public const string NAMED = 'Examples\\Stubs\\NamedByConstant';
}
