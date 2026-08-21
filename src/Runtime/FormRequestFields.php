<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Reads a `FormRequest` field through an accessor the class's `rules()` never validates.
 *
 * One of the checks in `CombinedMethodCallRule` asks a question no node hook can answer: `rules()` may be
 * inherited, so deciding it means reading a *different file's* method body. `getFileName()` plus
 * `Parser::parseFile()` is how PHPStan does that; the SDK route is `getDeclaringMethod()` plus that file's
 * CST, and it is only open to an after-analysis pass — a node hook is handed one file.
 *
 * So this check is not translated statement by statement, the same way a collector-and-consumer pair is not
 * ({@see TypeCoverage}). The original resolves its key set through a `NodeTraverser` and an anonymous
 * `NodeVisitor` subclass, which no vocabulary of this kind translates and none should try to recognise: a
 * plausible-but-wrong port of an opacity rule reports fields that *are* validated. The question is
 * reimplemented instead, and the emitted plugin still takes its accessor list, namespaces and identifier
 * from the rule's own source.
 *
 * Every SDK fact below was measured by `internal/probe-after-hook-self-contained.php` at `workers = 2`, not
 * read off the SDK. Two of them decided the shape:
 *
 * - **Nothing may cross from a node hook.** `internal/probe-collect-across-workers.php` measured
 *   `afterAnalysis` firing in a *different process* than the node hooks above one worker, so a collect-then-
 *   resolve design loses every site — while passing at `workers = 1`, which is what a test sandbox runs. The
 *   pass therefore finds its own call sites.
 * - **`SourceFile::getLiteralString()` returns null here.** Decoded literals are a snapshot requirement and
 *   `FileAnalysisRequirement` has no case for them, so {@see literal()} decodes the text and calls anything
 *   ambiguous unresolvable.
 *
 * Two divergences from the original, both narrowing and neither silent:
 *
 * - **A `rules()` declared in a file the analysis did not cover is opaque.** PHPStan's parser reads any path
 *   on disk; this pass can only reach files in `ProjectAnalysis`. A vendor base class outside mago's
 *   configured paths therefore yields no finding where PHPStan may still report one.
 * One divergence, then, rather than two: the opaque-method check reads the declaring class from
 * `FunctionLikeMetadata->identifier->class`, which matches the original exactly. It folds case, because
 * metadata lowercases class names.
 */
final class FormRequestFields
{
    private const string FORM_REQUEST = 'illuminate\\foundation\\http\\formrequest';

    /**
     * Reports every accessor call whose key the enclosing `FormRequest`'s `rules()` never validates.
     *
     * The accessor list arrives as the rule's own lookup — keys are the lowercased method names — because
     * that is the expression the rule hands its check, and the emitted call passes it through unchanged.
     *
     * @param array<string, true> $accessors        accessor method names, lowercased, as keys
     * @param list<string>        $namespaces        namespace prefixes the check applies to
     * @param list<string>        $excludeNamespaces namespace prefixes it does not
     */
    public static function report(
        AfterAnalysisContext $context,
        array $accessors,
        array $namespaces,
        array $excludeNamespaces,
        string $identifier,
    ): void {
        if ($accessors === []) {
            return;
        }

        /** @var array<string, array<string, true>|null> $roots resolved once per class, as the original caches */
        $roots = [];

        foreach ($context->analysis->files as $analysis) {
            $source = $analysis->getSourceFile();
            $namespace = self::declaredNamespace($source);
            if (! self::inScope($namespace, $namespaces, $excludeNamespaces)) {
                continue;
            }

            foreach ($source->getNodes(NodeKind::MethodCall) as $node) {
                $call = CallExpression::fromNode($source, $node);
                $member = $call->getName($source);
                if ($member === null || ! isset($accessors[strtolower($member)])) {
                    continue;
                }

                if (! $call->receiver instanceof Node || trim($source->getText($call->receiver)) !== '$this') {
                    continue;
                }

                $argument = $call->arguments[0] ?? null;
                if ($argument === null) {
                    continue;
                }

                $key = FormRequestRules::literal($source->getText($argument->value));
                if ($key === null) {
                    continue;
                }

                $class = self::enclosingClass($source, $node, $namespace);
                if ($class === null) {
                    continue;
                }

                $ancestors = array_map(strtolower(...), $context->codebase->getClassAncestors($class));
                if (! in_array(self::FORM_REQUEST, $ancestors, true)) {
                    continue;
                }

                // `array_key_exists`, not `??=`: an opaque class resolves to null, and `??=` would treat that
                // as "not yet cached" and re-resolve it at every site. The original caches with the same
                // function for the same reason, and opaque is the common answer — every FormRequest on the
                // project this was measured against has a conditional `rules()`.
                if (! array_key_exists($class, $roots)) {
                    $roots[$class] = FormRequestRules::rootsFor($context, $class);
                }

                $validated = $roots[$class];
                if ($validated === null || isset($validated[FormRequestRules::rootSegment($key)])) {
                    continue;
                }

                $context->report(
                    Level::Error,
                    $identifier,
                    Issue::at(
                        sprintf(
                            "Reading '%s' via %s() but the FormRequest's rules() never validates it.",
                            $key,
                            $member,
                        ),
                        new SourceLocation($analysis->file, $node->span),
                        'here',
                    ),
                );
            }
        }
    }

    /** The namespace the file declares, or null when it declares none. */
    private static function declaredNamespace(SourceFile $source): ?string
    {
        if (preg_match('/^\s*namespace\s+([^;{\s]+)\s*[;{]/m', $source->contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], '\\');
    }

    /**
     * The original's `namespaceStartsWithAny`: an exact match, or a prefix followed by a separator.
     *
     * A file declaring no namespace is out of scope, because `Scope::getNamespace()` is null there and the
     * original's prefix test returns false for null.
     *
     * @param list<string> $namespaces
     * @param list<string> $excludeNamespaces
     */
    private static function inScope(?string $namespace, array $namespaces, array $excludeNamespaces): bool
    {
        return self::startsWithAny($namespace, $namespaces) && ! self::startsWithAny($namespace, $excludeNamespaces);
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(?string $namespace, array $prefixes): bool
    {
        if ($namespace === null) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if ($namespace === $prefix || str_starts_with($namespace, rtrim($prefix, '\\') . '\\')) {
                return true;
            }
        }

        return false;
    }

    /** The fully qualified name of the class-like a node sits in, from the CST. */
    private static function enclosingClass(SourceFile $source, Node $node, ?string $namespace): ?string
    {
        foreach ($source->getAncestors($node) as $ancestor) {
            if ($ancestor->kind !== NodeKind::Class_) {
                continue;
            }

            $name = self::firstIdentifier($source, $ancestor);
            if ($name === null) {
                return null;
            }

            return $namespace === null ? $name : $namespace . '\\' . $name;
        }

        return null;
    }

    private static function firstIdentifier(SourceFile $source, Node $node): ?string
    {
        foreach ($source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::Identifier || $child->kind === NodeKind::LocalIdentifier) {
                return trim($source->getText($child));
            }
        }

        return null;
    }
}
