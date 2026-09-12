<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use ReflectionClass;

/**
 * The `[source] includes` a set of emitted plugins actually needs.
 *
 * Mago resolves a name by *indexing* every file under `includes`, where PHPStan resolves one arithmetically
 * from composer metadata and reads a single file. So a consumer who points `includes` at `vendor` pays for
 * every file in it, on every run. Measured on a 270-file corpus with 14,805 files of `vendor`, `src` and
 * `tests` included: **3.80s of a 4.99s run is that index, and mago analyses the 270 files in 0.12s.** The
 * cost is per file and slightly superlinear -- 3,187 files index in 0.45s, 0.14ms each, against 0.27ms each
 * at 14,805.
 *
 * The includes are not optional. Without them mago cannot walk into a vendored parent, and a rule asking
 * about one goes quiet rather than failing -- the asymmetry this repository's corpus differential found
 * first. So the lever is their *width*, and the width is derivable: an emitted plugin compares against a
 * fixed set of class names, and only the packages holding those names and their ancestors need indexing.
 *
 * **Resolved by reflection rather than by PSR-4 arithmetic, and that is the load-bearing choice.** A named
 * class's ancestors routinely live in another package -- `Doctrine\Bundle\DoctrineBundle\Repository\
 * ServiceEntityRepository` extends `Doctrine\ORM\EntityRepository` -- so a prefix-to-directory map covers the
 * name and misses what the name inherits. `class_parents()`, `class_implements()` and `class_uses()` give the
 * closure exactly, and `ReflectionClass::getFileName()` gives each one's file without this having to
 * reimplement composer's resolution.
 *
 * **Package directories rather than the exact files, and the cheaper option was measured wrong.** Including
 * only the 59 files behind the 52 names is indistinguishable from no includes at all -- 0.11s against 3.98s
 * -- and mago accepts file paths, so it looked like the answer. Run over `tests/Fixtures/examples`, the
 * corpus that exists to make every rule fire, it lost a finding: `NoPropertyNodeAssignRule` went **quiet**.
 * The reason is that mago must resolve the *analysed* code's ancestry, not the rules', and the analysed class
 * inherits from a vendored file no rule names. Package roots cover that because the sibling lives in the same
 * package, and they reproduce all 502 findings across 91 rules exactly.
 *
 * **Which is a match, not a proof.** No set derived from the rules can be proven sufficient, because the
 * files needed depend on what the consumer's own code inherits from. A consumer whose classes extend a
 * package no rule names has to add it, and the failure mode is a rule reporting nothing rather than an error
 * -- so the emitted snippet states that rather than presenting the list as complete.
 */
final class RecommendedIncludes
{
    /**
     * Every namespaced class name an emitted plugin compares against, and the packages that hold them.
     *
     * @param list<string> $emittedFiles the generated plugin sources
     * @return list<string> absolute directories, sorted
     */
    public static function forEmitted(array $emittedFiles): array
    {
        $roots = [];
        foreach (self::namesIn($emittedFiles) as $name) {
            foreach (self::filesBehind($name) as $file) {
                $root = self::packageRootOf($file);
                if ($root !== null) {
                    $roots[$root] = true;
                }
            }
        }

        foreach (array_keys($roots) as $root) {
            $canonical = self::canonicalCopyOf($root);
            if ($canonical !== null) {
                $roots[$canonical] = true;
            }
        }

        $sorted = array_keys($roots);
        sort($sorted);

        return $sorted;
    }

