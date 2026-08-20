<?php

declare(strict_types=1);

namespace Examples\Visibility;

/**
 * The parents both examples extend.
 *
 * A private parent method is the case worth testing: PHP allows a child to declare the same name public,
 * because a private method is not inherited, so the rule is what catches it rather than the engine. Narrowing
 * a public method to protected would be a fatal error and could not be a fixture at all.
 */
abstract class ParentVisibilities
{
    public function stayPublic(): void {}

    protected function stayProtected(): void {}

    private function goesPublic(): void {}

    private function stayPrivate(): void {}
}
