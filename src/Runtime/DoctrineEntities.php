<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;

/**
 * `DoctrineEntityDocumentAnalyser::isEntityClass()`, ported rather than translated.
 *
 * The original asks two things of a class reflection, and only one of them is answerable here:
 *
 * - **Does it carry a mapping attribute.** Its `ENTITY_ATTRIBUTES` are
 *   `Doctrine\ORM\Mapping\Entity` and `Doctrine\ODM\MongoDB\Mapping\Annotations\Document`, copied from the
 *   source rather than recalled, and mago's `ClassLikeMetadata->attributes` carries a resolved name per
 *   attribute. So this half is exact.
 * - **Does its docblock hold `@Entity`, `@ORM\Entity`, `@Document` or `@ORM\Document`.** `ClassLikeMetadata`
 *   carries no docblock text — read field by field, it holds flags, hierarchy, members, attributes and
 *   template information, and nothing that would let the marker be found. So this half cannot be asked.
 *
 * ## The divergence, and which direction it was chosen in
 *
 * An entity mapped by *annotation* is invisible to this port, so a rule asking "is this an entity" answers
 * no for one and the finding is not reported. That is an under-report, which is the direction this
 * repository picks when one must be picked: it surfaces as `only-original` in a differential rather than as
 * a finding nobody can act on. A codebase still on annotations gets nothing from the ported rule, and that
 * is worth saying out loud rather than discovering from a zero.
 *
 * Attribute mapping has been the documented default since Doctrine ORM 2.9, so the population this misses is
 * the older one — but "older" is not "empty", and nothing here measures how large it is.
 */
final class DoctrineEntities
{
    /**
     * The attribute names Doctrine maps an entity or a document with.
     *
     * Copied from `DoctrineEntityDocumentAnalyser::ENTITY_ATTRIBUTES`. A table of a package's own constants
     * is normally something this transpiler reads from the rule's source rather than holds — the exception
     * here is that the whole analyser is ported, so its constants come with it, the way
     * {@see RuleLevel}'s accepted types do.
     */
    private const array ENTITY_ATTRIBUTES = [
        'Doctrine\\ORM\\Mapping\\Entity',
        'Doctrine\\ODM\\MongoDB\\Mapping\\Annotations\\Document',
    ];

    /** Whether the codebase knows this class as a Doctrine entity or document, by attribute. */
    public static function isEntityClass(NodeAnalysisContext $context, ?string $class): bool
    {
        if ($class === null || $class === '') {
            return false;
        }

        $metadata = $context->codebase->getClassLike($class);
        if (! $metadata instanceof ClassLikeMetadata) {
            return false;
        }

        foreach ($metadata->attributes as $attribute) {
            if (in_array(ltrim($attribute->name, '\\'), self::ENTITY_ATTRIBUTES, true)) {
                return true;
            }
        }

        return false;
    }
}
