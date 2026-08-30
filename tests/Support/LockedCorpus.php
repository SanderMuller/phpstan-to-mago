<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

/**
 * Whether the installed rule packages are the ones `composer.lock` pins.
 *
 * Two tests here describe the *corpus* rather than the transpiler: the census records what each installed
 * package's rules do, and the orphan check asserts that every example pair has a rule to run against. Both
 * are facts about one dependency resolution, and CI asks them about two — `--prefer-lowest` installs older
 * packages with different rules in them, where `phpstan-deprecation-rules` holds sixteen rules rather than
 * two and two boolean-condition rules do not exist at all.
 *
 * So those runs cannot pass, and failing is the wrong answer: the code under test is fine and the corpus is
 * a different one. They skip instead, naming which package moved. What `--prefer-lowest` is for — that the
 * transpiler works against minimum supported versions — is still checked by every other test in the suite.
 *
 * Compared by version string rather than by resolving anything: the lock names one and the installed tree
 * reports one, and if they differ the corpus is not the corpus these tests were written against.
 */
final class LockedCorpus
{
    /**
     * The rule packages the census and the fires-gate read.
     *
     * Listed here rather than derived from either test, because the point is that both ask about the same
     * set and a third caller should not have to pick.
     *
     * @var list<string>
     */
    public const array PACKAGES = [
        'symplify/phpstan-rules',
        'hihaho/phpstan-rules',
        'tomasvotruba/type-coverage',
        'tomasvotruba/cognitive-complexity',
        'phpstan/phpstan-strict-rules',
        'phpstan/phpstan-phpunit',
        'phpstan/phpstan-deprecation-rules',
    ];

    /** A reason to skip, or null when every corpus package matches the lock. */
    public static function mismatch(): ?string
    {
        $locked = self::versions(dirname(__DIR__, 2) . '/composer.lock', ['packages', 'packages-dev']);
        $installed = self::versions(dirname(__DIR__, 2) . '/vendor/composer/installed.json', ['packages']);

        foreach (self::PACKAGES as $package) {
            $want = $locked[$package] ?? null;
            $have = $installed[$package] ?? null;
            if ($want === null || $have === null || $want === $have) {
                continue;
            }

            return sprintf(
                'The installed corpus is not the locked one: %s is %s and composer.lock pins %s. These '
                . 'assertions describe what the locked packages contain, so a different resolution — '
                . '`--prefer-lowest`, or a manual install — is a different corpus rather than a regression.',
                $package,
                $have,
                $want,
            );
        }

        return null;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function versions(string $file, array $keys): array
    {
        if (! is_file($file)) {
            return [];
        }

        /** @var array<string, list<array{name?: string, version?: string}>> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true) ?? [];

        $versions = [];
        foreach ($keys as $key) {
            foreach ($decoded[$key] ?? [] as $package) {
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                if (is_string($name) && is_string($version)) {
                    $versions[$name] = $version;
                }
            }
        }

        return $versions;
    }
}
