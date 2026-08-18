<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use FilesystemIterator;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds a class, interface or trait by short name in the source around a rule.
 *
 * The transpiler reads one rule file, but a rule reaches beyond it constantly: a constant on another class,
 * a static helper, and above all the trait or base class its own logic lives in. This is the only place
 * that touches the filesystem to answer those.
 *
 * Results are cached per process because a rule package resolves the same handful of traits over and over.
 */
final class SourceIndex
{
    /** @var array<string, list<string>> short name -> paths */
    private static array $files = [];

    /** @var array<string, true> roots already walked */
    private static array $indexed = [];

    /** @var array<string, array{class: ClassLike, uses: array<string, string>}> */
    private static array $parsed = [];

    /**
     * @return array{class: ClassLike, uses: array<string, string>}|null
     */
    public function find(string $shortName, string $ruleFile): ?array
    {
        if (isset(self::$parsed[$shortName])) {
            return self::$parsed[$shortName];
        }

        foreach ($this->paths($shortName, $ruleFile) as $path) {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($path));
            if ($ast === null) {
                continue;
            }

            $class = self::declaredAs($ast, $shortName);
            if (! $class instanceof ClassLike) {
                continue;
            }

            self::$parsed[$shortName] = ['class' => $class, 'uses' => Uses::collect($ast)];

            return self::$parsed[$shortName];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function paths(string $shortName, string $ruleFile): array
    {
        foreach ($this->roots($ruleFile) as $root) {
            if (isset(self::$indexed[$root])) {
                continue;
            }

            self::$indexed[$root] = true;
            /** @var SplFileInfo $entry */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    self::$files[$entry->getBasename('.php')][] = $entry->getPathname();
                }
            }
        }

        return self::$files[$shortName] ?? [];
    }

    /**
     * The class, interface or trait declared under `$shortName`.
     *
     * Distinct from asking "what rule does this file define": that must not match a trait, and this must,
     * because a trait is where a rule package usually keeps the helper.
     *
     * @param Stmt[] $ast
     */
    public static function declaredAs(array $ast, string $shortName): ?ClassLike
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $found = self::declaredAs($stmt->stmts, $shortName);
                if ($found instanceof ClassLike) {
                    return $found;
                }

                continue;
            }

            if ($stmt instanceof ClassLike && $stmt->name?->toString() === $shortName) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * Where to look. The rule's own package comes first and is the one that matters for traits and base
     * classes; a `vendor` ancestor is added when there is one, because a helper may reference a third-party
     * class.
     *
     * @return list<string>
     */
    private function roots(string $ruleFile): array
    {
        // Absolute first: walking up from a relative path stops at "." and finds neither root, so a rule
        // given as `tests/Fixtures/Rules/X.php` resolved no cross-file name at all.
        $absolute = realpath($ruleFile);
        if ($absolute === false) {
            return [];
        }

        $roots = [];

        $package = dirname($absolute);
        while ($package !== '/' && ! is_file($package . '/composer.json')) {
            $package = dirname($package);
        }

        if ($package !== '/') {
            $roots[] = $package;
        }

        $vendor = $absolute;
        while ($vendor !== '/' && basename($vendor) !== 'vendor') {
            $vendor = dirname($vendor);
        }

        if ($vendor !== '/') {
            $roots[] = $vendor;
        }

        return $roots;
    }
}
