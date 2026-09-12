<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata as ClassMetadata;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * The parameters `ParamTypeCoverageRule` measures, counted the way its collector counts them.
 *
 * Its own class rather than three more methods on {@see TypeCoverage}, because the collector is not the four
 * lines of counting it looks like. Reproducing it takes a trait-user index built from the syntax, an LSP guard
 * asked of the using class, and four separate skips — and the reimplementation that fitted in one method
 * answered 3079 where the original said 4057.
 *
 * Read from the syntax tree rather than from metadata, and that is the load-bearing choice.
 * `ParameterMetadata->declaredType` is set by a docblock or an inference as well as by a written type, where
 * the collector tests php-parser's native `$param->type`; a `FunctionLikeParameter` node holds a `Hint` child
 * exactly when a type is written. Probed rather than reasoned about, in
 * `internal/probe-param-cst.php`. Reading the tree also settles scope: the collector's node type is
 * `FunctionLike`, so a closure and an arrow function count, and neither is reachable through `Codebase`
 * because a closure has no name to enumerate.
 *
 * Every counting rule here has a control under `tests/Fixtures/aggregate/controls`, compared against the real
 * rule rather than against an expectation of it.
 */
final class DeclaredParameters
{
    /**
     * Every parameter the collector would count, and how many of them carry a written type.
     *
     * @return array{total: int, typed: int, missing: list<SourceLocation>}
     */
    public static function of(AfterAnalysisContext $context): array
    {
        $total = 0;
        $typed = 0;
        $missing = [];
        $traitUsers = TraitUsers::of($context);

        foreach ($context->analysis->files as $file) {
            $source = $file->getSourceFile();
            foreach (self::FUNCTION_LIKES as $kind) {
                foreach ($source->getNodes($kind) as $functionLike) {
                    $counted = self::countable($context, $source, $functionLike, $kind, $traitUsers, $file->file);
                    if ($counted === null) {
                        continue;
                    }

                    [$parameters, $times] = $counted;
                    foreach ($parameters as $parameter) {
                        $total += $times;
                        if (Declarations::hasTypeHint($source, $parameter)) {
                            $typed += $times;

                            continue;
                        }

                        // Once, however many times it is counted: a declaration has one site, and PHPStan
                        // reports one error per (file, line, message) whatever the collector handed it.
                        $missing[] = new SourceLocation($file->file, $parameter->span);
                    }
                }
            }
        }

        return ['total' => $total, 'typed' => $typed, 'missing' => $missing];
    }

    /**
     * The parameters of one declaration that count, and how many times each of them does.
     *
     * Null where the collector produces no record at all: no parameters to analyse, a docblock declaring a
     * `callable` parameter, a method whose types LSP locks, or a trait declaration no analysed class reaches.
     *
     * @param array<string, list<array{class: string|null, aliases: list<string>}>> $traitUsers
     *
     * @return array{list<Node>, int}|null
     */
    private static function countable(
        AfterAnalysisContext $context,
        SourceFile $source,
        Node $functionLike,
        NodeKind $kind,
        array $traitUsers,
        string $file,
    ): ?array {
        $parameters = Declarations::ownParameters($source, $functionLike);

        // "nothing to analyse" in the collector, and not the same as counting zero: a function-like with no
        // parameters contributes no record at all.
        if ($parameters === [] || Declarations::declaresCallableParameter($source, $functionLike)) {
            return null;
        }

        if ($kind === NodeKind::Method && self::lockedByAncestor($context, $source, $functionLike)) {
            return null;
        }

        $times = self::timesCounted($context, $source, $functionLike, $traitUsers, $file);
        if ($times === 0) {
            return null;
        }

        // A variadic parameter has no single declaration site to name, and the collector takes it back out of
        // the count rather than counting it as untyped.
        $counted = [];
        foreach ($parameters as $parameter) {
            if (! Declarations::isVariadic($source, $parameter)) {
                $counted[] = $parameter;
            }
        }

        return [$counted, $times];
    }

