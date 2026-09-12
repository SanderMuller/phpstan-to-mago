<?php

declare(strict_types=1);

namespace Examples\ForeachValue;

final class PlainValueLoops
{
    /**
     * Both loops bind their value to a plain variable, keyed and unkeyed.
     *
     * The keyed one is the control: its key is a variable too, so a port that read the wrong child would
     * still see a variable and stay silent here — which is why the disagreement has to be measured on the
     * bad file, where the two children differ.
     *
     * @param array<int, int>    $counts
     * @param array<string, int> $labelled
     */
    public function run(array $counts, array $labelled): int
    {
        $total = 0;
        foreach ($counts as $count) {
            $total += $count;
        }

        foreach ($labelled as $label => $count) {
            $total += strlen($label) + $count;
        }

        return $total;
    }
}
