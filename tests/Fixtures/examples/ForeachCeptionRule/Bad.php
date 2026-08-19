<?php

declare(strict_types=1);

namespace Examples\Loops;

final class Deep
{
    public function go(array $a): void
    {
        foreach ($a as $b) {
            foreach ($b as $c) {
                foreach ($c as $d) {
                    foreach ($d as $e) {
                        foreach ($e as $f) {
                            echo $f;
                        }
                    }
                }
            }
        }
    }
}
