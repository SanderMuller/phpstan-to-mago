<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use Nette\Neon\Neon;

/**
 * The rules a project's own PHPStan configuration registers, asked of PHPStan rather than inferred.
 *
 * Scanning a directory answers a different question to the one that matters. `hihaho/phpstan-rules` ships
 * more rule classes than any project turns on, `symplify/phpstan-rules` ships forty and a project may use
 * twelve, and a rule can arrive through an `includes:` two files deep or through `extension-installer`
 * without appearing in the project's own `rules:` list at all. A coverage figure whose denominator is files
 * on disk is therefore measuring the package, not the project, and reads as worse than it is; a figure that
 * misses a rule arriving through an include reads as better.
 *
 * So the denominator is taken from the container. PHPStan is run in `diagnose`, which builds the container
 * and runs no analysis, with a bootstrap file that names every service registered under the rule tag. That
 * settles level resolution, includes, and extension discovery by letting PHPStan do them.
 *
 * The strongest case for that measured so far: `phpstan/phpstan-strict-rules` is a direct dev dependency of a
 * consumer, is listed in `extension-installer`'s generated config, and registers **none** of its 27 rules —
 * because its `rules.neon` gates every one behind `conditionalTags` keyed on `%strictRules.allRules%`, and the
 * project sets that to `false`. A config reader would have to evaluate a conditional tag against an
 * interpolated parameter to get that right. Asking the container is the only route that does.
 *
 * This says nothing about how a rule is configured. Generated plugins deliberately carry their own package's
 * defaults rather than a consuming project's overrides, so that a generated project stands alone; see
 * `PackageConfiguration`.
 */
