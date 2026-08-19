<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * A rule package's configuration value object, reduced to the parameter each getter reads.
 *
 * Both TomasVotruba packages hand their rules a `Configuration` built from one whole parameter array, and the
 * rules ask it questions: `$this->configuration->getRequiredParamTypeLevel()`. Every getter in both packages
 * is a single array read, or a read with an alias fallback:
 *
 * ```php
 * return $this->parameters['class'];
 * return $this->parameters['param'] ?? $this->parameters['param_type'];
 * ```
 *
 * So a getter is inlined to the parameter key it reads, and the generated plugin takes one typed constructor
 * parameter per key actually used. Porting the class instead would give that constructor a single untyped
 * `array $parameters` and throw away the schema the neon supplies.
 *
 * A getter that is neither of those two shapes returns null here, and the caller refuses. That matters more
 * than covering it: a getter doing arithmetic or reading the environment would be carried as a plain
 * parameter read and quietly answer something else.
 */
final readonly class ConfigurationObject
{
    /**
     * @param array<string, list<string>> $getters the parameter keys each getter reads, in fallback order
     */
    private function __construct(
        public string $root,
        private array $getters,
    ) {}

    /**
     * Reads the value object's own source, given the file it lives in and the parameter root it is built from.
     */
    public static function fromFile(string $file, string $root): ?self
    {
        if (! is_file($file)) {
            return null;
        }

        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($file));
        if ($ast === null) {
            return null;
        }

        $getters = [];
        foreach ((new NodeFinder())->findInstanceOf($ast, ClassMethod::class) as $method) {
            if ($method->stmts === null || count($method->stmts) !== 1) {
                continue;
            }

            $statement = $method->stmts[0];
            if (! $statement instanceof Return_ || ! $statement->expr instanceof Expr) {
                continue;
            }

            $keys = self::keysRead($statement->expr);
            if ($keys !== []) {
                $getters[(string) $method->name] = $keys;
            }
        }

        return $getters === [] ? null : new self($root, $getters);
    }

    /**
     * The dotted parameter paths a getter reads, in the order it falls back through them.
     *
     * More than one means an alias: `['type_coverage.param', 'type_coverage.param_type']` for
     * `$this->parameters['param'] ?? $this->parameters['param_type']`. The caller takes the first that the
     * package actually declares.
     *
     * @return list<string>
     */
    public function pathsFor(string $getter): array
    {
        return array_map(
            fn (string $key): string => $this->root . '.' . $key,
            $this->getters[$getter] ?? [],
        );
    }

    /**
     * The keys a `return` expression reads out of `$this->parameters`, or none when it does something else.
     *
     * @return list<string>
     */
    private static function keysRead(Expr $expr): array
    {
        if ($expr instanceof Coalesce) {
            $left = self::keysRead($expr->left);
            if ($left === []) {
                return [];
            }

            // `?? []` and `?? 0` fall back to a literal rather than to another parameter, and the package's
            // own default already covers that, so the left key alone is the answer.
            $right = self::keysRead($expr->right);

            return $right === [] && ! $expr->right instanceof ArrayDimFetch
                ? $left
                : [...$left, ...$right];
        }

        if (! $expr instanceof ArrayDimFetch
            || ! $expr->dim instanceof String_
            || ! $expr->var instanceof PropertyFetch
            || ! $expr->var->var instanceof Variable
            || $expr->var->var->name !== 'this'
        ) {
            return [];
        }

        return [$expr->dim->value];
    }
}
