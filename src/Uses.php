<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;

/**
 * A file's import map: alias to fully qualified name.
 *
 * Kept separate because the map belongs to the *file a declaration lives in*, not to whoever is reading it.
 * A helper resolves the names it mentions through its own `use` statements; sharing the caller's map
 * silently resolved names to the wrong class, or failed to resolve them at all.
 */
final class Uses
{
    /**
     * @param Stmt[] $ast
     *
     * @return array<string, string>
     */
    public static function collect(array $ast): array
    {
        $map = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $map = [...$map, ...self::collect($stmt->stmts)];

                continue;
            }

            if ($stmt instanceof Use_) {
                foreach ($stmt->uses as $use) {
                    $map[$use->alias !== null ? (string) $use->alias : $use->name->getLast()] = $use->name->toString();
                }
            }
        }

        return $map;
    }
}
