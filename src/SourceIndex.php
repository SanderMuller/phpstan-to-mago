<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds a class, interface or trait by short name in the source around a rule.
 *
 * The transpiler reads one rule file, but a rule reaches beyond it constantly: a constant on another class,
 * a static helper, and above all the trait or base class its own logic lives in. This is the only place
 * that touches the filesystem to answer those.
 *
 * Results are cached per process because a rule package resolves the same handful of traits over and over.
 */
final class SourceIndex
{
    /** @var array<string, array<string, list<string>>> root -> short name -> paths */
    private static array $files = [];

    /** @var array<string, true> roots already walked */
    private static array $indexed = [];

    /** @var array<string, array{class: ClassLike, uses: array<string, string>, namespace: string|null}> */
    private static array $parsed = [];

    /** Cleared between rules only in tests; the roots stay indexed, since the filesystem has not changed. */
    public static function forget(): void
    {
        self::$parsed = [];
    }

    /**
     * @return array{class: ClassLike, uses: array<string, string>, namespace: string|null}|null
     */
    public function find(string $shortName, string $ruleFile): ?array
    {
        // Keyed by the roots too: the same short name means different classes in different packages, and a
        // flat key handed the second package whatever the first one resolved.
        $key = $shortName . '|' . implode('|', $this->roots($ruleFile));
        if (isset(self::$parsed[$key])) {
            return self::$parsed[$key];
        }

        foreach ($this->paths($shortName, $ruleFile) as $path) {
            $ast = self::parse((string) file_get_contents($path));
            if ($ast === null) {
                continue;
            }

            $class = self::declaredAs($ast, $shortName);
            if (! $class instanceof ClassLike) {
                continue;
            }

            self::$parsed[$key] = [
                'class' => $class,
                'uses' => Uses::collect($ast),
                'namespace' => self::namespaceOf($ast, $shortName),
            ];

            return self::$parsed[$key];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function paths(string $shortName, string $ruleFile): array
    {
        foreach ($this->roots($ruleFile) as $root) {
            if (isset(self::$indexed[$root])) {
                continue;
            }

            self::$indexed[$root] = true;
            /** @var SplFileInfo $entry */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    self::$files[$root][$entry->getBasename('.php')][] = $entry->getPathname();
                }
            }
        }

        $paths = [];
        foreach ($this->roots($ruleFile) as $root) {
            foreach (self::$files[$root][$shortName] ?? [] as $path) {
                $paths[] = $path;
            }
        }

        // Sorted, because a directory iterator yields the filesystem's order and that differs between machines.
        // Two files can declare one short name — `symplify/phpstan-rules` and a copy vendored inside
        // `rector/rector` both declare a `SymfonyClass` — so which one wins has to be the same everywhere for
        // the census to mean anything. Sorting does not make the choice *right*; the caller's imports would,
        // and that is a change with its own risk, since the accidental order is what several translations were
        // built on.
        sort($paths);

        return $paths;
    }

    /**
     * The class, interface or trait declared under `$shortName`.
     *
     * Distinct from asking "what rule does this file define": that must not match a trait, and this must,
     * because a trait is where a rule package usually keeps the helper.
     *
     * @param Stmt[] $ast
     */
    public static function declaredAs(array $ast, string $shortName): ?ClassLike
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $found = self::declaredAs($stmt->stmts, $shortName);
                if ($found instanceof ClassLike) {
                    return $found;
                }

                continue;
            }

            if ($stmt instanceof ClassLike && $stmt->name?->toString() === $shortName) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * Parses PHP source into statements, with the comment placeholders removed.
     *
     * php-parser turns a comment that ends a block into a `Nop` statement, so
     * `if ($node->if === null) { return []; // elvis ?: }` has a body of *two* statements. Every shape test
     * here counts statements, so `BooleanInTernaryOperatorRule` was refused as an `if` that is not a
     * single-statement guard — which is what its body is, and which sent a reader looking at the wrong thing.
     *
     * Removed once, at the parse, rather than skipped at each of the places that count: a comment carries
     * nothing any of them read, and the ones that would have to skip it are not enumerable in advance. A
     * docblock is unaffected — php-parser attaches those to the node that follows, not to a `Nop`.
     *
     * @return list<Stmt>|null
     */
    public static function parse(string $code): ?array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        if ($ast === null) {
            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class extends NodeVisitorAbstract {
            public function leaveNode(Node $node): ?int
            {
                return $node instanceof Nop ? NodeVisitor::REMOVE_NODE : null;
            }
        });

        // Narrowed by asking rather than by asserting: `traverse()` is typed for any node because a visitor
        // may replace one, and every top-level node a parse yields is a statement.
        $statements = [];
        foreach ($traverser->traverse($ast) as $node) {
            if ($node instanceof Stmt) {
                $statements[] = $node;
            }
        }

        return $statements;
    }

    /**
     * The namespace a short name is declared in, or null for a file that declares none.
     *
     * Read rather than derived from the path: a package's directory layout is not its namespace, and a caller
     * that needs a fully qualified name needs the declared one. {@see declaredAs()} descends into
     * `Namespace_` without recording which one it went through, so the question is asked separately instead
     * of changing what that returns for every caller.
     *
     * @param Stmt[] $ast
     */
    public static function namespaceOf(array $ast, string $shortName): ?string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_ && self::declaredAs($stmt->stmts, $shortName) instanceof ClassLike) {
                return $stmt->name?->toString();
            }
        }

        return null;
    }

    /**
     * Where to look: the rule's own package, and nothing else.
     *
     * A `vendor` ancestor used to be searched too, on the grounds that a helper may reference a third-party
     * class. It can, and inlining one turned out to be the wrong answer: the *reason* a rule was refused then
     * depended on a third party's source, so `Strings::match()` gaining a parameter changed the census on one
     * machine and not another. CI caught it as an unstable census rather than as anything about a rule.
     *
     * Dropping it cost no emissions — 34 before and after — and the refusals it changed became shallower and
     * stable. A rule package's own logic is what this translates; somebody else's utility is a vocabulary
     * question, and refusing it by name says so.
     *
     * @return list<string>
     */
    public function roots(string $ruleFile): array
    {
        // Absolute first: walking up from a relative path stops at "." and finds neither root, so a rule
        // given as `tests/Fixtures/Rules/X.php` resolved no cross-file name at all.
        $absolute = realpath($ruleFile);
        if ($absolute === false) {
            return [];
        }

        $roots = [];

        $package = dirname($absolute);
        while ($package !== '/' && ! is_file($package . '/composer.json')) {
            $package = dirname($package);
        }

        if ($package !== '/') {
            $roots[] = $package;
        }

        return $roots;
    }
}
