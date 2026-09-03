<?php declare(strict_types=1);

namespace Control;

/**
 * A method the parent's `@mixin` puts on the parent, which is what makes the collector skip it.
 *
 * `ClassReflection::hasMethod()` is answered by PHPStan's own `MixinMethodsClassReflectionExtension` — core,
 * not larastan — so `Base` has every method of `Mixin` and the LSP guard skips `Subject::invented()`
 * entirely. `Mixin`'s own declaration still counts, and so does `plain()`, so this control cannot pass by
 * measuring nothing.
 */
class Mixin
{
    public function invented(string $one, int $two): void {}
}

/** @mixin Mixin */
class Base {}

final class Subject extends Base
{
    public function invented(string $one, int $two): void {}

    public function plain(string $only): void {}
}
