<?php

declare(strict_types=1);

// No namespace, so the prologue's classification answers null and neither check is reached.

function goodOutsideTheNamespace(array $rows, object $subject): mixed
{
    dump($rows);

    return invade($subject)->hidden;
}
