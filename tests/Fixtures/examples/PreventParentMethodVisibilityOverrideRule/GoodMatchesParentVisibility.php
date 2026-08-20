<?php

declare(strict_types=1);

namespace Examples\Visibility;

final class GoodMatchesParentVisibility extends ParentVisibilities
{
    public function stayPublic(): void {}

    protected function stayProtected(): void {}

    private function stayPrivate(): void {}

    /** No parent declares this, so there is no visibility to respect. */
    public function ownMethod(): void {}
}
