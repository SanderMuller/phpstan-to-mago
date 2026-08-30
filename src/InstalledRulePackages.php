<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

/**
 * The PHPStan rule packages a project has installed.
 *
 * Read from `vendor/composer/installed.json` rather than from `composer.json`, because a rule package
 * arriving as a transitive dependency still ships rules a consumer can run, and because the installed file
 * carries the resolved version — which a status page needs and `composer.json`'s constraint cannot give.
 *
 * Two filters, and the second is the one that matters. `extra.phpstan` finds candidates: it is how a package
 * tells the extension installer it has something to register. It also matches packages that ship a PHPStan
 * *extension* and no rules at all — `nesbot/carbon`, `pestphp/pest` and `composer/pcre` all match here and
 * ship nothing this tool could transpile. So a candidate is kept only when {@see RulePaths} actually finds a
 * rule class under it. That is a walk of the filesystem rather than a guess about a manifest, and it is the
 * same check the transpiler makes when it is handed a directory.
 *
 * A curated list was the other option and is wrong for this: the point of a status page is to describe the
 * install in front of the reader, including a rule package this repository has never seen.
 */
final readonly class InstalledRulePackages
{
    /**
     * @param list<string> $ruleFiles rule classes found under this package
     */
    private function __construct(
        public string $name,
        public string $version,
        public string $root,
        public array $ruleFiles,
    ) {}

    /**
     * Every installed package that ships at least one PHPStan rule.
     *
     * Sorted by name, so two runs against the same install render the same page.
     *
     * @return list<self>
     */
    public static function discover(string $projectRoot): array
    {
        $manifest = $projectRoot . '/vendor/composer/installed.json';
        if (! is_file($manifest)) {
            throw new Refusal('no installed packages to read in ' . $projectRoot . ' (looked for ' . $manifest . ')');
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);
        if (! is_array($decoded)) {
            throw new Refusal('could not read ' . $manifest);
        }

        // Composer 2 nests the list under `packages`; Composer 1 wrote the list itself. Both are still in the
        // wild in vendor directories this tool is pointed at, so neither shape is assumed.
        $packages = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;

        $found = [];
        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $extra = $package['extra'] ?? null;
            if (! is_array($extra) || ! isset($extra['phpstan'])) {
                continue;
            }

            $root = $projectRoot . '/vendor/' . $package['name'];
            $source = is_dir($root . '/src') ? $root . '/src' : $root;
            if (! is_dir($source)) {
                continue;
            }

            $ruleFiles = RulePaths::expand([$source]);
            if ($ruleFiles === []) {
                continue;
            }

            $found[$package['name']] = new self(
                name: $package['name'],
                version: is_string($package['version'] ?? null) ? $package['version'] : 'unknown',
                root: $root,
                ruleFiles: $ruleFiles,
            );
        }

        ksort($found);

        return array_values($found);
    }
}
