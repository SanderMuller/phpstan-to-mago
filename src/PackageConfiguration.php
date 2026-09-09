<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use FilesystemIterator;
use Nette\Neon\Neon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A rule package's own configuration, read from the neon it ships.
 *
 * The values a rule is configured with are not guessable from its constructor signature. `services:` says
 * which argument is a configured value and which is a PHPStan service, `parametersSchema:` says what type a
 * value has, and `parameters:` says what it defaults to. Every package checked — `hihaho/phpstan-rules`,
 * `tomasvotruba/type-coverage`, `tomasvotruba/cognitive-complexity` and `symplify/phpstan-rules` — declares
 * all three, and points at the files from `composer.json`'s `extra.phpstan.includes`.
 *
 * That split is what decides whether a rule is portable at all: a configured value becomes a constructor
 * parameter on the generated plugin, carrying the package's default so a worker that passes nothing still
 * behaves like PHPStan. A PHPStan service has no injectable equivalent and has to be translated away at the
 * use site instead, so a rule that takes one is refused by name until it is.
 *
 * The defaults come from the rule package, never from a consuming project's `phpstan.neon`. That is what
 * keeps a generated plugin project independent — the consumer overrides through its worker instead.
 */
final readonly class PackageConfiguration
{
    /**
     * @param array<string, list<array{name: string, kind: 'config'|'service', reference: string}>> $arguments
     *        the constructor wiring of each rule class, in declaration order
     * @param array<string, mixed> $parameters the package's defaults, as a nested array
     */
    private function __construct(
        private array $arguments,
        private array $parameters,
        private string $root,
    ) {}

    /**
     * The configuration of the package a rule file belongs to, or null when it declares none.
     *
     * Walks up from the rule file looking for the `composer.json` that names the neon. Null is a real
     * answer rather than a failure: a rule with no configured arguments needs none of this.
     */
    public static function forRuleFile(string $ruleFile): ?self
    {
        $directory = dirname($ruleFile);
        while (! in_array($directory, ['', '/', dirname($directory)], true)) {
            $manifest = $directory . '/composer.json';
            if (is_file($manifest)) {
                return self::fromManifest($manifest);
            }

            $directory = dirname($directory);
        }

        return null;
    }

    /** @return list<array{name: string, kind: 'config'|'service', reference: string}> */
    public function argumentsFor(string $ruleClass): array
    {
        return $this->arguments[ltrim($ruleClass, '\\')] ?? [];
    }

    /**
     * The package's default for a dotted parameter path, or null when it declares none.
     *
     * `%noUnsafeRequestData.namespaces%` is spelled `noUnsafeRequestData.namespaces` here, and reads the
     * nested `parameters:` array one segment at a time.
     */
    public function defaultFor(string $path): mixed
    {
        $value = $this->parameters;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The parameter root a value-object class is built from, or null when it is not one.
     *
     * Both TomasVotruba packages build a `Configuration` from one whole parameter array and have their rules
     * call getters on it. The rules themselves are autowired, so the only place that mapping exists is the
     * factory's own wiring: `factory: TomasVotruba\TypeCoverage\Configuration, arguments: [%type_coverage%]`.
     */
    public function valueObjectRoot(string $class): ?string
    {
        $arguments = $this->argumentsFor($class);
        if (count($arguments) !== 1 || $arguments[0]['kind'] !== 'config') {
            return null;
        }

        return $this->hasParameter($arguments[0]['reference']) ? $arguments[0]['reference'] : null;
    }

    /**
     * The neons under the package root that wire this class differently from each other.
     *
     * Only the files `composer.json` auto-includes feed {@see argumentsFor()}, which is right: those are the
     * configuration a consumer gets by installing the package. But a rule wired *only* in a file a consumer
     * has to include by hand then reads as one "the package's neon does not wire", and that sentence is
     * false — the wiring is in the package, in a file this does not read.
     *
     * Measured across every neon in every installed package: 55 classes carry constructor wiring, 50 in
     * exactly one file, and 5 in two files that disagree. Of the five, four are `symplify/phpstan-rules`
     * rules and the fifth is a PHPStan parser service. Not one class is wired in a single hand-included file
     * *and* refused for it, so reading every neon here would move the emit count by zero. What it changes is
     * what four refusals say, and a refusal naming the wrong obstacle is how work gets sized wrongly.
     *
     * A conflict is a correct-forever refusal rather than a gap: which of the two values a consumer gets
     * depends on which config file it includes, so there is no single value a generated plugin could carry.
     *
     * Keyed by file, relative to the package root, with the argument names each file wires.
     *
     * @return array<string, list<string>>
     */
    public function conflictingWirings(string $ruleClass): array
    {
        $class = ltrim($ruleClass, '\\');
        $byFile = [];

        foreach ($this->neonFiles() as $path) {
            $decoded = Neon::decode((string) file_get_contents($path));
            if (! is_array($decoded) || ! is_array($decoded['services'] ?? null)) {
                continue;
            }

            foreach ($decoded['services'] as $service) {
                if (! is_array($service) || ($service['class'] ?? null) !== $class) {
                    continue;
                }

                $wiring = $service['arguments'] ?? null;
                if (! is_array($wiring) || $wiring === []) {
                    continue;
                }

                $relative = str_starts_with($path, $this->root . '/')
                    ? substr($path, strlen($this->root) + 1)
                    : $path;

                $byFile[$relative] = $wiring;
            }
        }

        if (count($byFile) < 2 || count(array_unique(array_map(json_encode(...), $byFile))) < 2) {
            return [];
        }

        return array_map(static fn (array $wiring): array => array_map(strval(...), array_keys($wiring)), $byFile);
    }

    /**
     * Every neon under the package root.
     *
     * Deliberately broad, the same choice {@see registeredClassNames()} records: a consumer includes whichever
     * config file it wants, so a file the manifest does not auto-include is still configuration the package
     * ships. Memoised per root, because this is asked once per unwired parameter.
     *
     * @return list<string>
     */
    private function neonFiles(): array
    {
        /** @var array<string, list<string>> $memo */
        static $memo = [];

        if (isset($memo[$this->root])) {
            return $memo[$this->root];
        }

        $found = [];
        if (! is_dir($this->root)) {
            return $memo[$this->root] = $found;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'neon') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $memo[$this->root] = $found;
    }

    public function hasParameter(string $path): bool
    {
        $value = $this->parameters;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    private static function fromManifest(string $manifest): ?self
    {
        /** @var array{extra?: array{phpstan?: array{includes?: list<string>}}}|null $decoded */
        $decoded = json_decode((string) file_get_contents($manifest), true);
        $includes = $decoded['extra']['phpstan']['includes'] ?? null;
        if (! is_array($includes) || $includes === []) {
            return null;
        }

        $root = dirname($manifest);
        $arguments = [];
        $parameters = [];
        foreach ($includes as $include) {
            // symplify spreads its configuration over four files, so each is merged rather than the first
            // one winning. A later file overriding an earlier one is the same order neon itself applies.
            [$fileArguments, $fileParameters] = self::readNeon($root . '/' . $include);
            $arguments = [...$arguments, ...$fileArguments];
            /** @var array<string, mixed> $parameters */
            $parameters = array_replace_recursive($parameters, $fileParameters);
        }

        return new self($arguments, $parameters, $root);
    }

    /**
     * Whether any neon the package ships names this rule at all.
     *
     * A rule nobody registers has no wiring, so every constructor parameter it declares reads as one the neon
     * "does not wire" — which is true and is not the cause. Eight of the nine rules a peer session ranked as a
     * configuration cluster were unregistered, and the census said so on every one of those lines; the refusal
     * did not, so the reason looked like a build target. This is what lets it say the real one.
     *
     * Every neon counts, not only the auto-included ones, and the match is on the short name. Both are
     * deliberate and both are the census's own rules — see {@see registeredClassNames()}.
     */
    public function registers(string $ruleClass): bool
    {
        $short = substr($ruleClass, (int) strrpos('\\' . $ruleClass, '\\'));

        return isset(self::registeredClassNames($this->root)[$short]);
    }

    /**
     * Every class short name any neon under a package root mentions.
     *
     * Registration is a *consumer* fact, so this is deliberately broad: `symplify/phpstan-rules` auto-includes
     * four of its thirteen config files and puts most rules behind `conditionalTags` that default off, and a
     * consumer lists those by hand. Keyed on auto-inclusion the package would read as registering almost
     * nothing, which is a worse denominator than the one it replaces rather than a better one.
     *
     * Memoised per root: the census asks it once per package and the transpiler once per refused rule.
     *
     * @return array<string, true>
     */
    public static function registeredClassNames(string $packageRoot): array
    {
        // A static local rather than a property: the class is `readonly`, which PHP extends to statics, and a
        // readonly static may not carry a default.
        /** @var array<string, array<string, true>> $memo */
        static $memo = [];

        if (isset($memo[$packageRoot])) {
            return $memo[$packageRoot];
        }

        $found = [];
        if (! is_dir($packageRoot)) {
            return $memo[$packageRoot] = $found;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'neon') {
                continue;
            }

            preg_match_all(
                '/[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/',
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches[0] as $reference) {
                $found[substr($reference, (int) strrpos($reference, '\\') + 1)] = true;
            }
        }

        return $memo[$packageRoot] = $found;
    }

    /**
     * @return array{array<string, list<array{name: string, kind: 'config'|'service', reference: string}>>, array<string, mixed>}
     */
    private static function readNeon(string $path): array
    {
        if (! is_file($path)) {
            return [[], []];
        }

        $decoded = Neon::decode((string) file_get_contents($path));
        if (! is_array($decoded)) {
            return [[], []];
        }

        /** @var array<string, mixed> $parameters */
        $parameters = is_array($decoded['parameters'] ?? null) ? $decoded['parameters'] : [];

        $arguments = [];
        /** @var list<mixed> $services */
        $services = is_array($decoded['services'] ?? null) ? array_values($decoded['services']) : [];
        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $class = $service['class'] ?? $service['factory'] ?? null;
            $wiring = $service['arguments'] ?? null;
            if (! is_string($class) || ! is_array($wiring)) {
                continue;
            }

            $arguments[$class] = self::classify($wiring);
        }

        // A neon `includes:` chain can carry the wiring one level further down.
        /** @var list<string> $nested */
        $nested = is_array($decoded['includes'] ?? null) ? $decoded['includes'] : [];
        foreach ($nested as $include) {
            [$deeper, $deeperParameters] = self::readNeon(dirname($path) . '/' . $include);
            $arguments = [...$deeper, ...$arguments];
            /** @var array<string, mixed> $parameters */
            $parameters = array_replace_recursive($deeperParameters, $parameters);
        }

        return [$arguments, $parameters];
    }

    /**
     * Sorts each wired argument into a configured value or a PHPStan service.
     *
     * `%path%` is a configured value and `@name` is a service. A literal — symplify wires a few — counts as
     * configured with no parameter behind it, so the generated constructor can carry it as its own default.
     *
     * @param array<array-key, mixed> $wiring
     *
     * @return list<array{name: string, kind: 'config'|'service', reference: string}>
     */
    private static function classify(array $wiring): array
    {
        $classified = [];
        foreach ($wiring as $name => $reference) {
            $spelled = is_string($reference) ? $reference : '';
            $kind = str_starts_with($spelled, '@') ? 'service' : 'config';
            $classified[] = [
                'name' => is_string($name) ? $name : (string) $name,
                'kind' => $kind,
                'reference' => trim($spelled, '%@'),
            ];
        }

        return $classified;
    }
}
