<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * What the codebase knows about a class, a method or a parameter.
 *
 * Metadata questions only: nothing here reads the CST. The distinction the group exists to keep is the one a
 * defect turned on — a class *declaring* a method and a class *having* one are different questions, and the
 * port answered the first where PHPStan asks the second.
 */
final class Reflect
{
    /**
     * Whether the codebase knows a class-like of this name.
     *
     * Nullable and empty-guarded because the name can come from {@see self::resolvedName()} rather than from a
     * literal, and `classLikeExists('')` aborts the whole analysis with "Codebase metadata names cannot be
     * empty" — a worker crash, not a false answer.
     */
    public static function classExists(NodeAnalysisContext $context, ?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        return $context->codebase->classLikeExists($name);
    }

    /**
     * Whether a class named by a *value* is abstract, which is `ReflectionProvider::getClass(..)->isAbstract()`.
     *
     * Kept apart from {@see declarationIsAbstract}, which reads the modifier on the declaration a hook fired
     * for. Here the name arrives as a string the plugin only has while it runs — the class a `createMock()`
     * argument names — so the question goes to the codebase instead.
     *
     * An unknown class answers false, the same way the rules that ask this guard with `hasClass()` first and
     * skip when it says no.
     */
    public static function namedClassIsAbstract(NodeAnalysisContext $context, ?string $name): bool
    {
        $metadata = $name === null || $name === '' ? null : $context->codebase->getClassLike($name);

        return $metadata instanceof ClassLikeMetadata && $metadata->flags->contains(MetadataFlags::ABSTRACT);
    }

    /**
     * The class a named class extends, or null when it extends nothing the codebase knows.
     *
     * `ClassReflection::getParentClass()` in PHPStan, which answers the *direct* parent — so it reads
     * `directParentClass` rather than the first entry of `parentClasses`, which is the whole chain.
     *
     * Lowercased, like every name metadata hands back. That costs nothing here because the questions asked of
     * the answer — abstract, builtin, which file — are asked of the codebase again rather than compared as
     * text.
     */
    public static function parentClassName(NodeAnalysisContext $context, ?string $name): ?string
    {
        $metadata = $name === null || $name === '' ? null : $context->codebase->getClassLike($name);

        return $metadata instanceof ClassLikeMetadata ? $metadata->directParentClass : null;
    }

    /**
     * Whether a class named by a value is one PHP itself ships, which is `ClassReflection::isBuiltin()`.
     *
     * The flag, not the file. A class mago resolves out of a vendor directory is `BUILTIN false` and
     * `USER_DEFINED false`, while `ArrayObject` is `BUILTIN true` — measured, because the rule that asks this
     * asks about vendor separately on the next line and answering either from the other would merge two
     * guards the original keeps apart.
     */
    public static function namedClassIsBuiltin(NodeAnalysisContext $context, ?string $name): bool
    {
        $metadata = $name === null || $name === '' ? null : $context->codebase->getClassLike($name);

        return $metadata instanceof ClassLikeMetadata && $metadata->flags->contains(MetadataFlags::BUILTIN);
    }

    /**
     * The file a named class is declared in, which is `ClassReflection::getFileName()`.
     *
     * The path as mago records it, and that is **relative to the analysed root** — probed with absolute
     * `paths` as well as relative ones, and relative both times: `src/Fixture.php`, `vendor/acme/lib/X.php`,
     * and `mago_prelude_extensions/spl.php` for a builtin. PHPStan hands back an absolute path, so a rule
     * testing for a `vendor` segment agrees with it, while one comparing a whole path would not.
     *
     * The one shape where the two disagree is a project whose own directory has `vendor` in its name: PHPStan
     * sees it in the absolute path and this does not. That is the narrow direction, and no corpus here has it.
     */
    public static function namedClassFile(NodeAnalysisContext $context, ?string $name): ?string
    {
        $metadata = $name === null || $name === '' ? null : $context->codebase->getClassLike($name);

        return $metadata instanceof ClassLikeMetadata ? $metadata->location->file : null;
    }

    /** Whether a class named by a value is an interface. {@see namedClassIsAbstract} says why this is separate. */
    public static function namedClassIsInterface(NodeAnalysisContext $context, ?string $name): bool
    {
        $metadata = $name === null || $name === '' ? null : $context->codebase->getClassLike($name);

        return $metadata instanceof ClassLikeMetadata && $metadata->kind === ClassLikeKind::Interface;
    }

    /**
     * The class that actually declares a method, or null when neither the class nor the method is known.
     *
     * The distinction a rule cares about: a first-party class inheriting a vendor method should be judged on
     * where the method *comes from*, not on the receiver. `getDeclaringMethod()` answers exactly that.
     */
    public static function declaringClassOfMethod(NodeAnalysisContext $context, ?string $class, ?string $method): ?string
    {
        if ($class === null || $method === null) {
            return null;
        }

        $declared = $context->codebase->getDeclaringMethod($class, $method);

        return $declared instanceof FunctionLikeMetadata ? self::declaringClassName($context, $class, $method) : null;
    }

