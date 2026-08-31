<?php declare(strict_types=1);

namespace Control;

/** A second user of the trait, so the trait's constant is counted twice and not once. */
final class Two
{
    use Shared;
}