    /**
     * The top-level copy of a package found inside a nested `vendor/`, when one exists.
     *
     * **Which copy of a duplicated package this resolves is an accident of autoload order**, and the accident
     * is observable: reflecting this repository's own emitted rules answers
     * `vendor/rector/rector/vendor/nikic/php-parser` and *not* the top-level `vendor/nikic/php-parser`, even
     * though both are installed -- rector's nested copy is simply the one that got loaded first for those
     * names.
     *
     * That matters because the consumer's code resolves against whichever copy *their* autoloader picks, and
     * it need not be ours. Excluding the nested tree from the include set was measured to lose a finding:
     * `NoPropertyNodeAssignRule` stopped reporting, because its fixture asks whether `new
     * PhpParser\Node\Expr\Variable(..)` is a `PhpParser\Node` and the only php-parser mago had indexed was
     * the nested one. Both copies cost little and guessing which one a consumer resolves against costs a
     * silent rule, so both go in.
     */
    private static function canonicalCopyOf(string $root): ?string
    {
        $nested = strpos($root, '/vendor/');
        $last = strrpos($root, '/vendor/');
        if ($nested === false || $last === false || $nested === $last) {
            return null;
        }

        $canonical = substr($root, 0, $nested) . substr($root, $last);

        return is_dir($canonical) ? $canonical : null;
    }

    /**
     * The namespaced string literals the emitted sources hold.
     *
     * Read from the emitted files rather than collected during translation, and deliberately: what matters is
     * the name the *plugin* will look up, and the emitted file is that by definition. A name added by a
     * vocabulary change therefore needs no second registration here.
     *
     * @param list<string> $emittedFiles
     * @return list<string>
     */
    private static function namesIn(array $emittedFiles): array
    {
        $names = [];
        foreach ($emittedFiles as $file) {
            $found = preg_match_all(
                "/'((?:[A-Za-z_][A-Za-z0-9_]*\\\\\\\\)+[A-Za-z_][A-Za-z0-9_]*)'/",
                (string) file_get_contents($file),
                $matches,
            );

            if ($found === false) {
                continue;
            }

            foreach ($matches[1] as $match) {
                $names[stripcslashes($match)] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * The files holding a class and everything it inherits, or none when it is not installed.
     *
     * A name the consumer's tree does not have needs no include -- the rule comparing against it can never
     * match, and inventing a directory for it would be guessing. Internal classes have no file and answer
     * false from `getFileName()`.
     *
     * @return list<string>
     */
    private static function filesBehind(string $name): array
    {
        if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name)) {
            return [];
        }

        $related = [$name];
        foreach ([class_parents($name), class_implements($name), class_uses($name)] as $group) {
            if ($group !== false) {
                $related = [...$related, ...array_keys($group)];
            }
        }

        $files = [];
        foreach (array_unique($related) as $each) {
            // Narrowed by the same three predicates the guard above uses: `class_parents()` and its siblings
            // answer names that exist by construction, but nothing in their signatures says so.
            if (! class_exists($each) && ! interface_exists($each) && ! trait_exists($each)) {
                continue;
            }

            $file = (new ReflectionClass($each))->getFileName();
            if ($file !== false) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The composer package directory a file sits in, or null when it is not under one.
     *
     * `vendor/<vendor>/<package>` rather than the PSR-4 subdirectory inside it: a package's own internals are
     * what its classes inherit from, and splitting them buys little while risking exactly the ancestor a
     * prefix map would miss. A file outside `vendor` is the consumer's own code, which they already point
     * `paths` at.
     */
    private static function packageRootOf(string $file): ?string
    {
        // A class reachable only inside a phar answers a `phar://` path, and `PHPStan\Rules\Rule` -- which
        // the fixture rules compare against -- is exactly that. There is no directory to index: mago reads
        // files, and the archive's interior is not one. Skipped rather than translated into the phar's own
        // path, which would put a non-directory in a consumer's config.
        if (! str_starts_with($file, '/')) {
            return null;
        }

        $at = strrpos($file, '/vendor/');
        if ($at === false) {
            return null;
        }

        $parts = explode('/', substr($file, $at + strlen('/vendor/')));
        if (count($parts) < 3) {
            return null;
        }

        return substr($file, 0, $at) . '/vendor/' . $parts[0] . '/' . $parts[1];
    }
}
