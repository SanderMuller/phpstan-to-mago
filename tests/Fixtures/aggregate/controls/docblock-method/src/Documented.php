<?php declare(strict_types=1);

namespace Control;

/**
 * A class whose docblock declares methods it does not write.
 *
 * `@method` is how a package documents what `__call()` will answer, and Laravel's factories carry two of
 * them. The collectors here visit `ClassMethod` *nodes*, so the original never sees one; a codebase that
 * lists them beside the real methods counts them.
 *
 * @method promised($a)
 * @method static promisedStatic($a)
 */
final class Documented
{
    public function real($a) {}
}