    /**
     * Kinds `ParamTypeDeclarationCollector` visits, which is every `FunctionLike` php-parser has.
     *
     * @var list<NodeKind>
     */
    private const array FUNCTION_LIKES = [NodeKind::Method, NodeKind::Function, NodeKind::Closure, NodeKind::ArrowFunction];

    /**
     * How many times a declaration's parameters enter the total.
     *
     * Once for anything written in a class, an enum, an interface or a plain function — and, for a trait
     * method, once per *class* that ends up with that method. PHPStan analyses a trait's body in the context
     * of each using class, and `CollectorDataNormalizer` sums the records without deduplicating, so the same
     * two parameters arrive three times for a trait three classes use.
     *
     * None of that is a guess about PHPStan's traversal. Every clause below is a control, each its own
     * sandbox, comparing the real rule against this one:
     *
     * | shape                                                     | PHPStan | naive port |
     * |:--|--:|--:|
     * | one trait method (2 params), three using classes          |       6 |          2 |
     * | the same trait used by nobody                             |       0 |          2 |
     * | trait used by a trait used by one class                   |       2 |          2 |
     * | trait on an abstract base, two subclasses                 |       2 |          2 |
     * | trait method the using class overrides                    |       2 |          4 |
     * | trait method whose name an implemented interface declares |       4 |          6 |
     *
     * The last two are why a using class is asked two questions rather than simply counted. A class that
     * declares the method itself never has the trait's version analysed at all, and a class whose parent or
     * interface declares the name has it skipped by the collector's own LSP guard — evaluated against the
     * *using* class, because that is the class reflection the collector's scope carries inside a trait method.
     *
     * A renamed method still arrives, so `use T { m as other; }` counts too: the class's own `m` wins that
     * name while the trait's `m` is analysed in the class's context as `other`. `ClassLikeMetadata->methods`
     * does not list what a trait brings, so the alias comes from the `use` statement's own syntax and the
     * declaration is then looked up under it. Asking only about the original name counted it zero times.
     *
     * And the guard is then asked about the name it arrived under, not the original: PHPStan reads the method
     * node's name, which inside a renamed trait method is the new one, so an interface declaring the original
     * does not lock it. Controlled by adding the alias to the interface as well, which makes the original skip
     * it too.
     *
     * One shape is still a known gap: `use A, B { A::m insteadof B; B::m as other; }`, where the rename names
     * its trait — a different adaptation node, and one no measured corpus contains. A control of that shape
     * counts 2 here where PHPStan counts 4.
     *
     * @param array<string, list<array{class: string|null, aliases: list<string>}>> $traitUsers
     */
    private static function timesCounted(
        AfterAnalysisContext $context,
        SourceFile $source,
        Node $functionLike,
        array $traitUsers,
        string $file,
    ): int {
        $owner = Declarations::enclosingClassLike($source, $functionLike);
        if (! $owner instanceof Node || $owner->kind !== NodeKind::Trait) {
            return 1;
        }

        // The *resolved* name, not the written one: the map is keyed by what a `use` statement resolves to,
        // and keying one side on `Shared` while the other said `control\shared` made every trait method count
        // zero times — the port went silent on exactly the declarations this multiplier exists for.
        $trait = Declarations::classLikeName($source, $owner);
        if ($trait === null) {
            return 1;
        }

        $users = $traitUsers[strtolower($trait)] ?? [];

        // A closure or an arrow function inside a trait method is analysed with that method, so it is counted
        // as many times as that method is — the questions below are asked of the enclosing method, because a
        // closure has no name for a parent to declare or an override to win over. Answering them of the
        // closure instead returned 1 for it, and a trait's closures counted once where PHPStan counted them
        // per using class: 0 against 18 on a directory of three traits with no users in it.
        $isMethod = $functionLike->kind === NodeKind::Method;
        $named = $isMethod ? $functionLike : Declarations::enclosingMethod($source, $functionLike);
        $method = $named instanceof Node ? Declarations::declaredName($source, $named) : null;
        if (! $named instanceof Node || $method === null) {
            return count($users);
        }

        $here = $file . ':' . $named->span->start;
        $times = 0;
        foreach ($users as $user) {
            // An anonymous class has no name to ask the codebase about, so neither question can be put to it
            // and the declaration counts once for it.
            $class = $user['class'];
            if ($class === null) {
                ++$times;

                continue;
            }

            // The LSP guard skips the *method record* and nothing else: the collector's node type is
            // `FunctionLike`, and its guard tests `$node instanceof ClassMethod`, so a closure written inside
            // a locked method is still visited and still counted. Skipping the closure as well cost 3 of 5 on
            // a control with one locked user, and about half the guard's effect on a real corpus.
            // Which name this class reaches the declaration under, because that is the name the guard has to
            // be asked about. An `insteadof` or an override means it reaches it under none, and the collector
            // never sees this declaration in that class's context.
            $reached = TraitUsers::reachedAs($context, $class, $here, [$method, ...$user['aliases']]);
            if ($reached === null) {
                continue;
            }

            // The *alias*, where there is one. PHPStan reads `$classMethod->name` for the guard, and inside a
            // renamed trait method that is the new name — so an interface declaring the original does not lock
            // it. Controlled: adding the alias to the interface as well makes the original skip it too.
            // Asking about the original instead was -2 on one project's enum directory, where an enum renames
            // a two-parameter trait method its contract declares.
            if ($isMethod && self::lockedByAncestorsOf($context, $class, $reached)) {
                continue;
            }

            ++$times;
        }

        return $times;
    }

