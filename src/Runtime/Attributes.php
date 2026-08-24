<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\ResolvedName;

/**
 * The attributes a declaration carries, by fully qualified name.
 *
 * php-parser models them in two levels — groups, each holding attributes — and the rules that read them
 * walk both to reach the names. Metadata carries them flattened and resolved, and *case-preserving*, unlike
 * every other name it holds, so a comparison against a written attribute name matches without folding case.
 */
final class Attributes
{
    /**
     * The attributes on the declaration a hook fired for, by fully qualified name.
     *
     * `$node->attrGroups` in php-parser is two levels — groups, each holding attributes — and the rules that
     * read it only ever walk both to reach the names. Metadata carries them already flattened, and *resolved*:
     * measured, an imported `#[Entity]` comes back as `Doctrine\ORM\Mapping\Entity`, which is what
     * `$attr->name->toString()` gives a rule after PHPStan's own name resolution. Case survives too, unlike
     * every other name metadata holds — so a comparison against a written attribute name matches without
     * folding case, and folding it would be wider than the rule.
     *
     * Both hooks that ask: a class-like declaration reads its own attributes, and a method declaration reads
     * the ones on the method rather than on the class around it.
     *
     * @return list<string>
     */
    public static function attributeNames(NodeAnalysisContext $context, Part|Node|null $subject): array
    {
        $className = Declares::enclosingClassName($context, $subject);
        if ($className === null) {
            return [];
        }

        $metadata = Members::isMethodDeclaration($subject)
            ? $context->codebase->getMethod($className, (string) Members::declarationName($context, $subject))
            : $context->codebase->getClassLike($className);

        return $metadata === null ? [] : array_values(array_map(
            static fn (AttributeMetadata $attribute): string => $attribute->name,
            $metadata->attributes,
        ));
    }

    /**
     * The attribute groups a declaration carries, one per `#[..]` written on it.
     *
     * @return list<Part>
     */
    public static function attributeGroups(?Part $declaration): array
    {
        if (! $declaration instanceof Part) {
            return [];
        }

        $out = [];
        foreach ($declaration->children() as $child) {
            if ($child->kind === NodeKind::AttributeList) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * The attributes inside one group: `#[A, B]` is one group holding two.
     *
     * @return list<Part>
     */
    public static function attributesOf(?Part $group): array
    {
        if (! $group instanceof Part) {
            return [];
        }

        $out = [];
        foreach ($group->children() as $child) {
            if ($child->kind === NodeKind::Attribute) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** The fully-qualified name an attribute names, which is what `$attr->name->toString()` gives a rule. */
    public static function attributeName(NodeAnalysisContext $context, ?Part $attribute): ?string
    {
        if (! $attribute instanceof Part) {
            return null;
        }

        $resolved = $context->source->getResolvedName($attribute->node);

        return $resolved instanceof ResolvedName && $resolved->name !== '' ? $resolved->name : trim($attribute->text);
    }
}
