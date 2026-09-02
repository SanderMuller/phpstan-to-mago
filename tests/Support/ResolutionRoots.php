<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

/**
 * What mago scans for symbols without analysing: the vendor tree and the consumer's autoload roots.
 *
 * `includes` is the resolution context, and it is what makes a differential fair. PHPStan reaches a class
 * through the consumer's autoloader, which does not stop at the analysed directories and names exactly one
 * file per class. Mago reaches one by scanning whatever it was pointed at. The two questions this class
 * answers are the two ways that gap has already produced a wrong number.
 *
 * **Which directories.** A `--paths` subset leaves the rest of the consumer unreadable to mago. Measured on
 * `--paths=tests` over one consumer: `symplify.noDynamicName` reported 29 sites the original does not, every
 * one of them `($this->handler)(..)` on a class declared under `app/`, where the `__invoke` test could not
 * run because mago had never read the class.
 *
 * **Which files inside them.** The whole project directory is the obvious answer and the wrong one. A Laravel
 * project keeps `_ide_helper.php` at its root — a second declaration of framework classes, at a path with no
 * `vendor` segment. Mago resolved `Illuminate\Foundation\Http\FormRequest` to *that* file, so
 * `ForbiddenExtendOfNonAbstractClassRule`'s "skip vendor classes" guard could not hold and the port reported
 * 119 sites the original does not. Composer's autoload map is what PHPStan resolves through and it names one
 * file per class, so following it is the fix rather than excluding stub files by name.
 *
 * A consumer with no readable `composer.json` gets the vendor tree alone, which is where this started.
 */
final class ResolutionRoots
{
    /**
     * @param list<string> $analysedPaths absolute directories the run analyses
     *
     * @return list<string> absolute paths, each already quoted for TOML
     */
    public static function of(string $consumerRoot, array $analysedPaths): array
    {
        $roots = ['"' . $consumerRoot . '/vendor"'];

        $seen = [];
        foreach (self::autoloadPaths($consumerRoot) as $path) {
            $absolute = $consumerRoot . '/' . trim($path, '/');
            if (isset($seen[$absolute]) || (! is_dir($absolute) && ! is_file($absolute))) {
                continue;
            }

            if (self::overlaps($absolute, $analysedPaths)) {
                continue;
            }

            $seen[$absolute] = true;
            $roots[] = '"' . $absolute . '"';
        }

        return $roots;
    }

    /**
     * Every path the consumer's `composer.json` autoloads, in whichever of the four shapes it wrote them.
     *
     * @return list<string> consumer-relative, unfiltered
     */
    private static function autoloadPaths(string $consumerRoot): array
    {
        $manifest = $consumerRoot . '/composer.json';
        $decoded = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        if (! is_array($decoded)) {
            return [];
        }

        $paths = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $block = $decoded[$section] ?? null;
            if (! is_array($block)) {
                continue;
            }

            // The four shapes composer accepts. A `psr-4` value may be one path or a list of them, so every
            // entry is read as a list and a `classmap` or `files` entry falls through the same way.
            foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
                foreach ((array) ($block[$kind] ?? []) as $entry) {
                    foreach ((array) $entry as $path) {
                        if (is_string($path)) {
                            $paths[] = $path;
                        }
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * Whether an autoload root is, contains, or sits inside a directory the run analyses.
     *
     * A root that is already analysed is left out, and that is not tidiness. An `includes` entry is scanned
     * rather than analysed, and naming an analysed directory in both takes it out of the corpus: the same
     * consumer went from 912 agreeing to **35 agreeing and 966 original-only** with `app` and `tests` in both
     * lists. So this adds resolution context for what the run does *not* analyse, which is exactly the case a
     * `--paths` subset creates and the whole reason the roots are here.
     *
     * @param list<string> $analysedPaths
     */
    private static function overlaps(string $root, array $analysedPaths): bool
    {
        foreach ($analysedPaths as $analysed) {
            if ($root === $analysed
                || str_starts_with($root . '/', $analysed . '/')
                || str_starts_with($analysed . '/', $root . '/')
            ) {
                return true;
            }
        }

        return false;
    }
}
