<?php

declare(strict_types=1);

/**
 * No namespace at all, so `enclosingNamespace()` answers null and the guard bails. Without the null
 * comparison this file would reach a string function with null.
 */
final class GlobalReporter
{
    public function go(): void
    {
        dump('x');
    }
}
