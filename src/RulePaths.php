<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Turns the paths given on the command line into a list of rule files.
 *
 * A directory is walked for rules; a file is taken as given. The distinction is deliberate: naming a
 * file is a claim that it is a rule, so a file that turns out not to be one is refused by name rather
 * than skipped. A directory carries no such claim, and a rule package holds plenty of files that are
 * not rules at all (traits, abstract bases, collectors' value objects), so those are filtered out
 * instead of reported as eighteen refusals nobody asked about.
 */
final class RulePaths
{
    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public static function expand(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (! is_dir($path)) {
                $files[] = $path;

                continue;
            }

            foreach (self::phpFilesIn($path) as $candidate) {
                if (self::isRule($candidate)) {
                    $files[] = $candidate;
                }
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A concrete class declaring `getNodeType()`, which is what makes a PHPStan rule addressable.
     */
    private static function isRule(string $file): bool
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($file));
        if ($ast === null) {
            return false;
        }

        foreach ((new NodeFinder())->findInstanceOf($ast, Class_::class) as $class) {
            if ($class->isAbstract()) {
                continue;
            }

            if (self::declaresNodeType($class)) {
                return true;
            }
        }

        return false;
    }

    private static function declaresNodeType(Class_ $class): bool
    {
        foreach ($class->stmts as $statement) {
            if ($statement instanceof ClassMethod && $statement->name->toString() === 'getNodeType') {
                return true;
            }
        }

        return false;
    }
}
