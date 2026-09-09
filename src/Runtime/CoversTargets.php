<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;

/**
 * What `CoversHelper::processCovers()` decides about every covers tag on one declaration.
 *
 * The same shape as {@see PhpUnitAnnotations}: the collaborator decides *and* builds the findings, so there is
 * no message for the transpiler to take and no question to turn into a guard. The helper is reproduced here
 * and the rule's own guards — which classes count, whose docblock is read — still come from the rule's source.
 *
 * **Reproduced literally, for the reason `PhpUnitAnnotations` gives.** The four messages, the four
 * identifiers, the `::` split and the fully-qualified-name tip are copied rather than rewritten, so an
 * upstream change arrives as a diff here rather than as findings that quietly stop matching.
 *
 * Two behaviours of the original that are easy to lose and are pinned by the example pair:
 *
 * - **The identifier varies per finding**, and one of the four is assembled: the original writes
 *   `sprintf('phpunit.covers%s', $isMethod ? 'Method' : '')`. Both arms are literals, so the transpiler reads
 *   the whole set out of the collaborator's source and this class chooses per finding — the identifier is
 *   never held in a table. {@see IDENTIFIERS} is what the transpiler asserts its reading against, so a
 *   renamed identifier upstream fails rather than emitting a plugin reporting under a name the package
 *   dropped.
 * - **A finding lands on the declaration, not on the annotation.** The original builds without `->line()`,
 *   which anchors on the node the rule fired for, so two bad tags in one docblock are two findings on the
 *   same line. Measured on the example pair, whose tag sits two lines above the class it reports on.
 */
final class CoversTargets
{
    /**
     * Every identifier the reproduction can report under, which the transpiler checks its own reading against.
     *
     * @var list<string>
     */
    public const array IDENTIFIERS = [
        'phpunit.covers',
        'phpunit.coversInterface',
        'phpunit.coversMethod',
    ];

    /** Reports for every covers tag on this declaration, the way `processCovers()` would. */
    public static function report(NodeAnalysisContext $context, Part|Node|null $declaration): void
    {
        $node = Tree::node($declaration);
        if (! $node instanceof Node) {
            return;
        }

        foreach (DocblockTags::values($context, $declaration, 'covers') as $covers) {
            foreach (self::findings($context, $covers) as [$identifier, $message]) {
                $context->report(
                    Level::Error,
                    $identifier,
                    Issue::new(Support::viaTraitUsers($context, $node, $message), $node->span, 'here'),
                );
            }
        }
    }

    /**
     * The findings one covers value produces, as `[identifier, message]` pairs in the original's order.
     *
     * Split out so the decision can be read and tested without a mago context around it, the way
     * `PhpUnitAnnotations::violations()` is.
     *
     * @return list<array{string, string}>
     */
    private static function findings(NodeAnalysisContext $context, string $covers): array
    {
        if ($covers === '') {
            return [['phpunit.covers', '@covers value does not specify anything.']];
        }

        $isMethod = str_contains($covers, '::');
        // The name is asked of the codebase through `Reflect`, which normalises the leading separator that
        // `@covers \Foo` is documented to carry -- see `Reflect::codebaseName()` for the measurement.
        $fullName = $covers;
        $method = null;
        $className = $covers;
        if ($isMethod) {
            [$className, $method] = explode('::', $covers, 2);
        }

        // The original's `$className === ''` branch prepends a `@coversDefaultClass`, and only for a class
        // method. The class-level rule passes `null` for it, so a bare `::method()` there has no default to
        // take and falls through to the invalid-target branch — which is the original's behaviour too, since
        // `$coversDefaultClass !== null` fails.
        if (Reflect::classExists($context, $className)) {
            return self::aboutAKnownClass($context, $className, $fullName, $method);
        }

        if ($method !== null && $method !== '' && Reflect::functionExists($context, $method)) {
            return [];
        }

        if ($method === null && Reflect::functionExists($context, $className)) {
            return [];
        }

        $message = sprintf(
            '@covers value %s references an invalid %s.',
            $fullName,
            $isMethod ? 'method' : 'class or function',
        );

        // The tip is part of the finding rather than decoration: the original adds it only for an unqualified
        // name, and a message compared against PHPStan's includes whatever the tip renders as.
        return [[
            'phpunit.covers' . ($isMethod ? 'Method' : ''),
            str_contains($className, '\\')
                ? $message
                : $message . "\n💡 The @covers annotation requires a fully qualified name.",
        ]];
    }

    /**
     * The two findings a resolvable class can produce, in the original's order.
     *
     * Both, not the first: the original appends to `$errors` without returning between them, so a covered
     * interface naming a missing method reports twice.
     *
     * @return list<array{string, string}>
     */
    private static function aboutAKnownClass(
        NodeAnalysisContext $context,
        string $className,
        string $fullName,
        ?string $method,
    ): array {
        $findings = [];
        $metadata = Reflect::classLike($context, $className);
        if ($metadata instanceof ClassLikeMetadata && $metadata->kind === ClassLikeKind::Interface) {
            $findings[] = ['phpunit.coversInterface', sprintf('@covers value %s references an interface.', $fullName)];
        }

        if ($method !== null && $method !== '' && ! Reflect::classHasMethod($context, $className, $method)) {
            $findings[] = ['phpunit.coversMethod', sprintf('@covers value %s references an invalid method.', $fullName)];
        }

        return $findings;
    }
}
