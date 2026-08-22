<?php

declare(strict_types=1);

/**
 * Names every rule PHPStan actually registered, from inside PHPStan's own process.
 *
 * This runs as a `bootstrapFiles:` entry, which `CommandHelper` requires from inside a closure that holds
 * the built container, so `$container` is in scope here. That is the whole reason this file exists as a
 * bootstrap file rather than a script: building the container from outside means resolving `level:`,
 * `includes:`, extension-installer entries and the phar's namespace prefixing by hand, and getting any of
 * that wrong changes the answer without failing.
 *
 * A rule is core when it ships with PHPStan itself. That is decided by comparing install roots rather than
 * namespaces, because a third-party package is free to declare its rules in `PHPStan\Rules\` and
 * `phpstan/phpstan-phpunit` does exactly that.
 *
 * Every failure writes a payload saying so. A missing file and a project with no rules of its own look the
 * same from the outside, and reading one as the other is how a survey reports full coverage of nothing.
 */

use PHPStan\Rules\Rule;

(static function (mixed $container): void {
    $destination = getenv('PHPSTAN_TO_MAGO_RULES_FILE');
    if (! is_string($destination) || $destination === '') {
        return;
    }

    $write = static function (array $payload) use ($destination): void {
        file_put_contents($destination, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    };

    if (! is_object($container) || ! method_exists($container, 'getExtensionsCollection')) {
        $write([
            'ok' => false,
            'error' => 'PHPStan did not expose its container to the bootstrap file, so the registered rules could not be read.',
            'rules' => [],
        ]);

        return;
    }

    try {
        $registered = $container->getExtensionsCollection(Rule::class)->getAll();
    } catch (Throwable $e) {
        $write([
            'ok' => false,
            'error' => get_class($e) . ': ' . $e->getMessage(),
            'rules' => [],
        ]);

        return;
    }

    // Where PHPStan itself lives, taken from a class it is guaranteed to own. Inside a phar this is a
    // `phar://` path, and in a source install an ordinary one; both compare the same way.
    $normalise = static fn (string $path): string => str_replace('\\', '/', $path);

    $coreRoot = null;
    $ruleInterface = (new ReflectionClass(Rule::class))->getFileName();
    if (is_string($ruleInterface)) {
        // `src/Rules/Rule.php` up to the install root, which is the phar itself in a phar install.
        $coreRoot = $normalise(dirname($ruleInterface, 3)) . '/';
    }

    $rules = [];
    foreach ($registered as $rule) {
        $reflection = new ReflectionClass($rule);
        $file = $reflection->getFileName();
        $file = is_string($file) ? $file : null;

        $class = $reflection->getName();
        if (isset($rules[$class])) {
            // The same class registered twice is two services with their own arguments, which a single
            // generated plugin cannot carry both of. Counted here so the caller can say so out loud.
            $rules[$class]['services']++;

            continue;
        }

        $rules[$class] = [
            'class' => $class,
            'file' => $file,
            'core' => $coreRoot !== null && $file !== null && str_starts_with($normalise($file), $coreRoot),
            'services' => 1,
        ];
    }

    ksort($rules);

    $write(['ok' => true, 'error' => null, 'rules' => array_values($rules)]);
})($container ?? null);
