<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Sandermuller\PhpstanToMago\Vocabulary;

/**
 * The two questions `RequireParentConstructCallRule` asks, neither of which is a statement this vocabulary has.
 *
 * The rule's own helpers are a recursion and a `while` loop, and both refuse: `property_exists($node, 'stmts')`
 * is the recursion's base case and the loop walks `getParentClass()` upwards. Their *questions* map even
 * though their statements do not, which is the choice {@see Vocabulary::COLLABORATOR_CALLS}
 * records preferring — does this constructor call its parent's, and which ancestor declares one it ought to
 * have called.
 *
 * **Three things about mago's metadata were probed before this was written, and each would have made a port
 * that looked right.** All three are silent failures rather than errors:
 *
 * - **`parentClasses` is not in nearest-first order.** For `Child extends Parental extends Grand extends
 *   Great` it answers `grand, great, parental` -- alphabetical. PHPStan walks `getParentClass()` strictly
 *   upwards and its message names the *nearest* ancestor declaring a constructor, so reading that list in
 *   order names the wrong class. The chain is rebuilt here by following `directParentClass` one link at a
 *   time.
 * - **Names come back lowercased.** `chain\grand`, where PHPStan's message carries `Chain\Grand`.
 *   `originalName` holds the written case and is what the message uses.
 * - **`methodExists()` answers true for an inherited method.** `Chain\Parental` does not declare
 *   `__construct` and `methodExists('Chain\Parental', '__construct')` is still true, so the rule's
 *   `getDeclaringClass()->getName() === $parentClass->getName()` test has to be reproduced through
 *   `getDeclaringMethod()`'s own identifier rather than through existence.
 */
final class Constructors
{
    /**
     * Whether this constructor calls `parent::__construct()` anywhere inside it.
     *
     * The original recurses through every statement, unwrapping an `Expression` and an error-suppress along
     * the way, and answers true if it finds the call at any depth. A descendant search answers the same
     * question without the recursion: depth is what the walk was for.
     */
    public static function callsParent(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return false;
        }

        foreach ($context->source->getDescendants($node, NodeKind::StaticMethodCall) as $call) {
            $class = Calls::classPart($context, $call);
            if (! $class instanceof Part || strtolower(trim(Support::textOf($class) ?? '')) !== 'parent') {
                continue;
            }

            if (Calls::selectorIs(Calls::selector($context, $call), '__construct')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nearest ancestor declaring a constructor this class ought to have called, in its written case.
     *
     * `false` in the original, `null` here, and the rule tests it the same way either side. The four
     * conditions are the original's: the constructor must be *declared* on that ancestor rather than
     * inherited, and must not be abstract, private or deprecated.
     */
    public static function parentDeclaring(NodeAnalysisContext $context, Part|Node|null $subject): ?string
    {
        $node = Tree::node($subject);
        if (! $node instanceof Node) {
            return null;
        }

        // **The declaration this node sits in, before the codebase is asked about its name.** A name can
        // have two declarations and the metadata keeps one: `PhpParser\Internal\TokenPolyfill` is declared
        // twice in one file, once `extends \PhpToken` behind a version check and once standalone, and the
        // constructor here belongs to the standalone one. Resolving the name found the extending
        // declaration and this rule reported a missing `parent::__construct()` on a class with no parent --
        // one only-port finding on 270 files, found by the corpus differential and not by the example pair.
        // {@see Reflect::parentHasConstructor()} records the same distinction for the same reason.
        if (! Inheritance::hasExtends($context, $node)) {
            return null;
        }

        $class = Support::enclosingClassName($context, $node);
        if ($class === null) {
            return null;
        }

        foreach (self::chainUpwards($context, $class) as $ancestor) {
            if (self::declaresACallableConstructor($context, $ancestor->name)) {
                return $ancestor->originalName;
            }
        }

        return null;
    }

    /**
     * The parent classes nearest first, rebuilt one link at a time.
     *
     * `parentClasses` holds the same set in another order, which is the probed finding this exists for. The
     * seen-set is not tidiness: a codebase mago read partially can name a parent that names it back, and a
     * cycle here is a worker that never answers.
     *
     * @return list<ClassLikeMetadata>
     */
    private static function chainUpwards(NodeAnalysisContext $context, string $class): array
    {
        $chain = [];
        $seen = [strtolower($class) => true];
        $metadata = $context->codebase->getClassLike($class);

        while ($metadata instanceof ClassLikeMetadata && $metadata->directParentClass !== null) {
            $parent = $metadata->directParentClass;
            if (isset($seen[strtolower($parent)])) {
                break;
            }

            $seen[strtolower($parent)] = true;
            $metadata = $context->codebase->getClassLike($parent);
            if (! $metadata instanceof ClassLikeMetadata) {
                break;
            }

            $chain[] = $metadata;
        }

        return $chain;
    }

    /** Whether this class declares a constructor of its own that a child is expected to call. */
    private static function declaresACallableConstructor(NodeAnalysisContext $context, string $class): bool
    {
        $constructor = $context->codebase->getDeclaringMethod($class, '__construct');
        if (! $constructor instanceof FunctionLikeMetadata) {
            return false;
        }

        // Declared here rather than inherited, which `methodExists()` cannot tell apart -- probed.
        if (strtolower((string) $constructor->identifier->class) !== strtolower($class)) {
            return false;
        }

        return ! $constructor->flags->contains(MetadataFlags::ABSTRACT)
            && ! $constructor->flags->contains(MetadataFlags::DEPRECATED)
            && $constructor->visibility !== Visibility::Private;
    }
}
