<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Which classes end up with each trait's methods, and under which names.
 *
 * Split out of {@see DeclaredParameters} because it asks about the syntax of `use` statements rather than
 * about coverage, and because each of its three findings cost a wrong number first: the users are the
 * *classes* whose own `use` statements reach a trait, following trait-to-trait `use` and not class
 * inheritance; an anonymous class is one of them; and a renamed method still arrives, so the names a class can
 * reach a declaration under include whatever its adaptations introduce.
 */
final class TraitUsers
{
    /**
     * Which name a class reaches one declaration under, or null when it reaches it under none.
     *
     * A class that uses a trait does not always get the trait's method: its own declaration of the same name
     * wins, and an `insteadof` gives the name to a different trait. Either way the collector never sees that
     * declaration in that class's context, so it is not counted for it. An alias is the other direction — a
     * renamed method still arrives, under the new name — which is why the caller passes every name the class
     * could reach it under and this answers which one landed.
     *
     * Identity is the declaration *site*, not the name: two traits can declare the same name and only one of
     * them is the declaration being counted.
     *
     * @param list<string> $names the original name first, then whatever adaptations introduce
     */
    public static function reachedAs(AfterAnalysisContext $context, string $class, string $site, array $names): ?string
    {
        $metadata = $context->codebase->getClassLike($class);
        $documented = $metadata instanceof ClassLikeMetadata
            ? array_flip([...$metadata->pseudoMethods, ...$metadata->staticPseudoMethods])
            : [];

        foreach ($names as $name) {
            $declaring = $context->codebase->getDeclaringMethod($class, $name);
            if ($declaring instanceof FunctionLikeMetadata
                && $declaring->location->file . ':' . $declaring->location->span->start === $site
            ) {
                return $name;
            }

            // A `@method` line on the class does not take the name away from the trait. The codebase
            // resolves the name to the documented declaration, so the site comparison above says "not
            // reached" — but PHP gives the class the trait's method all the same, and PHPStan analyses the
            // trait's body in that class's context. Three of one consumer's factories document `createMany`
            // and `createManyQuietly` beside a trait that declares them, which was exactly the -6 left in
            // that directory after every other cause was closed.
            if (isset($documented[strtolower($name)])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * How many classes use each trait, keyed by the trait's lowercased name as it is written.
     *
     * Read from `TraitUse` nodes rather than from `ClassLikeMetadata->usedTraits`, because the metadata field
     * is transitive *through a parent class* as well as through another trait — and the abstract-base control
     * above shows PHPStan does not count a parent's trait again for each subclass. The syntax says which
     * declaration wrote the `use`, which is the question.
     *
     * Names are the ones written, resolved against the file's imports but not lowercased by the resolver, so
     * every key and lookup folds case here.
     *
     * @return array<string, list<array{class: string|null, aliases: list<string>}>>
     */
    public static function of(AfterAnalysisContext $context): array
    {
        /** @var array<string, list<string>> $uses */
        $uses = [];
        /** @var array<string, array{class: string|null, aliases: list<string>}> $classes */
        $classes = [];

        foreach ($context->analysis->files as $file) {
            $source = $file->getSourceFile();
            foreach ($source->getNodes(NodeKind::TraitUse) as $use) {
                $owner = Declarations::enclosingClassLike($source, $use);
                if (! $owner instanceof Node) {
                    continue;
                }

                // An anonymous class has no name to key on, so its file and position stand in — it is still a
                // class that gets the trait's methods analysed in its context.
                $key = $owner->kind === NodeKind::Trait || $owner->kind === NodeKind::Interface
                    ? 'trait:' . strtolower((string) Declarations::classLikeName($source, $owner))
                    : 'class:' . $file->file . ':' . $owner->span->start;

                if (! str_starts_with($key, 'trait:')) {
                    $classes[$key] = self::withAliases($classes[$key] ?? null, $source, $owner, $use);
                }

                foreach ($source->getChildren($use) as $part) {
                    if ($part->kind !== NodeKind::Identifier) {
                        continue;
                    }

                    $resolved = $source->getResolvedName($part)?->name;
                    if ($resolved !== null) {
                        $uses[$key][] = strtolower($resolved);
                    }
                }
            }
        }

        $users = [];
        foreach ($classes as $key => $user) {
            foreach (self::reachableTraits($uses, $key) as $trait => $paths) {
                for ($i = 0; $i < $paths; ++$i) {
                    $users[$trait][] = $user;
                }
            }
        }

        return $users;
    }

    /**
     * One using class's entry, with whatever this `use` statement renames folded into it.
     *
     * A class may `use` several traits in several statements, so the aliases accumulate rather than replace.
     *
     * @param array{class: string|null, aliases: list<string>}|null $existing
     *
     * @return array{class: string|null, aliases: list<string>}
     */
    private static function withAliases(?array $existing, SourceFile $source, Node $owner, Node $use): array
    {
        $entry = $existing ?? ['class' => Declarations::classLikeName($source, $owner), 'aliases' => []];
        foreach (self::aliases($source, $use) as $alias) {
            $entry['aliases'][] = $alias;
        }

        return $entry;
    }

    /**
     * The names a `use` statement renames a trait method to.
     *
     * `use T { m as other; }` — the tree is `TraitUseSpecification > TraitUseConcreteSpecification >
     * TraitUseAdaptation`, whose `TraitUseMethodReference` is the original name and whose trailing
     * `LocalIdentifier` is the new one. Probed rather than assumed; `insteadof` is a different adaptation kind
     * and carries no `LocalIdentifier`, so it does not reach here.
     *
     * @return list<string>
     */
    private static function aliases(SourceFile $source, Node $use): array
    {
        $aliases = [];
        foreach ($source->getDescendants($use, NodeKind::TraitUseAdaptation) as $adaptation) {
            // `TraitUseAdaptation` covers both forms, and only the alias one carries `as`. Its direct
            // `LocalIdentifier` child is the new name; the original sits one level down inside
            // `TraitUseMethodReference`, so direct children give the alias without a second test.
            $renames = false;
            $renamed = null;
            foreach ($source->getChildren($adaptation) as $part) {
                if ($part->kind === NodeKind::Keyword && trim($source->getText($part)) === 'as') {
                    $renames = true;
                }

                if ($part->kind === NodeKind::LocalIdentifier) {
                    $renamed = $source->getText($part);
                }
            }

            if ($renames && $renamed !== null) {
                $aliases[] = $renamed;
            }
        }

        return $aliases;
    }

    /**
     * Every trait one declaration reaches through its own `use` statements, following trait-to-trait `use`.
     *
     * @param array<string, list<string>> $uses
     *
     * @return array<string, int> how many distinct `use` paths reach each trait
     */
    private static function reachableTraits(array $uses, string $from): array
    {
        // Counted per *path*, not per trait. A class that uses two traits which both use a third reaches
        // that third one twice, and PHPStan analyses its body once for each — measured on a control where
        // the real rule counts 2 and a visited-set walk counted 1. Deduplicating here was the last
        // divergence in the return metric on a real consumer, and one of the parameter metric's.
        //
        // PHP forbids a circular `use` between traits, so a walk with no visited set terminates.
        $reached = [];
        $queue = $uses[$from] ?? [];
        while ($queue !== []) {
            $trait = array_pop($queue);
            $reached[$trait] = ($reached[$trait] ?? 0) + 1;
            foreach ($uses['trait:' . $trait] ?? [] as $further) {
                $queue[] = $further;
            }
        }

        return $reached;
    }
}
