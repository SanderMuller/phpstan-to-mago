<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;

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
     * The receivers `RequireQueryBuilderOnRepositoryRule` treats as safe, from `DoctrineClass`.
     *
     * @var list<string>
     */
    private const array QUERY_BUILDER_RECEIVERS = [
        'Doctrine\\ORM\\EntityRepository',
        'Doctrine\\ODM\\MongoDB\\Repository\\DocumentRepository',
        'Doctrine\\DBAL\\Connection',
    ];

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
    /**
     * Whether a `->createQueryBuilder()` receiver is one the rule considers safe.
     *
     * `RequireQueryBuilderOnRepositoryRule::isValidRepositoryObjectType()`, ported whole because it recurses
     * over a union and the vocabulary has no statement for that.
     *
     * **A union is always valid, and that is the original\'s behaviour rather than a reading of it.** The
     * helper walks a union looking for one valid member and returns true if it finds one  and when it finds
     * none it falls through to `if (! $type instanceof ObjectType) { return true; }`, which a `UnionType` also
     * satisfies. So the union branch changes nothing: every union answers true either way. Reducing it to
     * any member is valid would be narrower than the rule and would report on a union of two unrelated
     * objects, which the original lets through.
     *
     * What is left is exact: **false only for a single object type that is none of the three Doctrine
     * classes.** Anything that is not an object  a scalar, an array, `mixed`  is valid, because the original
     * asks `instanceof ObjectType` and answers true for everything else.
     *
     * The three names are `DoctrineClass::ENTITY_REPOSITORY`, `::DOCUMENT_REPOSITORY` and `::CONNECTION`,
     * copied rather than restated, the same way {@see RectorAutoloadedTypes} copies its prefix pattern.
     */
    public static function isValidQueryBuilderReceiver(NodeAnalysisContext $context, ?Type $type): bool
    {
        if (! $type instanceof Type || Types::typeIsUnion($type) || ! Types::typeIsObject($type)) {
            return true;
        }

        foreach (self::QUERY_BUILDER_RECEIVERS as $name) {
            if (Types::typeIsInstanceOf($context, $type, $name)) {
                return true;
            }
        }

        return false;
    }

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
