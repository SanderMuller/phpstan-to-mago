<?php

declare(strict_types=1);

namespace Examples\Dynamic;

/**
 * A dynamic name inside `__get` and `__set`, which the rule exempts: dynamic names are the point of a magic
 * accessor. The exemption reads the *enclosing function's* name, so it applies here and nowhere else.
 */
final class GoodMagicAccessorIsExempt
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __get(string $name): mixed
    {
        $read = function () use ($name): mixed {
            return $this->$name;
        };

        return $read();
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }
}
