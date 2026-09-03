<?php

declare(strict_types=1);

namespace Examples\Protectedness;

/**
 * The same protected members, one level down inside an anonymous class.
 *
 * php-parser has no separate class for an anonymous one — it is a `Stmt\Class_` with a null name — so
 * PHPStan's `InClassNode` fires here and `getOriginalNode() instanceof Class_` passes. Mago gives it
 * `NodeKind::AnonymousClass`, and while the emitted plugin registered only `Class`, `Enum` and `Interface`
 * the hook never fired and every member below went unreported.
 *
 * Found by the corpus differential on `Illuminate\Database`: 5 only-original against 966 agreements, and
 * all five were protected members of the anonymous classes Laravel's casts return.
 */
final class Factory
{
    public function make(): object
    {
        return new class {
            protected const string LABEL = 'anonymous';

            protected int $uses = 0;

            protected function describe(): string
            {
                return self::LABEL;
            }
        };
    }
}
