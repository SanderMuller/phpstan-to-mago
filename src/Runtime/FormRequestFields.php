<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\FileAnalysis;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
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
    /**
     * Methods a `FormRequest` can override to rewrite the validated data, which makes the `rules()` key set
     * an unreliable picture of what is available. The original treats a user override anywhere in the
     * hierarchy as opaque and the framework's own defaults as not — every `FormRequest` inherits those.
     *
     * @var list<string>
     */
    private const array OPAQUE_METHODS = ['prepareForValidation', 'validationData', 'all'];

    private const string FORM_REQUEST = 'illuminate\\foundation\\http\\formrequest';

    private const string FRAMEWORK_PREFIX = 'illuminate\\';

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

                $key = self::literal($source->getText($argument->value));
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

                $roots[$class] ??= self::validatedRoots($context, $class);
                $validated = $roots[$class];
                if ($validated === null || isset($validated[self::rootSegment($key)])) {
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

    /**
     * The root segments of every literal key the class's `rules()` declares, or null when it is opaque.
     *
     * Null is the answer for everything the original cannot statically prove, and the list of those is the
     * whole point of the check: a user-defined opaque method, no `rules()` at all, a declaring file outside
     * the analysis, more than one `return`, a `return` that is not a direct array literal, or any element
     * that is a spread, value-only, or keyed by something other than a plain string.
     *
     * @return array<string, true>|null
     */
    private static function validatedRoots(AfterAnalysisContext $context, string $class): ?array
    {
        if (self::hasUserDefinedOpaqueMethod($context, $class)) {
            return null;
        }

        $declaring = $context->codebase->getDeclaringMethod($class, 'rules');
        if (! $declaring instanceof FunctionLikeMetadata || $declaring->location->file === null) {
            return null;
        }

        $owner = $context->analysis->getFile($declaring->location->file);
        if (! $owner instanceof FileAnalysis) {
            return null;
        }

        $source = $owner->getSourceFile();
        $array = self::directReturnArray($source, $declaring->location->span);
        if (! $array instanceof Node) {
            return null;
        }

        $roots = [];
        foreach ($source->getChildren($array) as $wrapper) {
            // Probed, not assumed: an `Array` holds one `ArrayElement` per entry, and that wraps the
            // `KeyValueArrayElement`, `ValueArrayElement` or `VariadicArrayElement` that says which kind of
            // entry it is. Comparing the wrapper's kind called every array opaque and reported nothing.
            $element = $source->getChildren($wrapper)[0] ?? null;
            if ($element === null || $element->kind !== NodeKind::KeyValueArrayElement) {
                // A spread or a value-only entry makes the set unresolvable, exactly as in the original.
                return null;
            }

            $key = $source->getChildren($element)[0] ?? null;
            $literal = $key === null ? null : self::literal($source->getText($key));
            if ($literal === null) {
                return null;
            }

            $roots[self::rootSegment($literal)] = true;
        }

        return $roots;
    }

    /**
     * The array a method returns, when it returns exactly one and returns it directly.
     *
     * The original counts `return` statements with a visitor that stops at any function-like, so a `return`
     * inside a closure used as a rule value does not count, and it then requires that single return to sit
     * at the method's top level with an array literal. Here the same three conditions are decided on spans:
     * returns nested in a closure are excluded by the closure's own span, and "top level" is the return
     * whose array is the method body's own.
     */
    private static function directReturnArray(SourceFile $source, Span $method): ?Node
    {
        $closures = [];
        foreach ([NodeKind::Closure, NodeKind::ArrowFunction] as $kind) {
            foreach ($source->getNodes($kind) as $closure) {
                if ($method->contains($closure->span)) {
                    $closures[] = $closure->span;
                }
            }
        }

        $returns = [];
        foreach ($source->getNodes(NodeKind::Return) as $return) {
            if (! $method->contains($return->span)) {
                continue;
            }

            foreach ($closures as $closure) {
                if ($closure->contains($return->span)) {
                    continue 2;
                }
            }

            $returns[] = $return;
        }

        if (count($returns) !== 1) {
            return null;
        }

        foreach ($source->getChildren($returns[0]) as $child) {
            $value = self::unwrapExpression($source, $child);
            if ($value->kind === NodeKind::Array || $value->kind === NodeKind::LegacyArray) {
                return $value;
            }
        }

        return null;
    }

    /**
     * True when user code overrides a method that rewrites the validated data.
     *
     * The same test the original makes: an override declared outside `Illuminate\` is user code. Case is
     * folded because metadata lowercases class names, and an unresolvable declaring class counts as user code
     * — the safe direction, since it makes the class opaque rather than reporting a field that may be
     * validated.
     */
    private static function hasUserDefinedOpaqueMethod(AfterAnalysisContext $context, string $class): bool
    {
        foreach (self::OPAQUE_METHODS as $method) {
            $declaring = $context->codebase->getDeclaringMethod($class, $method);
            if (! $declaring instanceof FunctionLikeMetadata) {
                continue;
            }

            if (! str_starts_with(strtolower($declaring->identifier->class ?? ''), self::FRAMEWORK_PREFIX)) {
                return true;
            }
        }

        return false;
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

    private static function unwrapExpression(SourceFile $source, Node $node): Node
    {
        while ($node->kind === NodeKind::Expression) {
            $next = $source->getChildren($node)[0] ?? null;
            if ($next === null) {
                break;
            }

            $node = $next;
        }

        return $node;
    }

    /**
     * The value of a plain quoted string, or null for anything else.
     *
     * Only the unambiguous shape is accepted — no backslash, no nested quote, no interpolation — because a
     * mis-decoded key is a finding about a field that is validated under a different name. Ambiguity is
     * unresolvable, which makes the key set opaque rather than wrong.
     */
    private static function literal(string $text): ?string
    {
        $text = trim($text);
        if (strlen($text) < 2 || str_contains($text, '\\')) {
            return null;
        }

        $quote = $text[0];
        if (($quote !== "'" && $quote !== '"') || substr($text, -1) !== $quote) {
            return null;
        }

        $inner = substr($text, 1, -1);
        if (str_contains($inner, $quote)) {
            return null;
        }

        return $quote === '"' && (str_contains($inner, '$') || str_contains($inner, '{')) ? null : $inner;
    }

    private static function rootSegment(string $key): string
    {
        $root = strstr($key, '.', true);

        return $root === false ? $key : $root;
    }
}
