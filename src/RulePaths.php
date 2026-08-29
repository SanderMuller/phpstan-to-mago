<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
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
        $ast = SourceIndex::parse((string) file_get_contents($file));
        if ($ast === null) {
            return false;
        }

        $uses = Uses::collect($ast);

        foreach ((new NodeFinder())->findInstanceOf($ast, Class_::class) as $class) {
            if ($class->isAbstract()) {
                continue;
            }

            if (self::declaresNodeType($class) || self::implementsRule($class, $uses) || self::inheritsNodeType($class, $uses, $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a concrete class implements PHPStan's `Rule` without declaring `getNodeType()` itself.
     *
     * A rule may inherit that method from an abstract base and get the rest of its behaviour from a trait.
     * `phpat/phpat` writes all but two of its rules that way — a two-line class, `extends ShouldNotDepend
     * implements Rule`, with a `use` for the extractor — and the walk found none of them. A package that is
     * installed and never read contributes a silent zero, which is the shape this whole tool exists to refuse.
     *
     * The other two carry no `implements` clause at all; {@see inheritsNodeType()} is what reaches those.
     *
     * Resolved through the file's imports rather than matched on the short name: `Rule` is a common enough
     * interface name that a package implementing its own would otherwise be walked as a rule package.
     *
     * @param array<string, string> $uses
     */
    private static function implementsRule(Class_ $class, array $uses): bool
    {
        foreach ($class->implements as $interface) {
            $written = $interface->toString();
            if (($uses[$interface->getFirst()] ?? $written) === 'PHPStan\\Rules\\Rule') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether something in the hierarchy declares `getNodeType()`, when the class itself says nothing.
     *
     * Two of phpat's rules — `HasOnlyOnePublicMethodRule` and `HasOnlyOnePublicMethodNamedRule` — are a
     * `final class X extends Y { use Z; }` with no `implements` clause. `Y` reaches `PHPStan\Rules\Rule`
     * through an interface three levels up, and `getNodeType()` is declared in the trait `Z`. Both earlier
     * tests answer false, so the walk was two short and the two were never counted, never refused, and never
     * visible — the silent-zero shape, one package deeper.
     *
     * {@see Hierarchy} resolves in PHP's own order, body then traits then parent, so the trait is what
     * answers here. An unresolvable chain answers false: a directory carries no claim that a file is a rule,
     * so a name this cannot follow is skipped rather than reported.
     *
     * @param array<string, string> $uses
     */
    private static function inheritsNodeType(Class_ $class, array $uses, string $file): bool
    {
        $index = new SourceIndex();
        $hierarchy = new Hierarchy(fn (string $shortName): ?array => $index->find($shortName, $file));

        return $hierarchy->declaring($class, 'getNodeType', $uses) !== null;
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
