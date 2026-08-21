<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Error;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A Laravel facade used through its alias outside a Blade file.
 *
 * The original resolves a bare single-segment class name with `new ReflectionClass($name)`, and its own
 * comment explains why: aliases are registered lazily by `AliasLoader`, an SPL autoloader, so PHPStan's
 * `ReflectionProvider` never sees them. Under PHPStan that works only because larastan's bootstrap boots the
 * application. A plugin worker autoloads the project without booting it, so the transpiler refuses that
 * route — `internal/probe-facade-alias.php` measured the alias resolving to nothing there.
 *
 * The *question* is answerable without a boot, in two halves, both probed rather than assumed
 * (`internal/probe-facade-alias-map-in-worker.php`):
 *
 * - **The map.** `Facade::defaultAliases()` is a `public static` returning literals, and it runs in a worker:
 *   46 entries with exact class names, before `AliasLoader` has done anything. So the map is the consumer's
 *   own Laravel version's, with no table here to drift out of date.
 * - **Facade-ness.** `is_subclass_of()` on the *mapped* value, because that value is a framework class rather
 *   than analysed code. Deciding it with `getClassAncestors()` instead would need the framework inside mago's
 *   analysis paths and would answer "not a facade" wherever it is not — silently, and in the direction that
 *   looks like a clean project.
 *
 * A bare name that *is* a real class takes the other branch and is answered from metadata, because that is
 * analysed code. Order matters: a real class shadowing an alias name is judged as the class, which is what
 * the original's reflection does.
 *
 * Two limitations, both measured in `internal/measurement-static-facade-map.md` rather than guessed:
 *
 * - **Aliases a consumer merges in its own config are missed.** That project's `config/app.php` adds 9 to
 *   Laravel's 46. Evaluating a config file needs the application, so those names go unresolved and the check
 *   reports nothing for them — narrower than the original, and named here rather than discovered.
 * - **Aliases registered at runtime through `AliasLoader::getInstance()` are missed.** The one on that
 *   project is guarded by an argv check, so PHPStan cannot see it either and a map omitting it *agrees* with
 *   the reference implementation.
 */
final class FacadeAliases
{
    private const string FACADE = 'Illuminate\\Support\\Facades\\Facade';

    /** Names that are never an alias: `self`, `parent` and `static` resolve against the enclosing class. */
    private const array SPECIAL = ['self', 'parent', 'static'];

    /**
     * Reports every static call on a facade alias outside a Blade file.
     *
     * Self-contained in the after pass, and not because the check needs the whole project: nothing may cross
     * from a node hook, since `afterAnalysis` runs in a different process above one worker
     * (`internal/probe-collect-across-workers.php`).
     */
    public static function report(AfterAnalysisContext $context, string $identifier): void
    {
        $aliases = self::defaultAliases();
        if ($aliases === []) {
            // No Laravel in the worker's autoload path means no aliases exist to misuse.
            return;
        }

        foreach ($context->analysis->files as $analysis) {
            if (str_ends_with($analysis->file, '.blade.php')) {
                continue;
            }

            $source = $analysis->getSourceFile();
            foreach ($source->getNodes(NodeKind::StaticMethodCall) as $node) {
                $written = self::aliasCandidate($source, $node);
                if ($written === null) {
                    continue;
                }

                $target = self::facadeBehind($context, $written, $aliases);
                if ($target === null) {
                    continue;
                }

                $context->report(
                    Level::Error,
                    $identifier,
                    Issue::at(
                        sprintf(
                            'Disallowed usage of `%s` facade alias, use `%s`. A facade alias can only be used in Blade.',
                            $written,
                            $target,
                        ),
                        new SourceLocation($analysis->file, $node->span),
                        'here',
                    ),
                );
            }
        }
    }

    /**
     * The facade class a bare name stands for, or null when the name is not one.
     *
     * A real class is judged as itself and an unresolvable name as nothing, so the only names that report are
     * the ones the autoloader would have created.
     *
     * @param array<string, string> $aliases
     */
    private static function facadeBehind(AfterAnalysisContext $context, string $written, array $aliases): ?string
    {
        // A real class first, from metadata, because a class shadowing an alias name is that class.
        if ($context->codebase->classLikeExists($written)) {
            $ancestors = array_map(strtolower(...), $context->codebase->getClassAncestors($written));

            return in_array(strtolower(self::FACADE), $ancestors, true) ? $written : null;
        }

        $target = $aliases[$written] ?? null;

        return $target !== null && is_subclass_of($target, self::FACADE) ? $target : null;
    }

    /**
     * The one-segment class name a static call resolves to, when it could be an alias.
     *
     * The **resolved** name, not the written one, and that distinction decides which code the rule looks at.
     * Measured with php-parser's own `NameResolver`, which is what PHPStan hands the rule:
     *
     *     Bare::get()                                 -> App\Reporting\Bare            3 segments, skipped
     *     use Illuminate\Support\Facades\Cache; Cache:: -> Illuminate\…\Cache            4 segments, skipped
     *     use Cache; Cache::get()                     -> Cache                         1 segment,  candidate
     *     \Cache::get()                                -> Cache                         1 segment,  candidate
     *     self:: / static::                           -> special,                       skipped
     *
     * So an *unimported* bare name in a namespaced file is not an alias use at all — PHP would resolve it
     * inside the current namespace, and the rule skips it. Reading the written text instead would report it,
     * and would skip the leading-backslash form that is the commonest real alias use. Both directions wrong,
     * from the same mistake.
     */
    private static function aliasCandidate(SourceFile $source, Node $node): ?string
    {
        $call = CallExpression::fromNode($source, $node);
        $callee = $call->callee;
        $resolved = $source->getResolvedName($callee)->name ?? trim($source->getText($callee));
        $resolved = ltrim($resolved, '\\');
        if ($resolved === '' || str_contains($resolved, '\\') || str_contains($resolved, '$')) {
            return null;
        }

        return in_array(strtolower($resolved), self::SPECIAL, true) ? null : $resolved;
    }

    /**
     * Laravel's own alias map, or an empty map outside Laravel.
     *
     * Called rather than read from a table: the method is pure and boot-free, so this is the consumer's own
     * version of the map instead of a copy of one version of it. Guarded on every step, because a
     * framework-agnostic package must be inert where the framework is absent.
     *
     * @return array<string, string>
     */
    private static function defaultAliases(): array
    {
        if (! class_exists(self::FACADE)) {
            return [];
        }

        try {
            $map = (self::FACADE)::defaultAliases();
        } catch (Error) {
            // A Laravel old or new enough not to have the method. Reported as no aliases rather than as a
            // crash in an analyser worker, where a fatal surfaces only as "the worker closed stdout".
            return [];
        }

        // Iterated rather than unwrapped with `all()`: the method returns a `Collection` on current Laravel
        // and returned a plain array on older ones, and both are iterable as alias => class. Naming the
        // Collection type here would also put a `laravel/framework` import into shipped code, and a
        // framework-agnostic package should not carry one.
        if (! is_iterable($map)) {
            return [];
        }

        $aliases = [];
        foreach ($map as $alias => $target) {
            if (is_string($alias) && is_string($target)) {
                $aliases[$alias] = $target;
            }
        }

        return $aliases;
    }
}