    /**
     * Whether a parent class or an interface already declares this method, which locks its parameter types.
     *
     * The collector skips such a method entirely — not its untyped parameters, the whole record — because LSP
     * means the types are not the subclass's to change. Metadata answers the ancestry: `parentClasses` and
     * `parentInterfaces` are both transitive, and both arrive lowercased, which `methodExists()` folds anyway.
     */
    private static function lockedByAncestor(AfterAnalysisContext $context, SourceFile $source, Node $method): bool
    {
        $name = Declarations::declaredName($source, $method);
        $owner = Declarations::enclosingClassLike($source, $method);
        if ($name === null || ! $owner instanceof Node) {
            return false;
        }

        return self::declaresMethod($context, self::ancestorsOf($context, $source, $owner), $name);
    }

    /** The same question for a named class, which is what a trait method's using class is. */
    private static function lockedByAncestorsOf(AfterAnalysisContext $context, string $class, string $method): bool
    {
        $metadata = $context->codebase->getClassLike($class);

        return $metadata instanceof ClassMetadata
            && self::declaresMethod($context, [...$metadata->parentClasses, ...$metadata->parentInterfaces], $method);
    }

    /**
     * @param list<string> $ancestors
     */
    private static function declaresMethod(AfterAnalysisContext $context, array $ancestors, string $method): bool
    {
        foreach (self::throughMixins($context, $ancestors) as $ancestor) {
            if ($context->codebase->methodExists($ancestor, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ancestors, plus whatever a `@mixin` on any of them puts on them, transitively.
     *
     * `ClassReflection::hasMethod()` is answered by PHPStan's own `MixinMethodsClassReflectionExtension`, in
     * core and not in larastan, so a class whose *ancestor* carries `@mixin X` has every method of `X` and
     * the collector's LSP guard skips it. That was the whole of this metric's over-count on
     * `laravel/framework`: bisecting `Illuminate` by directory put +1190 of +1310 in `Database`, +55 in
     * `Redis` and +16 in `Pagination`, and the other 35 directories at exactly zero.
     *
     * Controlled rather than reasoned about, each row predicted before the run:
     *
     * | shape                                             | PHPStan | port before |
     * |:--|--:|--:|
     * | `@mixin` on the parent, target declares the method |       3 |           5 |
     * | the same control with the `@mixin` line removed    |       5 |           5 |
     * | `@mixin` on the parent, target *documents* it      |       1 |           3 |
     * | a three-link `@mixin` chain                        |       3 |           5 |
     * | `@mixin` naming a class nothing resolves           |       5 |           5 |
     *
     * The documenting row needs nothing extra — `methodExists()` already answers for a `@method` line, which
     * is why a `@method` on a plain parent never diverged. The unresolvable row is the reason this cannot
     * close every case: `@mixin \Redis` on `Illuminate\Redis\Connections\Connection` is answered by PHPStan
     * from the loaded extension, and mago's `\Redis` is missing `hscan`.
     *
     * The subject's own `@mixin` is deliberately not consulted here. The guard asks `getParents()` and
     * `getInterfaces()`, so a class documenting a mixin on itself does not thereby lock its own methods —
     * which is the one way this question differs from {@see Mixins::declaringMethod()}, where the class
     * itself is part of what is asked.
     *
     * @param list<string> $ancestors
     *
     * @return list<string>
     */
    private static function throughMixins(AfterAnalysisContext $context, array $ancestors): array
    {
        return [...$ancestors, ...Mixins::targetsOf($context->codebase, $ancestors)];
    }

    /**
     * Everything above a class-like declaration, whether or not it has a name.
     *
     * Declared ancestry only, which is where the LSP guard diverges from the original and cannot be made not
     * to. `ClassReflection::hasMethod()` consults PHPStan's method-reflection extensions, so on a Laravel
     * project a factory annotated `@extends Factory<Model>` has every `for<Relation>` and `has<Relation>` its
     * model declares — larastan's `ModelFactoryMethodsClassReflectionExtension` says so — and the collector
     * skips those methods. Metadata knows only what is written.
     *
     * The same mechanism, worse, for whatever class a project configures as its auth model:
     * `AuthsMethodsExtension` answers `hasMethod()` on `Illuminate\Contracts\Auth\Authenticatable` by looking
     * the name up on that model, so implementing the contract puts every one of the model's own method names
     * on an ancestor and the collector skips all of them, counting only its closures.
     *
     * Measured on two consumers: of one project's 79-parameter over-count, 56 is the factory extension, 12 is
     * PHPStan's own reflection-extension interfaces being unreachable inside `phpstan.phar`, and 12 is the auth
     * model. `tests/Support/run-coverage-setdiff.php` names the declarations behind any of them.
     *
     * **Read from the tree for every class, named or not.** The clauses are the declaration's own; the name is
     * not. A file can declare one name twice — `nikic/php-parser` writes two `TokenPolyfill` classes behind a
     * `PHP_VERSION_ID` guard — and the metadata keeps one of them, so asking the codebase by name gave the
     * *other* declaration's parent. Every method the second declares that `PhpToken` also declares then looked
     * locked by LSP and was skipped, which is where the -7 this metric used to accept on `nikic/php-parser`
     * came from. Probed: the CST holds both declarations and both bodies, while the metadata for the name
     * holds `parent='phptoken'` and no methods at all.
     *
     * The tree route was already here for anonymous classes, which have no name to look up; it turned out to
     * be the right route for both. Each named ancestor's own ancestry is still folded in from metadata, which
     * is what the transitive `parentClasses` gave before. Skipping the question for an anonymous class instead
     * cost 4 against 2 on a control whose anonymous class implements an interface declaring the method — the
     * LSP guard could not fire where PHPStan's did.
     *
     * @return list<string>
     */
    private static function ancestorsOf(AfterAnalysisContext $context, SourceFile $source, Node $owner): array
    {
        $ancestors = [];
        foreach ($source->getChildren($owner) as $child) {
            if ($child->kind !== NodeKind::Extends && $child->kind !== NodeKind::Implements) {
                continue;
            }

            foreach ($source->getChildren($child) as $part) {
                $resolved = $part->kind === NodeKind::Identifier ? $source->getResolvedName($part)?->name : null;
                if ($resolved === null) {
                    continue;
                }

                $ancestors[] = $resolved;
                $metadata = $context->codebase->getClassLike($resolved);
                if ($metadata instanceof ClassMetadata) {
                    $ancestors = [...$ancestors, ...$metadata->parentClasses, ...$metadata->parentInterfaces];
                }
            }
        }

        return $ancestors;
    }
}
