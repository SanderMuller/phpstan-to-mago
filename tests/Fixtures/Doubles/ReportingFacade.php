<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Doubles;

use Illuminate\Support\Facades\Facade;

/**
 * A facade a consumer's own config aliases, for the `OnlyAllowFacadeAliasInBlade` example pair.
 *
 * Real and autoloaded rather than a stub: the original rule resolves an alias with `new ReflectionClass()`,
 * so the class behind it has to exist at runtime in PHPStan's process. Requiring the stub file instead would
 * redeclare `Illuminate\Foundation\Http\FormRequest`, which the same stub file also declares, and take every
 * other rule's gate run down with it.
 */
final class ReportingFacade extends Facade
{
    public static function summarise(): void {}

    protected static function getFacadeAccessor(): string
    {
        return 'reporting';
    }
}