    /**
     * The name of a parameter by position, or null when the method or the position is not known.
     *
     * What `ParametersAcceptorSelector::selectFromArgs(..)->getParameters()` is reached for in the original:
     * the *name* of the parameter an argument lands in, which is what a message about a positional argument
     * has to quote.
     */
    public static function parameterName(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): ?string
    {
        $parameter = self::parameterAt($context, $class, $method, $index);
        if (! $parameter instanceof ParameterMetadata) {
            return null;
        }

        // `ParameterMetadata->name` keeps the sigil — `$urgent` — where PHPStan's `getName()` drops it.
        // Measured against the real rule: the port landed on the right line with `($urgent: ...)` in a message
        // that has to read `(urgent: ...)`, because it is telling the reader what to type.
        return ltrim($parameter->name, '$');
    }

    /**
     * Whether the parameter at a position is variadic.
     *
     * A variadic parameter has no single argument position, so every rule in the corpus that names a
     * parameter skips one. Read from the metadata flags rather than inferred.
     */
    public static function parameterIsVariadic(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): bool
    {
        return self::parameterAt($context, $class, $method, $index)?->flags->contains(MetadataFlags::VARIADIC) === true;
    }

    /**
     * Whether a method declares a parameter at a position, which is `$parameters[$i] ?? null` being non-null.
     *
     * A call may pass more arguments than the method declares — into a variadic, or wrongly — so a rule that
     * names the parameter an argument lands in has to ask this first.
     */
    public static function hasParameterAt(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): bool
    {
        return self::parameterAt($context, $class, $method, $index) instanceof ParameterMetadata;
    }

    private static function parameterAt(NodeAnalysisContext $context, ?string $class, ?string $method, int $index): ?ParameterMetadata
    {
        if ($class === null || $method === null || $index < 0) {
            return null;
        }

        $declared = $context->codebase->getDeclaringMethod($class, $method);
        if (! $declared instanceof FunctionLikeMetadata) {
            return null;
        }

        $parameter = $declared->parameters[$index] ?? null;

        return $parameter instanceof ParameterMetadata ? $parameter : null;
    }

    /**
     * Whether a named class declares or inherits a method, which is `ClassReflection::hasMethod()`.
     *
     * `getDeclaringMethod()` answers for the whole hierarchy, which is what PHPStan's question means — a class
     * that inherits a method has it. Null-tolerant because the class name comes from
     * {@see self::resolvedName()}, which answers null for `parent` and for a variable class name.
     */
    public static function methodExists(NodeAnalysisContext $context, ?string $class, ?string $method): bool
    {
        if ($class === null || $method === null || $class === '' || $method === '') {
            return false;
        }

        return $context->codebase->getDeclaringMethod($class, $method) instanceof FunctionLikeMetadata;
    }

    /**
     * The class PHPStan would call a method's declaring class, spelled as it was written.
     *
     * Read from `getDeclaringMethod()->identifier->class`, which is the only answer that covers a method a
     * *trait* provides. Two earlier versions walked the class's own `methods` list instead and were wrong twice
     * over: that list is lowercased, so a camelCase method matched nothing, and it does not contain
     * trait-provided methods at all — `Illuminate\Support\Collection` has 115 methods and `dump` is not among
     * them, because `EnumeratesValues` provides it. Each time, the rule went quiet while every other guard
     * passed.
     *
     * Where the two engines disagree, PHPStan wins, because that is what the rule was written against: it
     * flattens traits, so a trait method's declaring class is the class that *uses* the trait. Mago names the
     * trait, so a trait is mapped back to the using class here.
     *
     * The **most ancestral** user, not the nearest, and that distinction was worth 26 wrong findings on a real
     * project. `ClassLikeMetadata->usedTraits` is flattened: `App\Collections\AnswerCollection extends
     * Illuminate\Support\Collection` lists `EnumeratesValues` itself, though its parent is what writes the
     * `use`. Taking the first candidate therefore answered `App\Collections\AnswerCollection` for
     * `->where(…)`, so a rule gating on first-party code reported every Eloquent collection call while
     * PHPStan resolved the same method to `Illuminate\Support\Collection` and declined. Measured in
     * `internal/probe-flattened-used-traits.php`.
     *
     * Metadata carries no "directly used" list and a node hook cannot read another file's `use` statements, so
     * the most ancestral user is the best available answer. It is exact wherever one class writes the `use`.
     * A subclass that *re-uses* the same trait is a real divergence — PHPStan attributes the method to the
     * subclass there — and it is named rather than handled, because nothing in metadata separates a re-use
     * from the flattening this fixes.
     */
    private static function declaringClassName(NodeAnalysisContext $context, string $class, string $method): ?string
    {
        $declaring = $context->codebase->getDeclaringMethod($class, $method);
        if (! $declaring instanceof FunctionLikeMetadata) {
            return null;
        }

        $declared = $declaring->identifier->class;
        if ($declared === null) {
            return null;
        }

        $using = self::classUsingTrait($context, $class, $declared);
        if ($using !== null) {
            return $using;
        }

        $metadata = $context->codebase->getClass($declared);

        return $metadata instanceof ClassLikeMetadata ? $metadata->originalName : $declared;
    }

