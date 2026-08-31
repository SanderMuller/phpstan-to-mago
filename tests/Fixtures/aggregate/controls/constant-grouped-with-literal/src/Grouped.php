<?php declare(strict_types=1);

namespace Control;

/**
 * A grouped declaration whose first default holds a brace, copied in shape from the one real consumer that
 * has one. Read as text, the `}` looks like the end of a statement and the pair counts twice; the tree says
 * it is one `ClassLikeConstant` and it counts once.
 */
final class Grouped
{
    private const string
        DYNAMIC = 'Welcome {name}',
        STATIC_TEXT = 'Welcome';

    public function texts(): string
    {
        return self::DYNAMIC . self::STATIC_TEXT;
    }
}
