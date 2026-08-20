<?php

declare(strict_types=1);

namespace Examples\OneFile;

// One named class-like, plus an anonymous class that must not be counted. php-parser models an anonymous
// class as a `Class_` with no name and the rule filters those out; Mago gives it its own node kind, so the
// search never returns one and the filter has nothing to do. This file is what makes that equivalence
// testable: a port that counted anonymous classes would report here, and PHPStan does not.
final class GoodOneClassLikeAndAnAnonymousOne
{
    public function handler(): object
    {
        return new class {
            public function handle(): void {}
        };
    }
}
