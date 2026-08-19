<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Syntax\NodeKind;

/**
 * Node kinds whose case name PHP will not let us write.
 *
 * `NodeKind::Declare` cannot be referenced: `declare` is a reserved word. The backed value is stable and is
 * what the SDK compares on anyway — the same reason `enclosingClassName()` compares `$kind->value` rather
 * than the case for `Class`.
 */
final readonly class NodeKinds
{
    public static function declare(): NodeKind
    {
        return NodeKind::from('Declare');
    }
}
