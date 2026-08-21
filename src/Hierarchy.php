<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use Closure;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TraitUse;

/**
 * Finds which class-like actually declares a method.
 *
 * A rule that reads `$this->somethingError(...)` usually does not declare `somethingError()` itself. Real
 * rule packages put the logic in a trait and keep the rule as a shim, or put it in an abstract base and
 * subclass per node type. Inlining only same-class helpers therefore refuses the whole package: 15 of the
 * 20 rules in `hihaho/phpstan-rules` fail on exactly this, 12 through a trait and 3 through a parent.
 *
 * Resolution order follows PHP's own: the class body wins, then its traits, then the parent, recursively.
 * That order matters — a class overriding a trait method must inline the override.
 */
final readonly class Hierarchy
{
    /**
     * Deep enough for a rule, a base class and a trait it uses. Beyond that a rule is not a shim over a
     * helper any more, and inlining it would produce something nobody could read against the original.
     */
    private const int DEPTH_LIMIT = 6;

    /**
     * @param Closure(string): ?array{class: ClassLike, uses: array<string, string>, namespace: string|null} $lookup
     *        resolves a short class-like name to its parsed declaration, its own import map and the namespace
     *        it was declared in
     */
    public function __construct(private Closure $lookup) {}

    /**
     * @param array<string, string> $uses      the import map of the file `$class` was declared in
     * @param string|null           $namespace the namespace `$class` was declared in, so a caller that needs
     *                                         a fully qualified name has one without re-deriving it
     *
     * @return array{class: ClassLike, uses: array<string, string>, namespace: string|null}|null null when
     *         nothing in the hierarchy declares the method, which is a refusal for the caller to report
     */
    public function declaring(ClassLike $class, string $method, array $uses, ?string $namespace = null, int $depth = 0): ?array
    {
        if ($depth >= self::DEPTH_LIMIT) {
            return null;
        }

        foreach ($class->getMethods() as $candidate) {
            if ($candidate->name->toString() === $method) {
                return ['class' => $class, 'uses' => $uses, 'namespace' => $namespace];
            }
        }

        foreach ($this->traitNames($class) as $name) {
            $found = $this->through($name, $method, $depth);
            if ($found !== null) {
                return $found;
            }
        }

        if ($class instanceof Class_ && $class->extends instanceof Name) {
            return $this->through($class->extends->getLast(), $method, $depth);
        }

        return null;
    }

    /**
     * Every class-like a `self::` reference can see: the class itself, its traits, and its parents.
     *
     * `self::SOME_CONSTANT` resolves through the hierarchy in PHP, so a transpiler collecting constants from
     * the class alone misses the ones a base class declares — which is most of them, in a package that keeps
     * its shared tables on an abstract rule.
     *
     * @return list<ClassLike>
     */
    public function selfAndAncestors(ClassLike $class, int $depth = 0): array
    {
        if ($depth >= self::DEPTH_LIMIT) {
            return [];
        }

        $found = [$class];
        foreach ($this->traitNames($class) as $name) {
            $resolved = ($this->lookup)($name);
            if ($resolved !== null) {
                $found = [...$found, ...$this->selfAndAncestors($resolved['class'], $depth + 1)];
            }
        }

        if ($class instanceof Class_ && $class->extends instanceof Name) {
            $resolved = ($this->lookup)($class->extends->getLast());
            if ($resolved !== null) {
                $found = [...$found, ...$this->selfAndAncestors($resolved['class'], $depth + 1)];
            }
        }

        return $found;
    }

    /**
     * @return array{class: ClassLike, uses: array<string, string>, namespace: string|null}|null
     */
    private function through(string $shortName, string $method, int $depth): ?array
    {
        $resolved = ($this->lookup)($shortName);
        if ($resolved === null) {
            return null;
        }

        // The import map and the namespace travel with the declaration: a helper resolves the names it
        // mentions through the `use` statements of *its own* file, never the calling rule's.
        return $this->declaring(
            $resolved['class'],
            $method,
            $resolved['uses'],
            $resolved['namespace'],
            $depth + 1,
        );
    }

    /**
     * @return list<string>
     */
    private function traitNames(ClassLike $class): array
    {
        $names = [];
        foreach ($class->stmts as $statement) {
            if ($statement instanceof TraitUse) {
                foreach ($statement->traits as $trait) {
                    $names[] = $trait->getLast();
                }
            }
        }

        return $names;
    }
}
