<?php

declare(strict_types=1);

namespace Examples\Visibility;

final class BadWidensPrivateParent extends ParentVisibilities
{
    public function goesPublic(): void {}
}
