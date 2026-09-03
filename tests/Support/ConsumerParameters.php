<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Support;

use Closure;

/**
 * The consumer's own values for the container parameters an emitted plugin declares.
 *
 * A plugin whose behaviour depends on a container parameter carries it as a constructor parameter at the
 * package default, so a worker that passes nothing runs at *PHPStan's* defaults rather than at the
 * consumer's. The corpus differential would then report that difference as a disagreement: on hihaho the
 * plugins ran at `checkUnionTypes: false` against a project at level 7, where it is true, and the port
 * stayed silent on unions the original reported.
 *
 * Not derived from a level. Four of the six flags `RuleLevelHelper` reads turn on one per level from 7 to
 * 10, `checkBenevolentUnionTypes` appears in no level config at all, and a project may set any of them
 * directly — which a level-to-flag table would read wrongly.
 *
 * Run with the *consumer's* binary, because their config names parameters their own extensions declare and
 * this repository's PHPStan rejects it as invalid configuration before dumping anything. Against their own
 * config file rather than the differential's sandbox one, which does not exist yet when the worker is
 * written -- the first version pointed at the sandbox, got an error, and returned no parameters at all. It
 * failed silently, and the run that followed reported numbers identical to the run before it. Identical
 * numbers were the only reason it was caught, so the failure is reported now rather than swallowed.
 *
 * Read by name out of the dump's text rather than by decoding it. `dump-parameters --json` emits the whole
 * container -- 90kB including the process environment -- and on a real project that document does not
 * decode: braces balance, the bytes are valid UTF-8 and there are no control characters, and
 * `json_decode` still answers `Syntax error`. What is wanted here is six named booleans, so depending on
 * the other 90kB parsing is a dependency this has no use for.
 */
final class ConsumerParameters
{
    private ?string $parameters = null;

    /** Why no parameters could be read, for the caller to print. Null while it has not been tried or worked. */
    public ?string $failure = null;

    /**
     * @param Closure(list<string>): string $capture runs a command in the consumer's root and returns stdout
     */
    public function __construct(
        private readonly string $consumerRoot,
        private readonly string $sandbox,
        private readonly Closure $capture,
        /** @var array<string, bool> forced values, which win over what the consumer's own config resolves to */
        private readonly array $overrides = [],
    ) {}

    /**
     * The named arguments to construct one plugin with, or an empty string where it declares none.
     *
     * Read from the generated file rather than from a list, so a plugin that starts carrying a parameter is
     * handled without a second place to keep in step. Only names the consumer actually defines are passed;
     * anything else keeps the package default, which is what a consumer who has not set it would get.
     */
    public function argumentsFor(string $plugin): string
    {
        $source = (string) file_get_contents($this->sandbox . '/plugins/' . $plugin . '.php');
        if (preg_match_all('/public readonly \w+ \$(\w+) =/', $source, $matches) === 0) {
            return '';
        }

        // The generated plugin says which PHPStan parameter each argument came from, on the `@param` line
        // beside it. Read from there rather than by matching the argument's own name against the dump: the
        // two differ wherever the option is nested, and every aggregate is — `$required` is
        // `%type_coverage.declare%`, and matching on `required` finds nothing at all. That mismatch is what
        // made a differential run compare the consumer's configured original against a port at package
        // defaults: 436 of the 953 divergences on one Laravel run were that and nothing else.
        $origins = [];
        preg_match_all('/@param \w+ \$(\w+) PHPStan\'s `%([^%]+)%`/', $source, $declared, PREG_SET_ORDER);
        foreach ($declared as $origin) {
            $origins[$origin[1]] = $origin[2];
        }

        $arguments = [];
        foreach ($matches[1] as $parameter) {
            if (array_key_exists($parameter, $this->overrides)) {
                $arguments[] = $parameter . ': ' . ($this->overrides[$parameter] ? 'true' : 'false');

                continue;
            }

            $value = isset($origins[$parameter]) ? $this->valueAt($origins[$parameter]) : null;
            if ($value !== null) {
                $arguments[] = $parameter . ': ' . $value;

                continue;
            }

            // The older path, kept for a plugin emitted before the `@param` line existed and for one whose
            // parameter the consumer does not define. Anchored to the dump's own two-column indent so a key
            // of the same name nested inside another structure cannot answer for the top-level parameter.
            if (preg_match('/^    "' . preg_quote($parameter, '/') . '": (true|false),?$/m', $this->dump(), $found) === 1) {
                $arguments[] = $parameter . ': ' . $found[1];
            }
        }

        return implode(', ', $arguments);
    }

    /**
     * A parameter's value as the dump wrote it, following a dotted path, or null where the consumer sets none.
     *
     * Text rather than `json_decode`, for the reason this class's header gives: the dump does not decode on a
     * real project. Each segment but the last narrows the text to that key's block, anchored to the indent
     * the level sits at, so `type_coverage.declare` cannot be answered by a `declare` key somewhere else.
     *
     * Booleans, integers and floats only. A list or a nested structure is not something a threshold argument
     * takes, and passing one as a named argument would need it rendered rather than copied.
     */
    private function valueAt(string $path): ?string
    {
        $text = $this->dump();
        $segments = explode('.', $path);
        $last = array_pop($segments);

        foreach ($segments as $depth => $segment) {
            $indent = str_repeat(' ', 4 + $depth * 4);
            $opening = $indent . '"' . $segment . '": {';
            $at = strpos($text, "\n" . $opening);
            if ($at === false) {
                return null;
            }

            $closing = strpos($text, "\n" . $indent . '}', $at);
            $text = $closing === false ? substr($text, $at) : substr($text, $at, $closing - $at);
        }

        $indent = str_repeat(' ', 4 + count($segments) * 4);
        $pattern = '/^' . preg_quote($indent, '/') . '"' . preg_quote($last, '/')
            . '": (true|false|-?\d+(?:\.\d+)?),?$/m';

        return preg_match($pattern, $text, $found) === 1 ? $found[1] : null;
    }

    /** The consumer's own configuration file, which is where their parameter values are declared. */
    private function configuration(): string
    {
        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $name) {
            if (is_file($this->consumerRoot . '/' . $name)) {
                return $this->consumerRoot . '/' . $name;
            }
        }

        return $this->consumerRoot . '/phpstan.neon';
    }

    /** The consumer's resolved container, as the text `dump-parameters` printed. */
    private function dump(): string
    {
        if ($this->parameters !== null) {
            return $this->parameters;
        }

        $command = [
            $this->consumerRoot . '/vendor/bin/phpstan',
            'dump-parameters',
            '--json',
            '--configuration=' . $this->configuration(),
        ];

        $this->parameters = ($this->capture)($command);

        // Not fatal: the plugins keep the package defaults, which is what a consumer who set nothing would
        // get, and the differential still measures a real difference. But it measures a *wider* one, so the
        // reason is recorded for the caller to print rather than left to be inferred from numbers that
        // happen not to move.
        if (! str_starts_with($this->parameters, '{')) {
            $this->failure = 'could not read parameters from ' . $this->configuration();
        }

        return $this->parameters;
    }
}
