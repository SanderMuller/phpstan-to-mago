<?php

declare(strict_types=1);

namespace Examples\RequireParentConstructCall;

final class Good extends Middle
{
    public function __construct()
    {
        parent::__construct();
    }
}

/** No ancestor at all, so there is no constructor to have called. */
final class GoodWithoutParent
{
    public function __construct() {}
}

/** The call sits inside a condition, which the original finds by recursing to any depth. */
final class GoodNested extends Middle
{
    public function __construct(bool $flag)
    {
        if ($flag) {
            parent::__construct();
        }
    }
}
