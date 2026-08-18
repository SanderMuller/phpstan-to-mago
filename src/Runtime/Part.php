<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A navigated-to piece of the tree, with what a predicate needs already resolved.
 *
 * Mago's PHP `Node` carries only id, kind, span and parent, so a Rust field access like `node.object`
 * has to walk children, and the text a comparison wants needs a separate lookup. Carrying kind and
 * text on the way back means the generated call stays
 * `Support::selectorIs(Support::selector($context, $node), 'atLeast')` instead of threading the source
 * through every step.
 */
final readonly class Part
{
    public function __construct(
        public NodeKind $kind,
        public string $text,
        public Node $node,
        public SourceFile $source,
    ) {}

    /** @return list<Part> */
    public function children(): array
    {
        $out = [];
        foreach ($this->source->getChildren($this->node) as $child) {
            $out[] = new self($child->kind, trim($this->source->getText($child)), $child, $this->source);
        }

        return $out;
    }

    public function firstChild(): ?self
    {
        return $this->children()[0] ?? null;
    }
}
