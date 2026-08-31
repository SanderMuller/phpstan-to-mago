<?php declare(strict_types=1);

namespace Control;

/**
 * One class reaching one trait through two, which PHPStan analyses twice.
 *
 * The walk that finds a class's traits used to carry a visited set, so a trait reached by two paths was
 * counted once. That was the last divergence in the return metric on a real consumer: one class using two
 * validation traits that both use a URL-prefixing trait, and a -1 in 18307.
 */
final class User
{
    use LeftOuter;
    use RightOuter;
}
