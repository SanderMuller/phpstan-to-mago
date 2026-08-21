<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\FileAnalysis;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * The set of field names a `FormRequest`'s `rules()` validates, or nothing when it cannot be proven.
 *
 * The resolver half of {@see FormRequestFields}, which is the reporting half. Split because they answer
 * different questions — "what does this class validate" against "which call sites break the rule" — and
 * because together they are past the complexity limit this project holds itself to.
 *
 * Every "cannot be proven" case below is one the original also refuses to guess at, and each one matters: a
 * key set treated as complete when it is not turns every field missing from it into a finding against a field
 * that *is* validated.
 */
final class FormRequestRules
{
    /**
     * Methods a `FormRequest` can override to rewrite the validated data, which makes the `rules()` key set
     * an unreliable picture of what is available. The original treats a user override anywhere in the
     * hierarchy as opaque and the framework's own defaults as not — every `FormRequest` inherits those.
     *
     * @var list<string>
     */
    private const array OPAQUE_METHODS = ['prepareForValidation', 'validationData', 'all'];

    private const string FRAMEWORK_PREFIX = 'illuminate\\';

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
    public static function rootsFor(AfterAnalysisContext $context, string $class): ?array
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
     * The array a method returns, when it returns exactly one and returns it *at its top level*.
     *
     * Both conditions, and the second one is easy to lose. The original counts `return` statements with a
     * visitor that stops at any function-like, so a `return` inside a closure used as a rule value does not
     * count — and it then requires that single return to be a direct child of the method body, calling
     * anything nested in control flow conditional and therefore opaque.
     *
     * An earlier version here checked only the count. A single `return` inside an `if` passed it, so the key
     * set looked complete and every field not in it was reported — findings against fields that *are*
     * validated, on the commonest shape there is. The fixture that would have caught it had two returns, so it
     * passed for the wrong reason: it proved the count, never the nesting.
     */
    private static function directReturnArray(SourceFile $source, Span $method): ?Node
    {
        $returns = self::returnsOutsideClosures($source, $method);
        if (count($returns) !== 1) {
            return null;
        }

        $body = self::methodBodyBlock($source, $method);
        if (! $body instanceof Node) {
            return null;
        }

        // Direct child of the body's block, which is what "top level" means. Probed rather than assumed, and
        // the wrapper is the part that matters: `Method` holds a `MethodBody`, which holds a `Block`, whose
        // children are `Statement` nodes wrapping the real statement. Comparing against the `Statement`
        // instead of what it wraps found no top-level return anywhere, so nothing reported at all.
        $top = false;
        foreach ($source->getChildren($body) as $statement) {
            $inner = $statement->kind === NodeKind::Statement
                ? ($source->getChildren($statement)[0] ?? $statement)
                : $statement;
            if ($inner->id === $returns[0]->id) {
                $top = true;

                break;
            }
        }

        if (! $top) {
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
     * Every `return` in the method that is not inside a closure used as a value.
     *
     * @return list<Node>
     */
    private static function returnsOutsideClosures(SourceFile $source, Span $method): array
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

        return $returns;
    }

    /** The statement block of the method at this span, or null when it has none. */
    private static function methodBodyBlock(SourceFile $source, Span $method): ?Node
    {
        foreach ($source->getNodes(NodeKind::Method) as $candidate) {
            if (! $method->contains($candidate->span) && ! $candidate->span->contains($method)) {
                continue;
            }

            foreach ($source->getChildren($candidate) as $part) {
                if ($part->kind !== NodeKind::MethodBody) {
                    continue;
                }

                return $source->getChildren($part)[0] ?? null;
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

    /**
     * The value of a plain quoted string, or null for anything else.
     *
     * Only the unambiguous shape is accepted — no backslash, no nested quote, no interpolation — because a
     * mis-decoded key is a finding about a field that is validated under a different name. Ambiguity is
     * unresolvable, which makes the key set opaque rather than wrong.
     *
     * Decoded here rather than with `SourceFile::getLiteralString()`, which returns null in an
     * after-analysis pass: decoded literals are a snapshot requirement and `FileAnalysisRequirement` has no
     * case for them.
     */
    public static function literal(string $text): ?string
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

    /** The part of a dotted key before the first dot, which is what the original compares. */
    public static function rootSegment(string $key): string
    {
        $root = strstr($key, '.', true);

        return $root === false ? $key : $root;
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
}
