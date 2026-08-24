<?php

declare(strict_types=1);

namespace Examples\ArgumentKinds;

final class Allowed
{
    public const string NAME = 'allowed';

    public function configure(mixed $one, mixed $two): void {}

    public function helper(): string
    {
        return 'helper';
    }

    /**
     * Three near misses. A *method* call in the first position is a call and is not a function call, which is
     * what the wrapper unwrapping has to get right; a literal in the first position is neither; and a literal
     * in the second is not a class-constant access.
     */
    public function set(): void
    {
        $this->configure($this->helper(), self::NAME);
        $this->configure('plain', self::NAME);
        $this->configure(ref(), 'plain');
    }
}
