<?php

declare(strict_types=1);

namespace Examples\Setters;

/**
 * The two ways the rule fires, which are two different walks rather than one.
 *
 * `setName()` is the scoped return: a `return` with a value, written in the method's own body.
 * `setTags()` returns nothing and yields instead, which the rule reaches through a second search — so a port
 * that ported only the traverser would report the first and go quiet on the second.
 */
final class ReturningSetters
{
    private string $name = '';

    /** @var list<string> */
    private array $tags = [];

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @return iterable<string> */
    public function setTags(string ...$tags): iterable
    {
        foreach ($tags as $tag) {
            yield $tag;
        }
    }
}
