<?php

declare(strict_types=1);

namespace Examples\Loops;

/**
 * Nesting the rule allows, plus the case that pins down what the search covers.
 *
 * `atTheLimit` has exactly as many nested `foreach` statements below its outermost one as the rule permits — one
 * more level and it reports, which is what the bad example is.
 *
 * `siblingsNotDescendants` is the scoping proof: three `foreach` statements in one method, none inside another. The
 * search runs over the *body* of the node the hook fired for, so each of them sees nothing below it. A search over
 * the file, or over the node instead of its body, would report here.
 */
final class Behaved
{
    public function atTheLimit(array $a): void
    {
        foreach ($a as $b) {
            foreach ($b as $c) {
                foreach ($c as $d) {
                    foreach ($d as $e) {
                        echo $e;
                    }
                }
            }
        }
    }

    public function siblingsNotDescendants(array $a): void
    {
        foreach ($a as $one) {
            echo $one;
        }

        foreach ($a as $two) {
            echo $two;
        }

        foreach ($a as $three) {
            echo $three;
        }
    }
}
