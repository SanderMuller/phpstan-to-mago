<?php

declare(strict_types=1);

namespace Divergence\IsCallableKeepsObjectArm;

/** `final` with no `__invoke`, so it provably cannot satisfy `is_callable()`. */
final class NotInvokable {}

final class Subject
{
    /**
     * `is_callable()` over a union of a callable and a provably non-callable object.
     *
     * PHPStan removes the object arm — it is `final`, declares no `__invoke`, and is in the analysed file,
     * so callability is decidable and impossible. Mago leaves the atomic untouched, so the port sees
     * `callable|NotInvokable`, cannot answer "every atomic is callable", and reports.
     *
     * The shape at `symfony/console` `Helper/TreeNode.php:76`, whose `TreeNode` is likewise `final` with no
     * `__invoke`.
     *
     * @var array<(callable(): \Generator)|NotInvokable>
     */
    private array $items = [];

    public function walk(): void
    {
        foreach ($this->items as $item) {
            if (\is_callable($item)) {
                $item();
            }
        }
    }

    /** The control: a dynamic call on a plain string, which both engines report. */
    public function plain(string $other): void
    {
        $other();
    }
}