    /**
     * The class in `$class`'s hierarchy that uses `$trait`, or null when `$trait` is not a trait it uses.
     *
     * PHPStan attributes a trait method to the class using the trait, and a rule asking where a method comes
     * from means that. Names are compared case-insensitively because metadata lowercases `usedTraits`.
     */
    private static function classUsingTrait(NodeAnalysisContext $context, string $class, string $trait): ?string
    {
        $name = null;
        $depth = null;
        foreach ([$class, ...$context->codebase->getClassAncestors($class)] as $candidate) {
            $metadata = $context->codebase->getClass($candidate);
            if (! $metadata instanceof ClassLikeMetadata) {
                continue;
            }

            $uses = false;
            foreach ($metadata->usedTraits as $used) {
                if (strcasecmp($used, $trait) === 0) {
                    $uses = true;

                    break;
                }
            }

            if (! $uses) {
                continue;
            }

            // The most ancestral user wins, and "most ancestral" is decided by how many ancestors a candidate
            // has rather than by the order they arrive in, which nothing documents.
            $candidateDepth = count($context->codebase->getClassAncestors($candidate));
            if ($depth === null || $candidateDepth < $depth) {
                $name = $metadata->originalName;
                $depth = $candidateDepth;
            }
        }

        return $name;
    }

    /**
     * Whether a class *is* another, or descends from it — PHPStan's `isSuperTypeOf` between two object types.
     *
     * Case-insensitive because `getClassAncestors()` answers in lowercase, the same trap that silenced an
     * earlier port. A class the codebase does not know descends from nothing, which is the safe answer: the
     * rule asking is looking for a reason to report.
     */
    public static function classDescendsFrom(NodeAnalysisContext $context, ?string $class, string $parent): bool
    {
        if ($class === null || $class === '') {
            return false;
        }

        if (strcasecmp($class, $parent) === 0) {
            return true;
        }

        foreach ($context->codebase->getClassAncestors($class) as $ancestor) {
            if (strcasecmp($ancestor, $parent) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a method declaration carries a modifier.
     *
     * `public` is the default in PHP, so php-parser's `isPublic()` is true for a method with no visibility
     * modifier at all — which is why absence has to mean public rather than unknown.
     */
    public static function methodIsPublic(?Part $method): bool
    {
        // The null case answers *no*, unlike the missing-modifier case. Absence of a modifier means public;
        // absence of a node means the navigation found nothing, and a predicate that says yes to that turns a
        // failed navigation into a reported finding. Every other helper here already defaults that way.
        if (! $method instanceof Part) {
            return false;
        }

        $modifiers = self::methodModifiers($method);

        return ! in_array('private', $modifiers, true) && ! in_array('protected', $modifiers, true);
    }

    /** Whether a method declaration is written `private`, which php-parser answers from its modifiers. */
    public static function methodIsPrivate(?Part $method): bool
    {
        return $method instanceof Part && in_array('private', self::methodModifiers($method), true);
    }

    /** Whether a method declaration is written `protected`. */
    public static function methodIsProtected(?Part $method): bool
    {
        return $method instanceof Part && in_array('protected', self::methodModifiers($method), true);
    }

    public static function methodIsStatic(?Part $method): bool
    {
        return in_array('static', self::methodModifiers($method), true);
    }

    /** @return list<string> */
    private static function methodModifiers(?Part $method): array
    {
        if (! $method instanceof Part) {
            return [];
        }

        $out = [];
        foreach ($method->children() as $child) {
            if ($child->kind === NodeKind::Modifier) {
                $out[] = strtolower(trim($child->text));
            }
        }

        return $out;
    }

    /**
     * Whether the class-like *around* this node is abstract — `$scope->getClassReflection()->isAbstract()`.
     *
     * Read from metadata rather than from a modifier, because the node a hook fired for is not the
     * declaration: a rule registered for `MethodCall` asks this of the class the call sits in, and there is no
     * `abstract` token anywhere near it. {@see declarationIsAbstract()} is the other question — the modifier
     * on a declaration the hook itself received — and the two are kept apart for that reason.
     */
    public static function enclosingClassIsAbstract(NodeAnalysisContext $context, Part|Node|null $subject): bool
    {
        $className = Declares::enclosingClassName($context, $subject);

        return $className !== null
            && $context->codebase->getClassLike($className)?->flags->contains(MetadataFlags::ABSTRACT) === true;
    }
}