final readonly class RegisteredRules
{
    /**
     * @param list<array{class: string, file: string|null, core: bool, services: int, arguments?: array<string, mixed>}> $rules
     */
    private function __construct(
        public array $rules,
        public string $configFile,
    ) {}

    /**
     * @throws Refusal when the project cannot be read, or PHPStan will not start in it
     */
    public static function discover(string $path, ?string $phpstanBinary = null): self
    {
        [$projectRoot, $configFile] = self::locate($path);

        $binary = $phpstanBinary ?? $projectRoot . '/vendor/bin/phpstan';
        if (! is_file($binary)) {
            throw new Refusal('no PHPStan to ask in ' . $projectRoot . ' (looked for ' . $binary . ')');
        }

        // Kept out of the project. PHPStan resolves a relative `includes:` against the file that wrote it,
        // so naming the project's config absolutely leaves its own relative includes resolving as before,
        // and nothing is written into a repository this tool was only asked to read. The `.neon` extension
        // is not decoration: PHPStan's loader picks its parser by extension and refuses a file without one.
        $workspace = sys_get_temp_dir() . '/phpstan-to-mago-' . bin2hex(random_bytes(8));
        if (! @mkdir($workspace, 0700) && ! is_dir($workspace)) {
            throw new Refusal('could not create a working directory in ' . sys_get_temp_dir());
        }

        $overlay = $workspace . '/discovery.neon';
        $destination = $workspace . '/rules.json';

        file_put_contents($overlay, Neon::encode([
            'includes' => [$configFile],
            'parameters' => ['bootstrapFiles' => [dirname(__DIR__) . '/resources/registered-rules.php']],
        ], true));

        try {
            $output = self::run($binary, $overlay, $projectRoot, $destination);
            $payload = self::decode(is_file($destination) ? (string) file_get_contents($destination) : '', $output);
        } finally {
            // Tested for rather than suppressed. `@unlink()` on a file PHPStan never wrote still raises the
            // warning, and PHPUnit's error handler turns that into a failed run under `failOnWarning` — a
            // suppressed diagnostic is invisible locally and fatal in CI, which is the worst of both.
            foreach ([$overlay, $destination] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            if (is_dir($workspace)) {
                rmdir($workspace);
            }
        }

        return new self($payload, $configFile);
    }

    /**
     * The rule files worth transpiling: everything the project registered that PHPStan does not ship itself.
     *
     * Mago implements its own equivalents of PHPStan's built-in rules, so carrying them across would produce
     * two of each. What a project cannot get any other way is its own rules and its packages'.
     *
     * @return list<string>
     */
    public function portableFiles(): array
    {
        $files = [];
        foreach ($this->rules as $rule) {
            if ($rule['core'] || $rule['file'] === null) {
                continue;
            }

            $files[$rule['file']] = true;
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * The configured values a rule was built with, by property name, as the project's container made it.
     *
     * Empty for a rule whose constructor takes nothing carryable, and empty for a class this project does
     * not register — the two are the same answer here on purpose, because both mean "no consumer value for
     * this property" and the transpiler's refusal already distinguishes why.
     *
     * The package's own neon stays the source wherever it wires the rule; see `PackageConfiguration`. This
     * is for the rules it registers nowhere, where a consumer that wants them registers and configures them
     * itself, and the container is the only place those values exist.
     *
     * @return array<string, mixed>
     */
    public function argumentsFor(string $class): array
    {
        $wanted = ltrim($class, '\\');
        foreach ($this->rules as $rule) {
            if ($rule['class'] === $wanted) {
                return $rule['arguments'] ?? [];
            }
        }

        return [];
    }

    /**
     * Classes registered more than once, with how many services each has.
     *
     * Worth naming rather than collapsing: two services of one class are two configurations, and a generated
     * plugin carries one. `spaze/phpstan-disallowed-calls` reaches this state whenever two of its shipped
     * configs are included together.
     *
     * @return array<string, int>
     */
    public function duplicated(): array
    {
        $duplicates = [];
        foreach ($this->rules as $rule) {
            if ($rule['services'] > 1) {
                $duplicates[$rule['class']] = $rule['services'];
            }
        }

        return $duplicates;
    }

    public function coreCount(): int
    {
        return count(array_filter($this->rules, static fn (array $rule): bool => $rule['core']));
    }

    public function portableCount(): int
    {
        return count($this->rules) - $this->coreCount();
    }

    /**
     * @return array{0: string, 1: string} the project root, and the config file to include
     *
     * @throws Refusal when neither a config file nor a project holding one was named
     */
    private static function locate(string $path): array
    {
        if (is_file($path)) {
            return [dirname((string) realpath($path)), (string) realpath($path)];
        }

        if (! is_dir($path)) {
            throw new Refusal('no such path: ' . $path);
        }

        $root = (string) realpath($path);
        foreach (['/phpstan.neon', '/phpstan.neon.dist', '/phpstan.dist.neon'] as $candidate) {
            if (is_file($root . $candidate)) {
                return [$root, $root . $candidate];
            }
        }

        throw new Refusal('no phpstan.neon, phpstan.neon.dist or phpstan.dist.neon in ' . $root);
    }

    private static function run(string $binary, string $overlay, string $projectRoot, string $destination): string
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($binary)
            . ' diagnose -c ' . escapeshellarg($overlay)
            . ' --no-ansi 2>&1';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $projectRoot,
            ['PHPSTAN_TO_MAGO_RULES_FILE' => $destination] + getenv(),
        );

        if (! is_resource($process)) {
            throw new Refusal('could not start PHPStan in ' . $projectRoot);
        }

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    /**
     * @return list<array{class: string, file: string|null, core: bool, services: int, arguments?: array<string, mixed>}>
     *
     * @throws Refusal when PHPStan wrote nothing, which is a failed run rather than a project with no rules
     */
    private static function decode(string $written, string $output): array
    {
        if (trim($written) === '') {
            throw new Refusal("PHPStan did not report its registered rules:\n" . trim($output));
        }

        /** @var array{ok?: bool, error?: string|null, rules?: list<array{class: string, file: string|null, core: bool, services: int, arguments?: array<string, mixed>}>}|null $payload */
        $payload = json_decode($written, true);
        if (! is_array($payload) || ! isset($payload['ok'])) {
            throw new Refusal("PHPStan reported its registered rules in a shape this does not understand:\n" . $written);
        }

        if (! $payload['ok']) {
            throw new Refusal('PHPStan could not report its registered rules: ' . ($payload['error'] ?? 'no reason given'));
        }

        return $payload['rules'] ?? [];
    }
}
