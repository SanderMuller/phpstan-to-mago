<?php

declare(strict_types=1);

namespace Examples\Expressions;

/**
 * The same accesses written out. `Target::$prop` matters most: a static property's written name *is* `$prop`,
 * so a test that read the leading `$` would report here.
 */
final class GoodWrittenOut
{
    public function run(Target $subject): mixed
    {
        $staticProperty = Target::$prop;
        $method = $subject->run();

        return [$staticProperty, $method];
    }
}
