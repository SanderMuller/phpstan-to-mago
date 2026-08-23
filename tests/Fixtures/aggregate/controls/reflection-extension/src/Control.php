<?php

declare(strict_types=1);

namespace Control;

/** Declares nothing. What the extension claims is what makes it look like it declares `invented()`. */
interface Contract {}

final class Subject implements Contract
{
    /** Skipped by the real rule, because the extension answers hasMethod() for this name on `Contract`. */
    public function invented(string $one, int $two): void {}

    /** Counted by both, so the control cannot pass by measuring nothing. */
    public function plain(string $only): void {}
}
