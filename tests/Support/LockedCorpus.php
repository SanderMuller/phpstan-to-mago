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
 * Compared against the versions the *census* records, not against `composer.lock`. CI installs with
 * `composer update --prefer-lowest`, which rewrites the lock in the workspace, so a lock-versus-installed
 * check agrees with itself on exactly the run it needs to catch — measured, after that was the first fix and
 * it changed nothing. The census is committed and regenerated deliberately, which makes it the record an
 * install cannot move.
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

    /** Where the versions this corpus was recorded against are written down. */
    private const string CENSUS = __DIR__ . '/../Fixtures/expected/census.md';

    /**
     * The versions each rule package is installed at, by name.
     *
     * @return array<string, string>
     */
    public static function installed(): array
    {
        $versions = self::versions(dirname(__DIR__, 2) . '/vendor/composer/installed.json');

        return array_intersect_key($versions, array_flip(self::PACKAGES));
    }

    /** A reason to skip, or null when the installed corpus is the one the census records. */
    public static function mismatch(): ?string
    {
        $recorded = self::recorded();
        if ($recorded === []) {
            return null;
        }

        foreach (self::installed() as $package => $have) {
            $want = $recorded[$package] ?? null;
            if ($want === null || $want === $have) {
                continue;
            }

            return sprintf(
                'The installed corpus is not the one the census records: %s is %s and the census was '
                . 'generated against %s. These assertions describe what those packages contain, so a '
                . 'different resolution is a different corpus rather than a regression.',
                $package,
                $have,
                $want,
            );
        }

        return null;
    }

    /**
     * The versions the committed census names, read back out of it.
     *
     * Not `composer.lock`. CI installs with `composer update --prefer-lowest`, which *rewrites* the lock in
     * the workspace — so a lock-versus-installed comparison agrees with itself on exactly the run this needs
     * to detect. The census is committed, regenerated deliberately, and cannot be rewritten by an install,
     * which makes it the only record here that survives one.
     *
     * @return array<string, string>
     */
    private static function recorded(): array
    {
        if (! is_file(self::CENSUS)) {
            return [];
        }

        preg_match_all(
            '/^ {4}(\S+\/\S+) {2,}(\S+)$/m',
            (string) file_get_contents(self::CENSUS),
            $matches,
            PREG_SET_ORDER,
        );

        $recorded = [];
        foreach ($matches as $match) {
            $recorded[$match[1]] = $match[2];
        }

        return $recorded;
    }

    /** @return array<string, string> */
    private static function versions(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        /** @var array{packages?: list<array{name?: string, version?: string}>} $decoded */
        $decoded = json_decode((string) file_get_contents($file), true) ?? [];

        $versions = [];
        foreach ($decoded['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;
            if (is_string($name) && is_string($version)) {
                $versions[$name] = $version;
            }
        }

        return $versions;
    }
}
