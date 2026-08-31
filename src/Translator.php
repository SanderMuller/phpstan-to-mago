<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;

/**
 * Statement and expression translation: two of `Transpiler`'s four jobs, and they cannot be separated.
 *
 * The split `CLAUDE.md` describes is four ways. Three are reachable by extraction — orchestration stays in
 * `Transpiler`, emission is {@see Emitter}, and everything else is here. The fourth boundary, between
 * statements and expressions, is not: inlining a helper translates *statements* from inside expression
 * resolution, and a loop body translates *expressions* from inside statement translation. Taking the
 * transitive closure of either entry point yields the same set of methods. Separating them is a design
 * change to how helpers are inlined, not a move.
 *
 * Orchestration reaches this class through the entry points below and this class reaches back into
 * orchestration not at all — which is what makes the cut a cut.
 *
 * @phpstan-import-type Descriptor from Transpiler
 * @phpstan-import-type Declaration from Transpiler
 * @phpstan-import-type RecordFields from Transpiler
 * @phpstan-import-type RecordField from Transpiler
 */
final readonly class Translator
{
    public function __construct(
        private TranslationContext $context,
        private Emitter $emitter,
        private string $file,
    ) {}

    /**
     * Stands in for a Rust expression that does not exist, on a descriptor only the PHP target ever reads.
     *
     * A comment rather than a plausible identifier on purpose: if one of these ever reaches emitted Rust, the
     * generated crate fails to compile instead of quietly analysing the wrong thing.
     */
    private const string PHP_ONLY = '/* PHP target only */';

    /**
     * A runaway backstop, not a shape limit: real recursion is caught by name above, so reaching this means
     * a chain long enough that the emitted expression would be unreadable anyway.
     */
    private const int INLINE_DEPTH_LIMIT = 24;

    /**
     * A member name written literally.
     *
     * `$object->$method()` and `Foo::{$name}` put an expression here rather than a name, and casting
     * that to string is a TypeError rather than a useful answer. The vocabulary has nothing to say
     * about a dynamic name, so this refuses instead of crashing.
     */
    public function memberName(Node|string $name, int $line): string
    {
        if (is_string($name) || $name instanceof Identifier || $name instanceof Name) {
            return (string) $name;
        }

        throw new Refusal('dynamic member name', $line);
    }

    /**
     * What a construct is, in the terms whoever has to support it would look it up by.
     *
     * `Expr_MethodCall` names php-parser's class, which is the same answer for every method call there is.
     * Seventeen rules across four packages refused with exactly that string, so the largest single gap in the
     * tool read as one unknown instead of the several named ones it turned out to be. `->getVariants()` is a
     * thing to go and support; a node class is not.
     */
    private function describe(Node $node): string
    {
        return match (true) {
            $node instanceof MethodCall => '->' . $this->memberLabel($node->name) . '()',
            $node instanceof NullsafeMethodCall => '?->' . $this->memberLabel($node->name) . '()',
            $node instanceof StaticCall => $this->classLabel($node->class) . '::' . $this->memberLabel($node->name) . '()',
            $node instanceof FuncCall => $this->memberLabel($node->name) . '()',
            $node instanceof PropertyFetch => '->' . $this->memberLabel($node->name),
            $node instanceof NullsafePropertyFetch => '?->' . $this->memberLabel($node->name),
            $node instanceof ClassConstFetch => $this->classLabel($node->class) . '::' . $this->memberLabel($node->name),
            default => $node->getType(),
        };
    }

    /**
     * Why a `foreach` cannot be translated, naming what the rule wrote rather than what it resolved to.
     *
     * The message used to be "no iteration mapped for a {kind}", where the kind is this transpiler's internal
     * name for whatever the loop subject turned out to be. `subtree` told a reader nothing: three rules refused
     * that way and all three write `foreach (... ->stmts as ...)`, which is one nameable missing capability —
     * iterating the statements of a declaration or a closure body — and not three unrelated gaps.
     *
     * `->stmts` was the whole cluster and is now built: {@see Runtime\Members::statementsOf()} reads a body's
     * top-level statements and `subtree` is iterable. Three rules moved past it in one step, which is what
     * the cluster promised, and none of them emits — each meets something else immediately. Two want a
     * predicate for a statement *kind* (`instanceof Stmt\Expression`, `instanceof Stmt\ClassConst`) and the
     * third a flag carried across loop iterations. So the capability was one thing and the rules behind it
     * are not one thing, which this comment claimed before anyone had looked.
     */
    private function noIterationRefusal(Expr $subject, string $kind): string
    {
        return sprintf('no iteration mapped for %s, which resolved to a %s', $this->describe($subject), $kind);
    }

    /**
     * What an `if` in an inlined helper is, beyond being an `if`.
     *
     * A helper is inlined as a guard chain: single-statement bodies that exit. `Stmt_If` alone read as a missing
     * statement type, and the four rules refusing that way turned out to be three different shapes — a nested
     * early `return true`, a return of a non-literal expression, and a `foreach` inside a recursive helper. The
     * body is what separates them, so the message says what it is.
     */
    private function ifShape(If_ $statement): string
    {
        $body = $statement->stmts;
        $last = $body === [] ? null : $body[count($body) - 1];

        return sprintf(
            'an if whose body is %d %s%s, which is a decision tree rather than a guard that exits',
            count($body),
            count($body) === 1 ? 'statement' : 'statements',
            $last instanceof Node ? ' ending in ' . $this->describe($last) : '',
        );
    }

    /** A member's written name, or a placeholder — this is for a message, so a dynamic name must not throw. */
    private function memberLabel(Node|string $name): string
    {
        return is_string($name) || $name instanceof Identifier || $name instanceof Name
            ? (string) $name
            : '{expr}';
    }

    private function classLabel(Node $class): string
    {
        return $class instanceof Name ? $class->getLast() : '{expr}';
    }

    public function resolveClassName(Name $name): string
    {
        $first = $name->getFirst();
        if (isset($this->context->useMap[$first])) {
            $rest = array_slice($name->getParts(), 1);

            return $this->context->useMap[$first] . ($rest === [] ? '' : '\\' . implode('\\', $rest));
        }

        return $name->toString();
    }

    private function declaresMethod(ClassLike $class, string $name): bool
    {
        foreach ($class->getMethods() as $method) {
            if ((string) $method->name === $name) {
                return true;
            }
        }

        return false;
    }

    public function findMethod(ClassLike $class, string $name): ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ((string) $method->name === $name) {
                return $method;
            }
        }

        throw new Refusal("no $name() method");
    }

    /**
     * A `ReflectionProvider` question answered from Mago's codebase, or null when it is not one.
     *
     * The service itself has no injectable equivalent, so it is never handed to a generated plugin. What a
     * rule actually *asks* it does translate: whether a class is known, which is `classLikeExists()`. Asked
     * through the property the package wires as `@reflectionProvider`, so a rule reaching for some other
     * service still refuses by name.
     *
     * @param array<Arg> $args
     */
    private function reflectionProviderCall(MethodCall $expr, string $method, array $args): ?string
    {
        // Both spellings reach here: the rule's own `$this->reflectionProvider`, and a parameter an outer
        // inlining bound to the service — a shim passes one down through several helpers.
        if ($this->serviceArgument($expr->var, $expr->getStartLine()) !== 'reflectionProvider') {
            return null;
        }

        // `hasFunction($name, $scope)` — whether the codebase knows a function. The scope argument is how
        // PHPStan resolves a namespaced name against the current file; the helper resolves it the same way.
        if ($method === 'hasFunction' && count($args) >= 1) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a function-existence question, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->context->backend->call('function_exists', [
                '$context',
                $this->nameText($this->resolve($args[0]->value, $expr->getStartLine()), $expr->getStartLine()),
            ]);
        }

        // `$reflectionProvider->hasConstant($node->name, $scope)`. The name argument is the node the hook
        // fired for, so the helper is handed that rather than a rendered name: resolving a constant read is
        // PHP's two-step fallback, not a string comparison — see {@see Constants::constantMetadata()}.
        if ($method === 'hasConstant' && count($args) === 2) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a constant-existence question, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->context->backend->call('constant_exists', [
                '$context',
                $this->operand($this->resolve($args[0]->value, $expr->getStartLine())),
            ]);
        }

        if ($method === 'hasClass' && count($args) === 1) {
            // The name can be a literal — `hasClass(Foo::class)` — or a name read out of the analysed file by
            // `$scope->resolveName()`. The second is what the positional-flag rules do, and it is the whole
            // point of asking: the class under analysis is not known at transpile time.
            $named = $this->resolvedNameArgument($args[0]->value, $expr->getStartLine());
            if ($named !== null) {
                return $this->context->backend->call('class_exists', ['$context', $named]);
            }

            try {
                $literal = $this->classLiteral($args[0]->value, $expr->getStartLine());
            } catch (Refusal $refusal) {
                // A third spelling, and the one the mocking rules use: the name is a *value* the plugin holds
                // while it runs — a literal string the mocked type names, walked out of `getConstantStrings()`.
                // Neither a written literal nor a name read off a node, and `classExists()` takes the text
                // either way. Tried only after the literal path, because `resolve()` refuses a string literal
                // by node kind on purpose and reaching for it first cost an already-emitting rule.
                $value = Transpiler::$target === 'php' ? $this->resolve($args[0]->value, $expr->getStartLine()) : null;
                if ($value !== null && in_array($value['kind'], ['bytes', 'class-name', 'config-bytes'], true)) {
                    return $this->context->backend->call('class_exists', ['$context', $this->operand($value)]);
                }

                throw $refusal;
            }

            return $this->context->backend->call('class_exists', Transpiler::$target === 'php'
                ? ['$context', $this->context->backend->bytes($literal)]
                : ['context', $this->context->backend->bytes($literal)]);
        }

        return null;
    }

    /**
     * A name read out of the analysed file, rendered as a PHP expression, or null when the value is not one.
     *
     * Only the PHP target carries `$scope->resolveName()`, so a Rust target falls through to the literal path
     * and refuses there with the message that names the construct.
     */
    private function resolvedNameArgument(Expr $value, int $line): ?string
    {
        if (Transpiler::$target !== 'php') {
            return null;
        }

        try {
            $subject = $this->resolve($value, $line);
        } catch (Refusal) {
            return null;
        }

        // A parent class name a loop bound is the same kind of answer: a name known only at analysis time.
        return in_array($subject['kind'], ['resolved-name', 'class-name'], true) ? $this->operand($subject) : null;
    }

    /**
     * The method name a `hasMethod()`/`getMethod()` call asks about, as a descriptor.
     *
     * Written either as a literal or as the selector read off the node under analysis —
     * `$classReflection->hasMethod($node->name->name)` — so both resolve rather than only the literal.
     *
     * @param array<Arg> $args
     *
     * @return Descriptor
     */
    private function methodNameArgument(array $args, string $method, int $line): array
    {
        $args = array_values($args);
        if (! isset($args[0])) {
            throw new Refusal("{$method}() with no method name to ask about", $line);
        }

        // A written name first: `hasMethod('expects')` is a literal, and resolving one as an expression is
        // both indirect and refused — `Scalar_String` is not an access path.
        // A written name, or a constant standing for one: `hasMethod(MethodName::INVOKE)` names `__invoke`
        // through a class of constants, and the value is known here exactly as a literal would be.
        if ($args[0]->value instanceof String_ || $args[0]->value instanceof ClassConstFetch) {
            $literal = $this->rawStringLiteral($args[0]->value, $line);

            return ['rust' => $this->context->backend->bytes($literal), 'kind' => 'bytes', 'php' => $this->context->backend->bytes($literal)];
        }

        $subject = $this->resolve($args[0]->value, $line);
        // `config-bytes` is a name a loop bound from a constant list or a configured one — `$methodName` walking
        // the methods a rule treats as opaque. Still just a string at runtime.
        if (in_array($subject['kind'], ['bytes', 'class-name', 'resolved-name', 'config-bytes'], true)) {
            return $subject;
        }

        // A name read off the node is a tree part, and the question takes a string.
        if (in_array($subject['kind'], ['name-expr', 'name-selector', 'local-name'], true)) {
            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => $this->context->backend->call('text_of', [$this->operand($subject)]),
            ];
        }

        throw new Refusal("{$method}() asked about a {$subject['kind']} rather than a method name", $line);
    }

    private function refuseCallOnService(MethodCall $expr, string $method): void
    {
        // Walked rather than checked one level down: `$this->reflectionProvider->getClass(..)->isFinal()`
        // fails on `isFinal()`, and naming that says nothing about why. The service at the root of the chain
        // is what would have to be translated.
        $receiver = $expr->var;
        while ($receiver instanceof MethodCall) {
            $receiver = $receiver->var;
        }

        if (! $receiver instanceof PropertyFetch
            || ! $receiver->var instanceof Variable
            || $receiver->var->name !== 'this'
        ) {
            return;
        }

        $property = $this->memberName($receiver->name, $expr->getStartLine());
        if (! isset($this->context->injected[$property])) {
            return;
        }

        throw new Refusal(
            "\${$property} holds the PHPStan service {$this->context->injected[$property]}, so ->{$method}() has to "
            . "be translated onto Mago's codebase instead",
            $expr->getStartLine(),
        );
    }

    /**
     * `in_array($needle, $haystack)` as one set-membership test, strict or loose.
     *
     * @param array<Arg> $args
     */
    private function inArrayPredicate(array $args, int $line): string
    {
        // Anything other than a literal `true` is treated as loose, including a flag the rule computes:
        // {@see refuseLooseUnlessItAgreesWithStrict} proves the two forms answer the same question, so a
        // flag whose value is unknown does not change the answer either.
        $strict = count($args) >= 3 && $args[2]->value instanceof ConstFetch
            && strtolower($args[2]->value->name->toString()) === 'true';

        // A list the plugin computes at analysis time rather than one written in the rule: the traits a
        // declaration uses, for instance. Compared case-insensitively, because metadata lowercases the names
        // it holds while the needle keeps whatever spelling its author wrote — a strict comparison between
        // the two is the silent-miss shape, and folding case is what PHPStan gets by canonicalising both
        // sides through reflection.
        // Only where the haystack is not one of the written forms {@see stringList} reads. Resolving those
        // first refused a literal array by its node kind, which cost an emitting rule.
        $written = $args[1]->value instanceof Array_ || $args[1]->value instanceof ClassConstFetch;
        // Only a list of *names* metadata produced. Those arrive lowercased, so folding case is what the
        // original's strict comparison against canonical names asks. A list the rule built itself holds
        // whatever it put there, and folding case for that would be wider than the `true` it was given.
        $haystack = $written ? null : $this->resolve($args[1]->value, $line);
        if ($haystack !== null && $this->holdsMetadataNames($haystack)) {
            // The fold above belongs to the strict form: `==` between two strings is already case-sensitive,
            // so carrying it over would report where the rule stays silent.
            if (! $strict) {
                throw new Refusal('in_array() without strict comparison, over names the plugin canonicalises', $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('in_array() over a computed list, which only the PHP target carries', $line);
            }

            return $this->context->backend->call('names_contain', [
                $this->operand($haystack),
                $this->nameText($this->resolve($args[0]->value, $line), $line),
            ]);
        }

        $options = $this->stringList($args[1]->value, $line);
        if (! $strict) {
            $this->refuseLooseUnlessItAgreesWithStrict($options, $line);
        }

        return $this->oneOf($args[0]->value, $options, 'in_array()', $line, $this->classNameList($args[1]->value, $line));
    }

    /**
     * `<subject> is one of <options>`, dispatched on what the subject is.
     *
     * Shared by `in_array($x, [...], true)` and `isset(self::MAP[$x])`, which ask the same question of the
     * same kinds of subject. `$asked` names the construct so a refusal says which one could not be answered.
     *
     * Every accepted kind has to be text. {@see refuseLooseUnlessItAgreesWithStrict} leans on that to
     * translate the loose `in_array()` as this one: `true == 'abc'` and `null == ''` both hold where `===`
     * does not, so a non-text kind added here would take the loose form with it and no test would say so.
     *
     * @param list<string> $options
     */
    private function oneOf(Expr $subjectExpr, array $options, string $asked, int $line, bool $classNames = false): string
    {
        $subject = $this->resolve($subjectExpr, $line);
        $list = $this->byteSliceList($options);

        // A list of class names, compared against the name the file resolves to rather than the one it
        // writes. {@see classNameList} says why, and `resolvedNameIsOneOf()` carries the same leading-`\`
        // and case handling `nameEquals()` was measured into.
        if ($classNames) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal("{$asked} over class names, which only the PHP target resolves", $line);
            }

            if ($subject['kind'] !== 'name-expr') {
                throw new Refusal("{$asked} over class names against a {$subject['kind']}", $line);
            }

            return $this->context->backend->call('resolved_name_is_one_of', ['$context', $this->operand($subject), $list]);
        }

        return match ($subject['kind']) {
            'local-name' => Transpiler::$target === 'php'
                ? $this->context->backend->call('bytes_is_one_of', [$this->operand($subject), $list])
                : "support::local_name_is_one_of({$subject['rust']}, &{$list})",
            'name-selector' => $this->context->backend->call('selector_is_one_of', [$this->operand($subject), Transpiler::$target === 'php' ? $list : '&' . $list]),
            'name-expr' => $this->nameExprIsOneOf($subject, $list),
            'extends' => "support::extends_is_one_of(context, node, &{$list})",
            'bytes', 'class-name' => $this->context->backend->call('bytes_is_one_of', [$this->operand($subject), Transpiler::$target === 'php' ? $list : '&' . $list]),
            default => throw new Refusal("{$asked} over a {$subject['kind']}", $line),
        };
    }

    /**
     * A loose `in_array()` is translated only where `==` and `===` cannot disagree.
     *
     * Rules do write the loose form, and treating it as the strict one would be an approximation — except
     * where the two provably answer the same question. Three things have to hold, and only the last is
     * checked here:
     *
     * - Every element folds to a string literal at transpile time. {@see stringList} accepts nothing else:
     *   a written literal, or a constant whose value is one and is read here.
     * - The needle is a name or text. This matters on its own: `true == 'abc'` and `null == ''` both hold
     *   where `===` does not, and neither needle is a string. {@see oneOf} accepts only name and text kinds
     *   and refuses the rest by kind, which is what carries it.
     * - None of the elements is numeric. Since PHP 8 two numeric strings compare numerically, so
     *   `'0' == '0.0'` holds where `===` does not.
     *
     * Where it does not hold the refusal names the element, not the construct, because the construct is not
     * the obstacle. `PreferDirectIsNameRule` is the case that has to stay refused for a different reason
     * again: it compares an `Identifier` object against strings and genuinely depends on `==` calling
     * `__toString()`, which is why the needle half is not merely a formality.
     *
     * @param list<string> $options
     */
    private function refuseLooseUnlessItAgreesWithStrict(array $options, int $line): void
    {
        foreach ($options as $option) {
            if (is_numeric($option)) {
                throw new Refusal("in_array() without strict comparison, against the numeric string '{$option}'", $line);
            }
        }
    }

    /**
     * `match (<subject>) { 'a', 'b' => true, default => false }` as one set-membership test.
     *
     * The shape a rule uses to ask whether a name is one of a few — `isBareBoolOrNullFlag()` asks it of
     * `true`, `false` and `null`. A `match` whose arms are anything other than that is not a membership test,
     * and is refused rather than partly translated.
     */
    private function matchAsOneOf(Match_ $expr): string
    {
        $options = [];
        $default = null;
        foreach ($expr->arms as $arm) {
            $result = $arm->body;
            if (! $result instanceof ConstFetch) {
                throw new Refusal('a match arm yielding something other than true or false', $expr->getStartLine());
            }

            $yields = strtolower($result->name->toString());
            if ($arm->conds === null) {
                $default = $yields;

                continue;
            }

            if ($yields !== 'true') {
                throw new Refusal('a match arm yielding something other than true', $expr->getStartLine());
            }

            foreach ($arm->conds as $condition) {
                $options[] = $this->rawStringLiteral($condition, $expr->getStartLine());
            }
        }

        if ($default !== 'false' || $options === []) {
            throw new Refusal('a match that is not a set-membership test', $expr->getStartLine());
        }

        return $this->oneOf($expr->cond, $options, 'a match', $expr->getStartLine());
    }

    /**
     * `<a name expression> is one of <options>`, rendered per target.
     *
     * Rust asks the node directly; PHP reads the node's text first, which is the same route a message
     * argument takes for this kind. Kept out of the dispatch so the arm stays one expression.
     *
     * @param Descriptor $subject
     */
    private function nameExprIsOneOf(array $subject, string $list): string
    {
        if (Transpiler::$target !== 'php') {
            return "support::name_is_one_of({$subject['rust']}, &{$list})";
        }

        return $this->context->backend->call('bytes_is_one_of', [
            $this->context->backend->call('text_of', [$this->operand($subject)]),
            $list,
        ]);
    }

    /**
     * `isset(<a map constant>[<subject>])`, which is a set-membership test spelled as an array read.
     *
     * `BaseNoDebugRule::isDebugFunction()` is exactly this, and so is every other lookup in the corpus that
     * uses a constant table. The keys are the set; the values are always `true` and say nothing.
     */
    private function issetOverConstant(Isset_ $expr): string
    {
        if (count($expr->vars) !== 1) {
            throw new Refusal('isset() with more than one subject', $expr->getStartLine());
        }

        $fetch = $expr->vars[0];
        if (! $fetch instanceof ArrayDimFetch || ! $fetch->dim instanceof Expr) {
            throw new Refusal('isset() over something other than an array read', $expr->getStartLine());
        }

        $table = $fetch->var;

        // `isset($matches['name'])` — whether the match caught that group, which is the only thing a rule asks
        // of a match besides the value itself.
        if ($table instanceof Variable
            && is_string($table->name)
            && ($this->context->locals[$table->name]['kind'] ?? null) === 'captures'
        ) {
            return $this->capturedGroup(
                $this->context->locals[$table->name],
                $this->stringLiteral($fetch->dim, $expr->getStartLine()),
                $expr->getStartLine(),
            ) . ' !== null';
        }

        // A lookup table the constructor built — from configured values, a class constant and literals — is a
        // property on the generated plugin, so membership in it is an array read at analysis time rather than a
        // set known at transpile time.
        if ($table instanceof PropertyFetch && $table->var instanceof Variable && $table->var->name === 'this') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('isset() over a constructed lookup table, which only the PHP target carries', $expr->getStartLine());
            }

            $property = $this->resolve($table, $expr->getStartLine());

            return $this->context->backend->call('lookup_has', [$this->operand($property), $this->stringValue($fetch->dim, $expr->getStartLine())]);
        }

        // The same table reached through a parameter: a helper takes `$this->unsafeMethodsLookup` as an
        // argument, so inside it the lookup is a local. Still the plugin's own property at runtime.
        if ($table instanceof Variable && is_string($table->name) && isset($this->context->locals[$table->name])) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('isset() over a lookup table passed to a helper, which only the PHP target carries', $expr->getStartLine());
            }

            $bound = $this->context->locals[$table->name];
            if (in_array($bound['kind'], ['lookup', 'config-list'], true)) {
                return $this->context->backend->call('lookup_has', [$this->operand($bound), $this->stringValue($fetch->dim, $expr->getStartLine())]);
            }

            throw new Refusal("isset() over a {$bound['kind']}", $expr->getStartLine());
        }

        if (! $table instanceof ClassConstFetch
            || ! $table->class instanceof Name
            || ! in_array($table->class->toString(), ['self', 'static'], true)
        ) {
            throw new Refusal("isset() over an array that is not one of the rule's own constants", $expr->getStartLine());
        }

        $name = $this->memberName($table->name, $expr->getStartLine());
        if (! isset($this->context->constantKeys[$name])) {
            throw new Refusal("isset() over self::{$name}, which is not a constant map of string keys", $expr->getStartLine());
        }

        return $this->oneOf($fetch->dim, $this->context->constantKeys[$name], 'isset()', $expr->getStartLine());
    }

    /**
     * `<a string> === <a string>`, or null when either side is not one.
     *
     * `ChecksNamespace` compares a configured prefix against the file's namespace before falling back to a
     * prefix test, and both sides are values rather than nodes. Resolved through the same descriptors as
     * everything else, so a literal, a bound parameter and the namespace all work.
     */
    private function stringComparison(Expr $left, Expr $right, int $line): ?string
    {
        // A variable on either side is the common shape. A *call* against a written literal is the other one —
        // `strtolower($node->name->getLast()) !== 'request'`. Only against a literal: `strtoupper($x) === $x`
        // is a different question with its own translation, and matching it here would answer "are these two
        // strings equal" where the rule asked "is this already uppercase".
        $callAgainstLiteral = ($left instanceof FuncCall && $right instanceof String_)
            || ($right instanceof FuncCall && $left instanceof String_);

        if (! $left instanceof Variable && ! $right instanceof Variable && ! $callAgainstLiteral) {
            return null;
        }

        try {
            $first = $this->resolve($left, $line);
            $second = $this->resolve($right, $line);
        } catch (Refusal) {
            return null;
        }

        // A configured value is a string the plugin carries, so comparing one against a string is the same
        // comparison. It reaches here as the item of a configured list, which is what iterating one binds.
        $comparable = ['bytes', 'class-name', 'config-bytes'];
        if (! in_array($first['kind'], $comparable, true) || ! in_array($second['kind'], $comparable, true)) {
            return null;
        }

        return Transpiler::$target === 'php'
            ? $this->operand($first) . ' === ' . $this->operand($second)
            : $this->operand($first) . ' == ' . $this->operand($second);
    }

    /**
     * `Strings::match($subject, $pattern)` or `preg_match($pattern, $subject)` as a boolean, or null.
     *
     * Only the yes-or-no half. A caller reading a capture — `$match[1]` — is asking a second question this
     * does not answer, and falls through to the refusal that names the construct.
     *
     * The pattern has to be a literal at transpile time, which every caller in the corpus writes as a class
     * constant. A pattern built while the plugin runs would be a different capability.
     */
    private function patternTest(Expr $expr, int $line): ?string
    {
        if ($expr instanceof StaticCall
            && $expr->class instanceof Name
            && $expr->class->getLast() === 'Strings'
            && $this->memberName($expr->name, $line) === 'match'
            && count($expr->getArgs()) === 2
        ) {
            [$subject, $pattern] = $expr->getArgs();
        } elseif ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'preg_match'
            && count($expr->getArgs()) === 2
        ) {
            [$pattern, $subject] = $expr->getArgs();
        } else {
            return null;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('a pattern test, which only the PHP target carries', $line);
        }

        // `bytesValue()` rather than `bytes()` on the raw literal. A pattern is the one string in the corpus
        // that is *about* backslashes — `#\\Php\d+\\#` matches a namespace separator — and `PhpBackend::bytes()`
        // undoes Rust's two escape sequences on what it is handed. Passing the value straight in emitted one
        // backslash where the rule has two, which is a different regex and a PCRE warning at analysis time.
        return $this->context->backend->call('matches_pattern', [
            $this->nameText($this->resolve($subject->value, $line), $line),
            $this->bytesValue($pattern->value, $line),
        ]);
    }

    /**
     * `<a nullable string> === null`, or null when the right-hand side is not the null literal.
     *
     * The shape a rule uses before asking anything of a value. Real rules null-check the namespace before
     * comparing it, and refusing that would push them into handing a possibly-null value to a string
     * function.
     */
    private function nullComparison(Expr $left, Expr $right, int $line): ?string
    {
        if (! $right instanceof ConstFetch || strtolower($right->name->toString()) !== 'null') {
            return null;
        }

        // `Strings::match($subject, $pattern) === null` is "the pattern does not match", spelled through the
        // return value. Nette hands back the capture array or null, and with two arguments and the defaults
        // that is exactly `preg_match()`'s own answer — read from `Strings::match()` rather than assumed:
        // the `u` modifier it can append is behind a `$utf8` parameter neither caller passes. A call with
        // more arguments than the two is refused below, where an unknown static helper is.
        $match = $this->patternTest($left, $line);
        if ($match !== null) {
            return '! ' . $match;
        }

        $subject = $this->resolve($left, $line);

        // A value producer's `=== null` check is the caller re-asking what the producer already answered: every
        // way the producer returns null is a guard that has already bailed by the time this is reached, and
        // what it does return — `count($args) - 1` — is an int. So the check cannot hold.
        if ($subject['kind'] === 'int') {
            return $this->unreachable('an index produced behind guards is never null once those guards have run');
        }

        // `$scope->getClassReflection() === null` inside a class-declaration hook. The hook fires *on* a
        // class-like, so there is always one — the same argument the produced-value cases above make, and
        // gated on the hook's kind because in any other hook the scope may genuinely have no class.
        if ($subject['kind'] === 'class-reflection'
            && $this->everyHookKindIsInAClass()
        ) {
            return $this->unreachable(
                'this hook fires on a class-like or on one of its members, so the scope it carries always has '
                . 'a class reflection',
            );
        }

        // `$docComment === null` is `instanceof Doc` the other way round, and the descriptor is already the text.
        if ($subject['kind'] === 'docblock') {
            return $this->operand($subject) . ' === null';
        }

        // `$parameters[$i] ?? null === null` asks whether the method declares a parameter at that position at
        // all, which a call passing more arguments than the method takes does not.
        if ($subject['kind'] === 'parameter') {
            return '! ' . $this->parameterQuestion('has_parameter_at', $subject, $line);
        }

        // `$node->stmts === null` on a method declaration is *whether it has a body*: an abstract method and
        // an interface method have none, and php-parser spells that absence as a null statement list.
        // {@see Support::bodyOf} answers the same null, and its comment records what had to be measured to
        // make it do so.
        if ($subject['kind'] === 'subtree') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a body test, which only the PHP target carries', $line);
            }

            return $this->operand($subject) . ' === null';
        }

        // `$node->if === null` on a ternary is *whether it has a middle arm*: an elvis has none, and php-parser
        // spells that absence as a null. {@see Support::conditionalThen} answers the same null, by counting the
        // arms rather than reading a position — see its comment for why position cannot tell the two apart.
        //
        // Gated on the field, not on the `expr` kind: most navigations that resolve to one always find
        // something, and letting every `expr` compare to null would emit guards that can never hold.
        if ($subject['kind'] === 'expr'
            && ($subject['key'] ?? null) === '$node->if'
            && $this->context->nodeKind === 'Conditional'
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a ternary middle-arm test, which only the PHP target carries', $line);
            }

            return $this->operand($subject) . ' === null';
        }

        // A record materialised across a loop is null exactly when its fields are, because they are declared
        // null before the loop and assigned together inside it. Asked of one materialised field rather than
        // of a separate flag: a second variable tracking the same fact is a second thing that can disagree
        // with it. See {@see foldRecordInLoop}.
        if ($subject['kind'] === 'record') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a record materialised across a loop, which only the PHP target carries', $line);
            }

            /** @var RecordFields $fields */
            $fields = $subject['record'] ?? [];

            return $this->materialisedWitness($fields, $line) . ' === null';
        }

        if (! in_array($subject['kind'], ['bytes', 'class-name'], true)) {
            // Names what was written as well as what it resolved to. `null comparison against a subtree` told a
            // reader nothing: the one rule refusing that way asks `$node->stmts === null`, which is *whether the
            // declaration has a body* — a question with an answer, where `subtree` reads as an internal state.
            throw new Refusal(sprintf(
                'null comparison against %s, which resolved to a %s',
                $this->describe($left),
                $subject['kind'],
            ), $line);
        }

        return Transpiler::$target === 'php'
            ? $this->operand($subject) . ' === null'
            : $subject['rust'] . '.is_none()';
    }

    /**
     * The pieces of a property declaration: the names it declares, and one name's text.
     *
     * `protected $a = 1, $b = 2;` is one declaration with two names, which php-parser calls `$node->props`.
     * Mago spells the same list as `PropertyItem` children of a `PlainProperty`, read from a probe of the tree
     * rather than assumed.
     *
     * @param Descriptor $base
     *
     * @return array{rust: string, kind: string, key?: string, php?: string}|null
     */
    private function resolvePropertyDeclaration(array $base, string $property, string $key, int $line): ?array
    {
        if ($base['kind'] === 'property-item' && $property === 'name') {
            return [
                'rust' => "support::property_item_name({$base['rust']})",
                'kind' => 'bytes',
                'key' => $key,
                'php' => $this->context->backend->call('property_item_name', [$this->operand($base)]),
            ];
        }

        // `$node->attrGroups` — the attributes on the method this hook fired for. php-parser nests them one
        // level deeper, groups each holding attributes, and metadata carries them flattened. Exact for the
        // question `NoReturnSetterMethodRule` asks, because a declaration has an empty group list exactly when
        // it has no attributes.
        //
        // Method only, though the same field exists on a class-like: `NoEntityOutsideEntityNamespaceRule` is
        // the class-like case and this does not carry it, because it walks *both* levels to reach each
        // attribute's name. Answering that from a flattened list would take an `->attrs` and a `->name` that
        // each hand back what they were given — three mappings pretending the tree has a shape it does not,
        // where one wrong step reads as a rule that works. It refuses instead, and the refusal now names
        // `->attrs` on a flattened list rather than the field.
        if ($base['kind'] === 'hook-node' && $property === 'attrGroups' && $this->context->nodeKind === 'Method') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal("a declaration's attributes, which only the PHP target carries", $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'attribute-names',
                'key' => $key,
                'php' => 'Support::attributeNames($context, $node)',
            ];
        }

        // `$node->namespacedName` on a class-like declaration — the fully qualified name PHPStan's name
        // resolution put there. `enclosingClassName()` reads it off the CST rather than out of metadata, so the
        // case survives: measured as `App\Forms\ContactFormType`, which matters because both rules reaching
        // this do a case-sensitive `str_ends_with` on it.
        //
        // PHPStan makes it null for an anonymous class, and both rules guard on that. The port never sees one:
        // `AnonymousClass` is a node kind of its own in Mago and a class-like hook registers `Class`, `Enum`
        // and `Interface`, so the declaration never arrives. Probed — a `Class` hook over a file holding one
        // fired exactly once, for the named class.
        if ($base['kind'] === 'hook-node' && $property === 'namespacedName'
            && in_array($this->context->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal("a declaration's qualified name, which only the PHP target carries", $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'class-name',
                'key' => $key,
                'php' => 'Support::enclosingClassName($context, $node)',
            ];
        }

        // `$classLike->implements` — the interfaces the declaration writes. Only from a class-like hook: on
        // anything else the property is not this question.
        if ($base['kind'] === 'hook-node' && $property === 'implements'
            && in_array($this->context->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('the interfaces a declaration writes, which only the PHP target carries', $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'class-names',
                'key' => $key,
                'php' => 'Support::directInterfaceNames($context, $node)',
            ];
        }

        if ($base['kind'] === 'hook-node' && $property === 'props') {
            return [
                'rust' => 'support::property_items(context, node)',
                'kind' => 'property-items',
                'key' => $key,
                'php' => $this->context->backend->call('property_items', ['$context', '$node']),
            ];
        }

        return null;
    }

    /**
     * `$scope->getNamespace()`, or null when the expression is something else.
     *
     * Answered from the file's own text, which only the PHP runtime reads. `support.rs` has no counterpart,
     * and emitting a call to a helper that does not exist would produce Rust that cannot compile — worse
     * than a refusal, because the count would still say emitted.
     *
     * @return array{rust: string, kind: string, key?: string, php?: string}|null
     */
    private function resolveScopeNamespace(Expr $expr, int $line): ?array
    {
        if (! $expr instanceof MethodCall
            || ! $expr->var instanceof Variable
            || $expr->var->name !== 'scope'
            || $this->memberName($expr->name, $expr->getStartLine()) !== 'getNamespace'
        ) {
            return null;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('getNamespace() is answered from the file text, which only the PHP target reads', $line);
        }

        return [
            'rust' => 'support::enclosing_namespace(context)',
            'kind' => 'bytes',
            'key' => '$scope->getNamespace()',
            'php' => 'Support::enclosingNamespace($context)',
        ];
    }

    /**
     * One of the rule's own constructor properties, as the generated plugin would read it.
     *
     * Null means "not a constructor property", so the caller carries on with its other paths. A property
     * that *is* one but cannot be carried refuses here instead, naming which of the three obstacles it hit:
     * a PHPStan service, a value derived in the constructor body, or configuration the package never wired.
     *
     * @return array{rust: string, kind: string, key?: string, php?: string}|null
     */
    private function resolveOwnProperty(PropertyFetch $expr, string $property, string $key, int $line): ?array
    {
        if (! $expr->var instanceof Variable || $expr->var->name !== 'this') {
            return null;
        }

        if (isset($this->context->injected[$property])) {
            throw new Refusal(
                "\${$property} holds the PHPStan service {$this->context->injected[$property]}, which has no "
                . 'injectable equivalent; its uses have to be translated instead',
                $line,
            );
        }

        if (isset($this->context->finders[$property])) {
            return ['rust' => self::PHP_ONLY, 'kind' => 'node-finder', 'key' => $key, 'php' => self::PHP_ONLY];
        }

        if (isset($this->context->pure[$property])) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal(
                    "\${$property} is derived in the constructor, which only the PHP target can carry",
                    $line,
                );
            }

            $this->context->usesConfiguration = true;

            return [
                'rust' => 'self.' . Emitter::snake($property),
                'kind' => 'config-list',
                'key' => $key,
                'php' => '$this->' . $property,
            ];
        }

        if (isset($this->context->derived[$property])) {
            throw new Refusal(
                "\${$property} is computed in the constructor and {$this->context->derived[$property]}",
                $line,
            );
        }

        if (isset($this->context->unwired[$property])) {
            // Which of the two facts is the cause. An unregistered rule has no wiring *because* nothing
            // registers it, so naming the missing wiring alone reads as a gap to close and is a symptom.
            $cause = $this->context->ruleIsUnregistered
                ? ', and no neon the package ships names this rule at all — so there is nothing to wire it '
                    . 'from, and a consumer that wants it registers and configures it itself'
                : ', and its type names no PHPStan service, so there is no value for the generated plugin to '
                    . 'carry';

            throw new Refusal(
                "\${$property} is a constructor parameter the package's neon does not wire for "
                . $this->context->unwired[$property] . $cause,
                $line,
            );
        }

        if (! isset($this->context->configured[$property])) {
            return null;
        }

        $this->context->usesConfiguration = true;

        return [
            'rust' => 'self.' . Emitter::snake($property),
            'kind' => $this->context->configured[$property]['kind'],
            'key' => $key,
            'php' => '$this->' . $property,
        ];
    }

    /**
     * Records a map constant's keys, which is how the corpus spells a set.
     *
     * `['dump' => true, 'dd' => true]` answers `isset(self::X[$name])`. Kept apart from a list constant
     * because the question asked of it is membership in the keys, and the values carry nothing. A single
     * non-string key makes the whole constant unusable as a set, so none of it is recorded.
     */
    private function collectConstantKeys(string $name, Array_ $value): void
    {
        $keys = [];
        foreach ($value->items as $item) {
            if ($item === null || ! $item->key instanceof String_) {
                return;
            }

            $keys[] = $item->key->value;
        }

        if ($keys !== []) {
            $this->context->constantKeys[$name] = $keys;
        }
    }

    public function collectConstants(ClassLike $class): void
    {
        // The hierarchy, not just the class: `self::METHOD_DEBUG_STATEMENTS` is declared on the abstract rule
        // three of `hihaho/phpstan-rules`' own rules extend, and PHP resolves it from there. Collected
        // nearest-first so a class that redeclares a constant wins, as it does at runtime.
        foreach (array_reverse($this->hierarchy()->selfAndAncestors($class)) as $declaring) {
            $this->collectOwnConstants($declaring);
        }
    }

    private function collectOwnConstants(ClassLike $class): void
    {
        foreach ($class->getConstants() as $const) {
            foreach ($const->consts as $c) {
                if ($c->value instanceof String_) {
                    $this->context->constants[(string) $c->name] = $c->value->value;

                    continue;
                }

                if ($c->value instanceof Int_) {
                    $this->context->intConstants[(string) $c->name] = $c->value->value;

                    continue;
                }

                if ($c->value instanceof Array_) {
                    $this->collectConstantKeys((string) $c->name, $c->value);

                    $values = [];
                    foreach ($c->value->items as $item) {
                        if ($item === null) {
                            continue 2;
                        }

                        try {
                            $values[] = $this->rawStringLiteral($item->value, $c->getStartLine());
                        } catch (Refusal) {
                            continue 2; // not resolvable to strings; leave the constant unresolved
                        }
                    }

                    $this->context->arrayConstants[(string) $c->name] = $values;
                }
            }
        }
    }

    /**
     * Translates the argument of `RuleErrorBuilder::message()` into a Rust expression producing the
     * message. `Issue::error` takes `impl Into<String>`, so a literal and a `format!` both fit.
     */
    private function translateMessageExpression(Expr $expr): string
    {
        if ($expr instanceof String_) {
            return '"' . addcslashes($expr->value, '"\\') . '"';
        }

        if ($expr instanceof ClassConstFetch) {
            return '"' . addcslashes($this->selfConstant($expr), '"\\') . '"';
        }

        if ($expr instanceof Variable && is_string($expr->name)) {
            $local = $this->context->locals[$expr->name] ?? null;
            if (($local['kind'] ?? null) === 'message') {
                return $local['rust'];
            }

            throw new Refusal("\${$expr->name} is not a message built in this rule", $expr->getStartLine());
        }

        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'sprintf'
        ) {
            return $this->translateSprintf($expr);
        }

        // `"{$a} uses {$b} but does not implement {$c}."` — a message written as an interpolation rather than
        // through `sprintf()`. The same pieces, joined the way the target joins strings; each interpolated part
        // goes through the message-argument renderer, so a part with no string rendering refuses by its kind.
        if ($expr instanceof InterpolatedString) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('an interpolated message, which only the PHP target carries', $expr->getStartLine());
            }

            $parts = [];
            foreach ($expr->parts as $part) {
                if ($part instanceof InterpolatedStringPart) {
                    $parts[] = $this->context->backend->bytes($part->value);

                    continue;
                }

                if (! $part instanceof Expr) {
                    throw new Refusal('a message interpolates something that is not an expression', $expr->getStartLine());
                }

                $parts[] = $this->stringValue($part, $expr->getStartLine());
            }

            $this->context->messageIsExpression = true;

            return implode(' . ', $parts);
        }

        throw new Refusal('message expression outside the vocabulary: ' . $this->describe($expr), $expr->getStartLine());
    }

    /** `sprintf(<format>, <args>)` -> `format!("...", ...)`, with PHP's specifiers rewritten. */
    private function translateSprintf(FuncCall $expr): string
    {
        $args = $expr->getArgs();
        if ($args === []) {
            throw new Refusal('sprintf() without a format', $expr->getStartLine());
        }

        $formatArg = $args[0]->value;

        // `<a description the port cannot read> ?? '<literal>'`. `getDeprecatedDescription()` is PHPStan
        // reading the `@deprecated` text out of a docblock, and mago's `ConstantMetadata` carries the flag
        // and no text at all — checked field by field. So the left side is always null here and the format is
        // always the literal, which is what PHPStan itself uses for every built-in constant, none of which
        // has a docblock. A user-defined constant with `@deprecated Use X instead.` gets that sentence from
        // PHPStan and the generic one from the port: a message divergence at a site both engines report, not
        // a finding either of them misses.
        if ($formatArg instanceof Coalesce && $formatArg->right instanceof String_) {
            $formatArg = $formatArg->right;
        }

        $format = match (true) {
            $formatArg instanceof String_ => $formatArg->value,
            $formatArg instanceof ClassConstFetch => $this->selfConstant($formatArg),
            default => throw new Refusal('sprintf() format is not a literal or a class constant', $expr->getStartLine()),
        };

        [$rustFormat, $expected] = $this->rustFormat($format, $expr->getStartLine());
        $values = array_slice($args, 1);
        if (count($values) !== $expected) {
            throw new Refusal(
                sprintf('sprintf() has %d placeholders but %d arguments', $expected, count($values)),
                $expr->getStartLine(),
            );
        }

        $translated = [];
        foreach ($values as $value) {
            $translated[] = $this->stringValue($value->value, $expr->getStartLine());
        }

        if (Transpiler::$target === 'php') {
            // PHP keeps its own format string, so the placeholders do not need translating; only the
            // values do, and those have already been rendered for this target.
            return 'sprintf(' . $this->context->backend->bytes($format)
                . ($translated === [] ? '' : ', ' . implode(', ', $translated)) . ')';
        }

        return 'format!("' . $rustFormat . '"' . ($translated === [] ? '' : ', ' . implode(', ', $translated)) . ')';
    }

    /**
     * PHP's format specifiers as Rust's, plus the escaping each language needs.
     *
     * @return array{string, int} the Rust format string and how many arguments it consumes
     */
    private function rustFormat(string $format, int $line): array
    {
        $out = '';
        $count = 0;
        $length = strlen($format);
        for ($i = 0; $i < $length; ++$i) {
            $char = $format[$i];
            if ($char === '{' || $char === '}') {
                $out .= $char . $char; // Rust escapes braces by doubling them

                continue;
            }

            if ($char === '"' || $char === '\\') {
                $out .= '\\' . $char;

                continue;
            }

            if ($char !== '%') {
                $out .= $char;

                continue;
            }

            $rest = substr($format, $i);
            if (str_starts_with($rest, '%%')) {
                $out .= '%';
                ++$i;

                continue;
            }

            if (preg_match('/^%(?:\.(\d+))?([sdf])/', $rest, $match) !== 1) {
                throw new Refusal('unsupported sprintf specifier near "' . substr($rest, 0, 6) . '"', $line);
            }

            $out .= $match[1] === '' ? '{}' : '{:.' . $match[1] . '}';
            ++$count;
            $i += strlen($match[0]) - 1;
        }

        return [$out, $count];
    }

    /**
     * Whether every element of a written list is a `::class` fetch.
     *
     * The provenance decides what the comparison is *against*. `[Name::class, FullyQualified::class]` is a
     * list of class names, and PHPStan compares it to `$node->class->toString()` — which php-parser has
     * already resolved through the file's imports. Mago's tree keeps the name as written, so comparing the
     * written text to a fully-qualified list is silent on exactly the imported spelling the rule targets.
     * {@see oneOf} asks the resolved name instead when this holds.
     *
     * A mixed list is refused rather than guessed: half the elements would need one operand and half the
     * other, and there is no single comparison that answers both.
     *
     * Only literal `::class` fetches count. `SensioClass::IS_GRANTED` folds to a fully-qualified *string* and
     * is indistinguishable from one a rule wrote out, which is why this reads the node and not the value.
     */
    private function classNameList(Expr $expr, int $line): bool
    {
        if (! $expr instanceof Array_ || $expr->items === []) {
            return false;
        }

        $classNames = 0;
        foreach ($expr->items as $item) {
            if ($item !== null
                && $item->value instanceof ClassConstFetch
                && $this->memberName($item->value->name, $line) === 'class'
            ) {
                ++$classNames;
            }
        }

        if ($classNames !== 0 && $classNames !== count($expr->items)) {
            throw new Refusal('list mixes ::class fetches with other elements, which compare against different operands', $line);
        }

        return $classNames !== 0;
    }

    /**
     * A list of string literals, written inline or as one of the rule's own constants.
     *
     * @return list<string>
     */
    private function stringList(Expr $expr, int $line): array
    {
        if ($expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && in_array($expr->class->toString(), ['self', 'static'], true)
            && isset($this->context->arrayConstants[$this->memberName($expr->name, $expr->getStartLine())])
        ) {
            return $this->context->arrayConstants[$this->memberName($expr->name, $expr->getStartLine())];
        }

        if ($expr instanceof Array_) {
            $values = [];
            foreach ($expr->items as $item) {
                if ($item === null) {
                    throw new Refusal('list contains a hole rather than a string literal', $line);
                }

                // A constant standing for a string, resolved the same way a name argument is: the rules that
                // reach here list `SensioClass::IS_GRANTED` and `SymfonyFunctionName::SERVICE`, constants on
                // *other* classes in the same package, and `Name::class`. Each is known at transpile time
                // exactly as a literal would be, so the emitted comparison is against the value. Not a
                // catch-all: `rawStringLiteral()` refuses a variable, an array and a dynamic class alike, and
                // the item is named in the refusal so a new shape says which one stopped it.
                if (! $item->value instanceof String_) {
                    try {
                        $values[] = $this->rawStringLiteral($item->value, $line);

                        continue;
                    } catch (Refusal) {
                        throw new Refusal(sprintf(
                            'list contains %s rather than a string literal',
                            $this->describe($item->value),
                        ), $line);
                    }
                }

                $values[] = $item->value->value;
            }

            return $values;
        }

        throw new Refusal('not a resolvable list of strings', $line);
    }

    /** Records something survey mode took for granted, once per distinct assumption. */
    public function assume(string $note): void
    {
        if (! in_array($note, $this->context->assumed, true)) {
            $this->context->assumed[] = $note;
        }
    }

    /**
     * Enters a helper being inlined, refusing one that reaches itself.
     *
     * {@see $inlining} says why this is a cycle check rather than a depth cap. `$what` names the relation in
     * the refusal, because "following" and "inlining" are different failures to read.
     */
    private function enterInline(string $name, string $what, int $line): void
    {
        if (in_array($name, $this->context->inlining, true)) {
            throw new Refusal("{$what} {$name}() reaches {$name}() again, so it cannot be inlined", $line);
        }

        if (count($this->context->inlining) >= self::INLINE_DEPTH_LIMIT) {
            throw new Refusal("{$what} {$name}() nests deeper than " . self::INLINE_DEPTH_LIMIT, $line);
        }

        $this->context->inlining[] = $name;
        ++$this->context->inlineDepth;
    }

    /** Leaves the helper {@see enterInline()} entered. */
    private function leaveInline(): void
    {
        array_pop($this->context->inlining);
        --$this->context->inlineDepth;
    }

    /**
     * Inlines a PHP method whose source we can find, as a Rust boolean expression.
     *
     * This replaces hand-written translations of helper predicates. A helper is just a function in a
     * file: parse it, bind its parameters to the caller's arguments, and translate its body with the
     * same vocabulary as the rule itself. Only two body shapes are accepted — a single `return`, and
     * a chain of `if (..) { return <bool>; }` ending in a `return` — because anything else is a
     * different feature (loops, accumulators) rather than a different helper.
     *
     * @param list<Arg> $args
     */
    private function inlineMethod(ClassLike $class, string $methodName, array $args, int $line, ?array $uses = null): string
    {
        $method = null;
        foreach ($class->getMethods() as $candidate) {
            if ((string) $candidate->name === $methodName) {
                $method = $candidate;
                break;
            }
        }

        if ($method === null) {
            throw new Refusal("no method {$methodName}() to inline", $line);
        }

        // Bind the parameters, then translate the body in the helper's own constant scope.
        $savedLocals = $this->context->locals;
        $savedLiterals = $this->context->literals;
        $savedCaches = $this->context->caches;
        $savedConstants = $this->context->constants;
        $savedInts = $this->context->intConstants;
        $savedArrayConstants = $this->context->arrayConstants;
        $savedClass = $this->context->currentClass;
        $savedUses = $this->context->useMap;

        $this->context->locals = $this->bindParameters($method, $args, $methodName, $line);
        $this->context->constants = [];
        $this->context->intConstants = [];
        $this->context->arrayConstants = [];
        $this->context->currentClass = $class;
        if ($uses !== null) {
            $this->context->useMap = $uses;
        }

        $this->collectConstants($class);
        $this->enterInline($methodName, 'inlining', $line);

        try {
            return $this->translateMethodAsPredicate($method, $line);
        } finally {
            $this->leaveInline();
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
            $this->context->constants = $savedConstants;
            $this->context->intConstants = $savedInts;
            $this->context->arrayConstants = $savedArrayConstants;
            $this->context->currentClass = $savedClass;
            $this->context->useMap = $savedUses;
        }
    }

    /**
     * Bind a helper's parameters to the caller's arguments, as descriptors the body can then resolve.
     *
     * @param array<Arg> $args
     *
     * @return array<string, Descriptor>
     */
    private function bindParameters(ClassMethod $method, array $args, string $methodName, int $line): array
    {
        $bound = [];
        $args = array_values($args);
        foreach ($method->params as $index => $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                throw new Refusal("{$methodName}() has a parameter that is not a simple variable", $line);
            }

            if (! isset($args[$index])) {
                // A parameter with a default is optional, and a call that omits it is ordinary PHP. Refusing
                // those made the *reason* a rule was refused depend on which version of an unrelated vendor
                // package was installed — `Strings::match()` grew a third parameter — which CI caught as an
                // unstable census rather than as anything about the rule.
                if ($param->default instanceof Expr) {
                    continue;
                }

                throw new Refusal("{$methodName}() is called with fewer arguments than it declares", $line);
            }

            $argument = $args[$index]->value;

            // A literal argument is also known at transpile time, and a helper may use it where a literal is
            // required — `rtrim($namespace, '\\') . '\\'` with `$namespace` bound to `'App'`. Recorded
            // alongside the descriptor rather than instead of it, since the same parameter may be read either
            // way in the same body.
            try {
                $literal = $this->rawStringLiteral($argument, $line);
            } catch (Refusal) {
                $literal = null;
            }

            if ($literal !== null) {
                $this->context->literals[$param->var->name] = $literal;
                $bound[$param->var->name] = [
                    'rust' => $this->context->backend->bytes($literal),
                    'kind' => 'bytes',
                    'php' => $this->context->backend->bytes($literal),
                ];

                continue;
            }

            unset($this->context->literals[$param->var->name]);

            // A PHPStan service the rule was constructed with is not a value the plugin carries, and reading
            // one refuses. Its *calls* do translate though — `$reflectionProvider->hasClass()` becomes a
            // codebase question — so the parameter binds to the service by name and the inlined body's
            // `$reflectionProvider->..` reaches the same translation `$this->reflectionProvider->..` does.
            $service = $this->serviceArgument($argument, $line);
            if ($service !== null) {
                $bound[$param->var->name] = ['rust' => self::PHP_ONLY, 'kind' => 'service', 'service' => $service];

                continue;
            }

            // A parameter the helper declares `bool` takes a condition, not a value, and the two are
            // translated by different halves of this class: `$node->isAbstract()` answers as a predicate and
            // refuses as an access path. Gated on the *declared* type rather than on the argument's shape,
            // because translating speculatively can inline a helper as a side effect and leave its lines
            // behind when the value turns out not to be a condition — the same reason the assignment path
            // restricts itself to expressions that can only be conditions.
            if ($param->type instanceof Identifier && $param->type->toLowerString() === 'bool') {
                $condition = '(' . $this->translateCondition($argument) . ')';
                $bound[$param->var->name] = ['rust' => $condition, 'kind' => 'bool', 'php' => $condition];

                continue;
            }

            // `$scope` is the analysis context on both sides, so it needs no descriptor.
            $bound[$param->var->name] = $argument instanceof Variable && $argument->name === 'scope'
                ? ['rust' => 'context', 'kind' => 'scope']
                : $this->resolve($argument, $line);
        }

        return $bound;
    }

    /**
     * The PHPStan service an argument holds, or null when it holds something else.
     *
     * Reads both spellings: the rule's own `$this->reflectionProvider`, and a parameter already bound to the
     * service by an outer inlining — a shim passes one on, and the depth is unbounded in principle.
     */
    private function serviceArgument(Expr $argument, int $line): ?string
    {
        if ($argument instanceof PropertyFetch
            && $argument->var instanceof Variable
            && $argument->var->name === 'this'
        ) {
            return $this->context->injected[$this->memberName($argument->name, $line)] ?? null;
        }

        if ($argument instanceof Variable && is_string($argument->name)) {
            $local = $this->context->locals[$argument->name] ?? null;

            return ($local['kind'] ?? null) === 'service' ? ($local['service'] ?? null) : null;
        }

        return null;
    }

    /**
     * `foreach (<items> as $item) { if (<cond>) { return true; } }` as one "any of them" expression.
     *
     * The shape a predicate helper uses to ask whether *some* member satisfies something —
     * `NoEloquentWithPropertyRule::declaresWithProperty()` asks it of a property's declared names. It is the
     * same question `array_any()` asks, so it emits the same combinator rather than a loop: a helper is
     * inlined as an expression, and a loop is not one.
     */
    private function foreachAsAny(Foreach_ $statement, int $depth = 0): string
    {
        if ($statement->byRef || $statement->keyVar instanceof Expr) {
            throw new Refusal('a foreach in an inlined helper that binds a key or a reference', $statement->getStartLine());
        }

        $item = $statement->valueVar;
        if (! $item instanceof Variable || ! is_string($item->name)) {
            throw new Refusal('a foreach in an inlined helper over something other than a simple variable', $statement->getStartLine());
        }

        $body = $statement->stmts;

        $subject = $this->resolve($statement->expr, $statement->getStartLine());

        // The classes a type names, iterated: the same list a loop in the rule's own body gets, and for the
        // same reason — the single-class reduction would go quiet on a union receiver.
        if ($subject['kind'] === 'sole-class' && Transpiler::$target === 'php') {
            $subject = [
                'rust' => self::PHP_ONLY,
                'kind' => 'class-names',
                'php' => $this->handlePart($subject, 'listPhp', $statement->getStartLine()),
            ];
        }

        if (! isset(Vocabulary::ITERABLES[$subject['kind']])) {
            throw new Refusal(
                $this->noIterationRefusal($statement->expr, $subject['kind']) . ', in an inlined helper',
                $statement->getStartLine(),
            );
        }

        $saved = $this->context->locals;
        // The loop variable shadows anything of the same name, including a literal an *earlier* inline bound
        // to a parameter called the same thing. `ChecksNamespace` binds `$namespace` to `'App'` for the
        // singular check and iterates a configured list under the same name for the plural one, so without
        // this the second check compares every item against the first check's literal.
        $savedLiterals = $this->context->literals;
        $savedCaches = $this->context->caches;
        unset($this->context->literals[$item->name]);
        $bound = 'item' . ($depth === 0 ? '' : (string) $depth);
        $this->context->locals[$item->name] = [
            'rust' => $bound,
            'kind' => Vocabulary::ITERABLES[$subject['kind']]['item'],
            'php' => '$' . $bound,
        ] + (isset($subject['as']) ? ['as' => $subject['as']] : []);

        try {
            $predicate = $this->anyBodies($body, $depth);
        } finally {
            $this->context->locals = $saved;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
        }

        if (Transpiler::$target === 'php') {
            return $this->context->backend->call('any_of', [
                $this->operand($subject),
                "static fn (\${$bound}): bool => {$predicate}",
            ]);
        }

        $iterable = str_replace('{rust}', $subject['rust'], Vocabulary::ITERABLES[$subject['kind']]['iter']);

        return "{$iterable}.any(|{$bound}| {$predicate})";
    }

    /**
     * A loop body reduced to the predicate "this item matches".
     *
     * One guard is the common shape. A longer body is accepted when every statement before the last is either
     * a `continue` guard — a reason this item does *not* match — or a local binding the last one reads:
     * `isDebugHelperMethodCall()` skips a class with no such method, reads the declaring class, then matches on
     * its prefix. Folded, that is `! skipped && matched`, which is what the loop computes.
     *
     * @param array<Stmt> $body
     */
    private function anyBodies(array $body, int $depth): string
    {
        if ($body === []) {
            throw new Refusal('a foreach in an inlined helper with an empty body');
        }

        $last = array_pop($body);
        $conditions = [];
        foreach ($body as $statement) {
            // A binding the final condition reads. Bound rather than emitted: this is one expression.
            if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                $this->bindLocal($statement->expr, $statement->getStartLine());

                continue;
            }

            if ($statement instanceof If_
                && $statement->elseifs === []
                && ! $statement->else instanceof Else_
                && count($statement->stmts) === 1
                && $statement->stmts[0] instanceof Continue_
            ) {
                // `continue` says this item does not match, so the loop matches when the guard does *not* hold.
                $conditions[] = '!(' . $this->stripOuterParentheses($this->translateCondition($statement->cond)) . ')';

                continue;
            }

            throw new Refusal(
                'a foreach in an inlined helper whose body is not a guard chain: ' . $this->describe($statement),
                $statement->getStartLine(),
            );
        }

        $conditions[] = $this->anyBody($last, $depth);

        return count($conditions) === 1 ? $conditions[0] : '(' . implode(' && ', $conditions) . ')';
    }

    /**
     * What a loop answers when an item matches, as the boolean literal it returns.
     *
     * Every return inside the loop has to agree: two polarities in one loop is a different shape, and folding
     * it into one "any of them" would answer the opposite question for half the items.
     */
    private function loopMatchValue(Foreach_ $statement): string
    {
        $values = [];
        foreach ((new NodeFinder())->findInstanceOf([$statement], Return_::class) as $return) {
            $literal = $return->expr instanceof Expr ? $this->isBooleanLiteral($return->expr) : null;
            if ($literal === null) {
                throw new Refusal('a foreach in an inlined helper returning something other than a boolean', $return->getStartLine());
            }

            $values[$literal] = true;
        }

        if (count($values) !== 1) {
            throw new Refusal('a foreach in an inlined helper returning both booleans', $statement->getStartLine());
        }

        return array_key_first($values);
    }

    private function anyBody(Stmt $body, int $depth): string
    {
        if ($body instanceof Foreach_) {
            if ($depth >= self::INLINE_DEPTH_LIMIT) {
                throw new Refusal('an "any of them" loop nested deeper than ' . self::INLINE_DEPTH_LIMIT, $body->getStartLine());
            }

            return $this->stripOuterParentheses($this->foreachAsAny($body, $depth + 1));
        }

        if (! $body instanceof If_ || $body->elseifs !== [] || $body->else instanceof Else_) {
            throw new Refusal('a foreach in an inlined helper whose body is not a single guard', $body->getStartLine());
        }

        $returned = $this->soleReturn($body->stmts);
        if (! $returned instanceof Expr || $this->isBooleanLiteral($returned) === null) {
            throw new Refusal('a foreach in an inlined helper that does not return a boolean when it matches', $body->getStartLine());
        }

        return $this->stripOuterParentheses($this->translateCondition($body->cond));
    }

    /**
     * The expression a memoised helper memoises, or null when the body is not that shape.
     *
     * A cache around a pure question is invisible to the answer, so the generated plugin computes the question
     * and is done. Two spellings, both in the corpus:
     *
     *     static $cache = []; if (! array_key_exists($k, $cache)) { $cache[$k] = <expr>; } return $cache[$k];
     *     static $cache = []; $k = ..; if (array_key_exists($k, $cache)) { return $cache[$k]; }
     *                         return $cache[$k] = <expr>;
     *
     * Recognised as a whole rather than statement by statement, because `static $cache` on its own says nothing
     * about whether dropping it is sound.
     */
    private function memoisedExpression(ClassMethod $method): ?Expr
    {
        $statements = $method->stmts ?? [];

        // The second spelling binds the key between the declaration and the cache logic. The binding is dropped
        // with the cache, so it may only serve the key: an expression reading it would lose its value.
        $keyed = $this->keyedMemoisation($statements);
        if ($keyed instanceof Expr) {
            return $keyed;
        }

        if (count($statements) !== 3
            || ! $statements[0] instanceof Static_
            || ! $statements[1] instanceof If_
            || ! $statements[2] instanceof Return_
        ) {
            return null;
        }

        [$declaration, $fill, $return] = $statements;
        if (count($declaration->vars) !== 1 || ! $declaration->vars[0]->var instanceof Variable) {
            return null;
        }

        $cache = $declaration->vars[0]->var->name;
        if (! is_string($cache)
            || ! $return->expr instanceof ArrayDimFetch
            || ! $return->expr->var instanceof Variable
            || $return->expr->var->name !== $cache
            || count($fill->stmts) !== 1
        ) {
            return null;
        }

        $only = $fill->stmts[0];
        if (! $only instanceof Expression
            || ! $only->expr instanceof Assign
            || ! $only->expr->var instanceof ArrayDimFetch
            || ! $only->expr->var->var instanceof Variable
            || $only->expr->var->var->name !== $cache
        ) {
            return null;
        }

        return $only->expr->expr;
    }

    /**
     * The expression memoised by the read-first spelling of the cache, or null when the body is not it.
     *
     * `static $cache = []; $k = ..; if (array_key_exists($k, $cache)) { return $cache[$k]; }
     * return $cache[$k] = <expr>;`
     *
     * @param array<Stmt> $statements
     */
    private function keyedMemoisation(array $statements): ?Expr
    {
        if (count($statements) < 3 || ! $statements[0] instanceof Static_) {
            return null;
        }

        $declaration = $statements[0];
        if (count($declaration->vars) !== 1 || ! $declaration->vars[0]->var instanceof Variable) {
            return null;
        }

        $cache = $declaration->vars[0]->var->name;
        if (! is_string($cache)) {
            return null;
        }

        $rest = array_slice($statements, 1);

        // Any bindings between the declaration and the cache logic serve the key, and the key goes away with
        // the cache. Collected so the memoised expression can be checked against them.
        $bound = [];
        while (($rest[0] ?? null) instanceof Expression
            && $rest[0]->expr instanceof Assign
            && $rest[0]->expr->var instanceof Variable
            && is_string($rest[0]->expr->var->name)
        ) {
            $bound[] = $rest[0]->expr->var->name;
            array_shift($rest);
        }

        if (count($rest) !== 2 || ! $rest[0] instanceof If_ || ! $rest[1] instanceof Return_) {
            return null;
        }

        [$hit, $miss] = $rest;
        if (! $this->readsCache($hit, $cache) || ! $miss->expr instanceof Assign) {
            return null;
        }

        $fill = $miss->expr;
        if (! $fill->var instanceof ArrayDimFetch
            || ! $fill->var->var instanceof Variable
            || $fill->var->var->name !== $cache
        ) {
            return null;
        }

        foreach ((new NodeFinder())->findInstanceOf([$fill->expr], Variable::class) as $read) {
            if (is_string($read->name) && in_array($read->name, $bound, true)) {
                return null;
            }
        }

        return $fill->expr;
    }

    /** `if (array_key_exists($k, $cache)) { return $cache[$k]; }` — the cache-hit branch, and nothing else. */
    private function readsCache(If_ $hit, string $cache): bool
    {
        if ($hit->elseifs !== [] || $hit->else instanceof Else_ || count($hit->stmts) !== 1) {
            return false;
        }

        $only = $hit->stmts[0];

        return $only instanceof Return_
            && $only->expr instanceof ArrayDimFetch
            && $only->expr->var instanceof Variable
            && $only->expr->var->name === $cache
            && $hit->cond instanceof FuncCall
            && $hit->cond->name instanceof Name
            && $hit->cond->name->toString() === 'array_key_exists'
            // The table asked about has to be the cache this recognised. Without it a body checking one array
            // and returning from another would be read as a memoisation of neither.
            && ($hit->cond->getArgs()[1]->value ?? null) instanceof Variable
            && $hit->cond->getArgs()[1]->value->name === $cache;
    }

    /**
     * `if (outer) { <bindings> if (inner) return <bool>; }` folded into the helper's guard chain.
     *
     * A guard that exits is `if (cond) return <bool>;` and folds to one `[cond, value]` pair. A helper that
     * groups two such guards under a shared condition — `hasClass()` before asking what kind of class it is —
     * is the same question written one level in, and folds to `[outer && inner, value]` per inner guard.
     *
     * The bindings between them are the reason the shape exists: `$classReflection = getClass($name)` is only
     * meaningful once `hasClass($name)` holds. `&&` short-circuits, so anding the outer condition into each
     * pair keeps that order — and the names are scoped to the block, because a later statement reading one
     * would be reading a value the outer condition does not guarantee.
     *
     * Null rather than a refusal when the body is some other shape, so the caller's message still names it.
     *
     * @return list<array{string, string}>|null
     */
    private function nestedGuards(If_ $outer): ?array
    {
        $saved = [$this->context->locals, $this->context->literals, $this->context->caches];
        $guards = [];

        try {
            $condition = $this->translateCondition($outer->cond);

            foreach ($outer->stmts as $statement) {
                if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                    $this->bindLocal($statement->expr, $statement->getStartLine());

                    continue;
                }

                if ($this->takesACacheStatement($statement)) {
                    continue;
                }

                if (! $statement instanceof If_
                    || $statement->elseifs !== []
                    || $statement->else instanceof Else_
                    || count($statement->stmts) !== 1
                    || ! $statement->stmts[0] instanceof Return_
                ) {
                    return null;
                }

                $returned = $statement->stmts[0]->expr;
                if (! $returned instanceof ConstFetch) {
                    return null;
                }

                $guards[] = [
                    '(' . $condition . ' && ' . $this->translateCondition($statement->cond) . ')',
                    strtolower($returned->name->toString()) === 'true' ? 'true' : 'false',
                ];
            }
        } finally {
            [$this->context->locals, $this->context->literals, $this->context->caches] = $saved;
        }

        return $guards === [] ? null : $guards;
    }

    /** The accepted helper shapes, as one Rust expression. */
    private function translateMethodAsPredicate(ClassMethod $method, int $line): string
    {
        $memoised = $this->memoisedExpression($method);
        if ($memoised instanceof Expr) {
            return '(' . $this->translateCondition($memoised) . ')';
        }

        /** @var list<array{string, string}> condition and the value returned when it holds */
        $guards = [];
        $final = null;

        foreach ($method->stmts ?? [] as $statement) {
            if ($final !== null) {
                throw new Refusal('statements after the return of an inlined helper', $statement->getStartLine());
            }

            if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                $this->bindLocal($statement->expr, $statement->getStartLine());

                continue;
            }

            // A cache declared and filled part-way through a predicate helper. Neither statement contributes to
            // the expression this folds to: the reads carry what the cache stood for.
            if ($this->takesACacheStatement($statement)) {
                continue;
            }

            if ($statement instanceof If_
                && $statement->elseifs === []
                && ! $statement->else instanceof Else_
                && count($statement->stmts) === 1
                && $statement->stmts[0] instanceof Return_
            ) {
                $returned = $statement->stmts[0]->expr;
                if (! $returned instanceof ConstFetch) {
                    throw new Refusal('early return from a helper that is not a boolean literal', $statement->getStartLine());
                }

                $guards[] = [
                    $this->translateCondition($statement->cond),
                    strtolower($returned->name->toString()) === 'true' ? 'true' : 'false',
                ];

                continue;
            }

            if ($statement instanceof Return_ && $statement->expr instanceof Expr) {
                // A predicate helper that ends `return false;` is the common closing line, and a bare
                // boolean is not a condition to translate — it is the answer.
                $literal = $this->isBooleanLiteral($statement->expr);
                $final = $literal ?? $this->translateCondition($statement->expr);

                continue;
            }

            if ($statement instanceof Foreach_) {
                // The polarity is the loop's own: a search returns `true` on a hit, and a "nothing disqualifies
                // it" check returns `false` on one and `true` after the loop. Both are "any of them matches";
                // what differs is the answer, so reading it here is what keeps the second from inverting.
                $guards[] = [$this->foreachAsAny($statement), $this->loopMatchValue($statement)];

                continue;
            }

            if ($statement instanceof If_ && $statement->elseifs === [] && ! $statement->else instanceof Else_) {
                $nested = $this->nestedGuards($statement);
                if ($nested !== null) {
                    foreach ($nested as $guard) {
                        $guards[] = $guard;
                    }

                    continue;
                }
            }

            // Named, because a line number alone does not say *which* helper: two rules refused at "line 50"
            // and the file was a different one each time, which cost a reader a wrong conclusion about what
            // the refusal was asking for.
            throw new Refusal(sprintf(
                'statement in %s() outside the vocabulary: %s',
                $method->name->toString(),
                $statement instanceof If_ ? $this->ifShape($statement) : $this->describe($statement),
            ), $statement->getStartLine());
        }

        if ($final === null) {
            throw new Refusal('inlined helper does not return a value', $line);
        }

        $expression = $final;
        foreach (array_reverse($guards) as [$condition, $value]) {
            $expression = $this->context->backend->conditional($condition, $value, $expression);
        }

        return '(' . $expression . ')';
    }

    /** `(bool) $tags` — a list used as a condition, which means "there is at least one". */
    private function boolCast(Bool_ $expr): string
    {
        $subject = $this->resolve($expr->expr, $expr->getStartLine());
        if ($subject['kind'] !== 'docblock-tags') {
            throw new Refusal("a bool cast of a {$subject['kind']}", $expr->getStartLine());
        }

        return $this->operand($subject) . ' !== []';
    }

    /**
     * A declaration as the part the docblock helpers navigate, whatever it arrived as.
     *
     * @param Descriptor $subject
     *
     * @return Descriptor
     */
    private function asDeclarationPart(array $subject): array
    {
        if ($subject['kind'] !== 'hook-node') {
            return $subject;
        }

        return ['rust' => self::PHP_ONLY, 'kind' => 'method-decl', 'php' => 'Support::asPart($context, $node)'];
    }

    /**
     * A collaborator call a runtime helper answers, or null when this is not one.
     *
     * {@see Vocabulary::COLLABORATOR_CALLS} says which, and the helper takes the file's CST plus whatever the
     * call was handed — the analyzer it stands in for reads only syntax, so that is everything it needs.
     *
     * @return Descriptor|null
     */
    private function resolveCollaboratorCall(MethodCall $expr, int $line): ?array
    {
        if (Transpiler::$target !== 'php') {
            return null;
        }

        $method = $this->memberName($expr->name, $line);

        // On a collaborator property, or on the rule itself. `$this->resolveFunctionName(…)` is the same
        // question one owner along, and keying both on the declaring class means one table rather than two.
        $declaring = $this->collaboratorClass($expr->var, $line)
            ?? ($this->isThis($expr->var) ? $this->declaringOf($method) : null);
        if ($declaring === null) {
            return null;
        }

        $entry = Vocabulary::COLLABORATOR_CALLS[$this->fullyQualified($declaring) . '::' . $method] ?? null;
        if ($entry === null) {
            return null;
        }

        $arguments = match ($entry['takes']) {
            'context' => ['$context'],
            // A ported helper that navigates from the node the hook fired for rather than over the whole
            // file: the scope questions PHPStan answers off `$scope` are answered here by walking up from
            // the node, so the helper needs both.
            'context-node' => ['$context', '$node'],
            'none' => [],
            default => ['$context->source'],
        };
        $args = $expr->getArgs();
        foreach ($entry['arguments'] as $position) {
            $arguments[] = $this->operand($this->resolve($this->collaboratorArgument($args, $position, $method, $line), $line));
        }

        // An argument the helper reads as a *type* rather than as the expression itself. Asked for by node
        // through `ExpressionTypes`, the same route `$scope->getType(<expr>)` takes, so the two cannot drift.
        foreach ($entry['types'] ?? [] as $position) {
            $this->context->usesExpressionTypes = true;
            $of = $this->resolve($this->collaboratorArgument($args, $position, $method, $line), $line);
            $arguments[] = 'Support::expressionType($context, ' . $this->operand($of) . ')';
            $this->context->runtimeHelpers['Support'] = true;
        }

        // A capability the ported helper needs of the analysis. Opt-in, like every other requirement: unset,
        // the helper reads a null receiver type and answers the same thing for every call.
        if ($entry['receiverType'] ?? false) {
            $this->context->usesReceiverType = true;
        }

        // A container parameter the ported helper's answer depends on. Declared as a configured value so the
        // emitted plugin takes it in its constructor at PHPStan's own default, which is what lets a consumer
        // pass the value its own project runs rather than inherit whichever corpus was measured last.
        foreach ($entry['flags'] ?? [] as $flag) {
            $this->context->configured[$flag] = ['parameter' => $flag, 'kind' => 'config-bool', 'default' => false];
            $this->context->usesConfiguration = true;
            $arguments[] = '$this->' . $flag;
        }

        // A helper that reports for itself needs the identifier the original reports under, and that is read
        // out of the collaborator rather than named here: the message and the identifier are the two things a
        // reader checks a port against, and a table holding either would drift from the package silently.
        if ($entry['kind'] === 'reports') {
            $identifier = $this->reportedIdentifierIn($declaring['class'], $line);
            $arguments[] = $this->context->backend->bytes($identifier);
            $this->context->identifiers[] = $identifier;
        }

        $call = $entry['helper'] . '(' . implode(', ', $arguments) . ')';
        $this->context->runtimeHelpers[explode('::', $entry['helper'])[0]] = true;

        return ['rust' => self::PHP_ONLY, 'kind' => $entry['kind'], 'php' => $call];
    }

    /**
     * One argument of a collaborator call, refused by position rather than resolved to something else.
     *
     * @param array<Arg> $args
     */
    private function collaboratorArgument(array $args, int $position, string $method, int $line): Expr
    {
        $argument = $args[$position] ?? null;
        if (! $argument instanceof Arg) {
            throw new Refusal("{$method}() has no argument {$position} for its runtime helper", $line);
        }

        return $argument->value;
    }

    /** Whether an expression is the bare `$this`. */
    private function isThis(Expr $expr): bool
    {
        return $expr instanceof Variable && $expr->name === 'this';
    }

    /** The descriptor kind a configured default belongs to, in one place so the two callers cannot drift. */
    public function configKind(mixed $default): string
    {
        return match (true) {
            is_array($default) => 'config-list',
            is_bool($default) => 'config-bool',
            is_int($default), is_float($default) => 'config-number',
            default => 'config-bytes',
        };
    }

    /**
     * The first of a getter's parameter paths the package actually declares, as a configured value.
     *
     * A getter may name more than one, in fallback order — `$this->parameters['param'] ??
     * $this->parameters['param_type']` is an alias, and the package declares one of the two. Taking the first
     * declared is what {@see ConfigurationObject} documents; naming none is a refusal, because a plugin
     * carrying a value nobody declared would be a guess.
     *
     * @param list<string> $paths
     *
     * @return Descriptor
     */
    private function configuredFromPath(array $paths, string $getter, int $line): array
    {
        $configuration = PackageConfiguration::forRuleFile($this->file);
        foreach ($paths as $path) {
            if (! $configuration instanceof PackageConfiguration || ! $configuration->hasParameter($path)) {
                continue;
            }

            $segments = explode('.', $path);
            $property = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', end($segments)))));
            $default = $configuration->defaultFor($path);
            $this->context->configured[$property] ??= [
                'parameter' => $path,
                'default' => $default,
                'kind' => $this->configKind($default),
            ];

            $this->context->usesConfiguration = true;

            return [
                'rust' => 'self.' . Emitter::snake($property),
                'kind' => $this->context->configured[$property]['kind'],
                'php' => '$this->' . $property,
            ];
        }

        throw new Refusal(
            "{$getter}() reads " . implode(' or ', $paths) . ", which this package's neon does not declare",
            $line,
        );
    }

    /**
     * A getter on a configuration value object, resolved to the neon parameter it reads.
     *
     * The plugin then carries that parameter as a constructor default, exactly as a directly configured value
     * would be — so the value object never exists at analysis time and its getters never need translating.
     *
     * @return Descriptor|null
     */
    private function resolveValueObjectGetter(MethodCall $expr, string $method, int $line): ?array
    {
        if (! $expr->var instanceof PropertyFetch
            || ! $expr->var->var instanceof Variable
            || $expr->var->var->name !== 'this'
        ) {
            return null;
        }

        $valueObject = $this->context->valueObjects[$this->memberName($expr->var->name, $line)] ?? null;
        if (! $valueObject instanceof ConfigurationObject) {
            return null;
        }

        $paths = $valueObject->pathsFor($method);
        if ($paths === []) {
            return $this->resolveDerivedGetter($valueObject, $method, $line);
        }

        return $this->configuredFromPath($paths, $method, $line);
    }

    /**
     * A configuration getter that reads no parameter of its own, but asks a question about one that does.
     *
     * `isDependencyTreeEnabled()` is `getDependencyTreeTypes() !== []`. The old refusal said the plugin has
     * nothing to carry for it, which read like a vocabulary gap. It is not one: the parameter behind it is
     * carried already, so the question is answerable at analysis time from the value the plugin holds.
     *
     * Answering it *at transpile time* instead — reading the package default and folding the getter to `true`
     * or `false` — would be wrong, not merely lazy: the plugin takes that parameter as a constructor argument
     * and a consumer's worker overrides it, so a constant baked in here answers for a configuration the plugin
     * may never run under. So the comparison is emitted, and stays a comparison.
     *
     * @return Descriptor
     */
    private function resolveDerivedGetter(ConfigurationObject $valueObject, string $method, int $line): array
    {
        $emptiness = $valueObject->emptinessFor($method);
        if ($emptiness === null) {
            throw new Refusal(
                "{$method}() on this package's configuration reads no parameter, so there is nothing for the "
                . 'plugin to carry',
                $line,
            );
        }

        $paths = $valueObject->pathsFor($emptiness['getter']);
        if ($paths === []) {
            throw new Refusal(
                "{$method}() asks whether {$emptiness['getter']}() is empty, and that getter reads no "
                . 'parameter either, so nothing decides the answer',
                $line,
            );
        }

        $subject = $this->configuredFromPath($paths, $emptiness['getter'], $line);
        if ($subject['kind'] !== 'config-list') {
            throw new Refusal(
                "{$method}() compares {$emptiness['getter']}() against an empty array, and that getter reads a "
                . 'configured value this package does not declare as a list, so the comparison would not mean '
                . 'what it says',
                $line,
            );
        }

        $negation = $emptiness['expects'] === 'empty' ? '' : '!';
        $descriptor = ['rust' => $negation . $subject['rust'] . '.is_empty()', 'kind' => 'bool'];
        if (isset($subject['php'])) {
            $descriptor['php'] = $subject['php'] . ($emptiness['expects'] === 'empty' ? ' === []' : ' !== []');
        }

        return $descriptor;
    }

    /**
     * The class behind `$this-><property>`, when that property holds a collaborator this package can read.
     *
     * @return Declaration|null
     */
    private function collaboratorClass(Expr $receiver, int $line): ?array
    {
        if (! $receiver instanceof PropertyFetch
            || ! $receiver->var instanceof Variable
            || $receiver->var->name !== 'this'
        ) {
            return null;
        }

        $property = $this->memberName($receiver->name, $line);
        $type = $this->context->collaborators[$property] ?? null;

        return $type === null ? null : $this->findClassByName($type);
    }

    /**
     * `$classReflection->getTraits(true)` — every trait the declaration picks up.
     *
     * The `true` asks PHPStan to include traits reached through a parent class or through another trait. Mago's
     * `usedTraits` already answers that: probed on a class using a trait that uses another, and on a subclass
     * using neither itself, and both listed both. So there is no walk to write, and the flag is what the field
     * already means rather than something to reproduce.
     *
     * Metadata lowercases the names, so every comparison against one folds case.
     *
     * @return Descriptor|null
     */
    private function resolveUsedTraitNames(Expr $expr, int $line): ?array
    {
        if (! $expr instanceof MethodCall
            || $this->memberName($expr->name, $expr->getStartLine()) !== 'getTraits'
        ) {
            return null;
        }

        $base = $this->resolve($expr->var, $line);
        if ($base['kind'] !== 'class-reflection') {
            throw new Refusal('getTraits() on something other than a class reflection', $line);
        }

        // Without the flag the question is "traits written on this declaration", which `usedTraits` does not
        // answer: it is already transitive, so answering with it would be wider than the rule.
        $arguments = $expr->getArgs();
        if (count($arguments) !== 1 || $this->isBooleanLiteral($arguments[0]->value) !== 'true') {
            throw new Refusal('getTraits() without the flag that asks for inherited traits too', $line);
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('the traits a declaration uses, which only the PHP target carries', $line);
        }

        return ['rust' => self::PHP_ONLY, 'kind' => 'class-names', 'php' => 'Support::usedTraitNames($context, $node)'];
    }

    /**
     * `$classReflection->getParentClassesNames()` — the ancestry a rule walks.
     *
     * @return Descriptor|null
     */
    private function resolveParentClassNames(Expr $expr, int $line): ?array
    {
        if (! $expr instanceof MethodCall
            || $this->memberName($expr->name, $expr->getStartLine()) !== 'getParentClassesNames'
            || $expr->args !== []
        ) {
            return null;
        }

        $base = $this->resolve($expr->var, $line);
        if ($base['kind'] !== 'class-reflection') {
            throw new Refusal('getParentClassesNames() on something other than a class reflection', $line);
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('the parent class names, which only the PHP target carries', $line);
        }

        return ['rust' => self::PHP_ONLY, 'kind' => 'class-names', 'php' => 'Support::parentClassNames($context, $node)'];
    }

    /**
     * `$classLike->getMethod($name)` — php-parser's own lookup in a class body.
     *
     * Not the same call as `getMethod()` on a reflection: this one hands back the declaration as written, and
     * null when the class declares no such method. Null here when the expression is something else, so the
     * caller keeps looking.
     *
     * @return Descriptor|null
     */
    private function resolveMethodLookup(Expr $expr, int $line): ?array
    {
        if (! $expr instanceof MethodCall
            || $this->memberName($expr->name, $expr->getStartLine()) !== 'getMethod'
            || count($expr->getArgs()) !== 1
        ) {
            return null;
        }

        $base = $this->resolve($expr->var, $line);
        if ($base['kind'] !== 'hook-node' || ! in_array($this->context->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)) {
            return null;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('a method looked up by name, which only the PHP target carries', $line);
        }

        $named = $this->resolve($expr->getArgs()[0]->value, $line);
        if (! in_array($named['kind'], ['bytes', 'message'], true)) {
            throw new Refusal("getMethod() of a {$named['kind']}", $line);
        }

        return [
            'rust' => self::PHP_ONLY,
            // Not plain `method-decl`: a lookup by name answers null when the class declares no such method,
            // and `instanceof ClassMethod` on the result is the rule asking exactly that.
            'kind' => 'maybe-method-decl',
            'php' => 'Support::methodNamed($context, ' . $this->operand($base) . ', ' . $this->operand($named) . ')',
        ];
    }

    /**
     * Whether a branch returns a written string rather than a computed value.
     *
     * The recogniser has to be narrow: a helper returning `$firstItemType` also looks like a two-branch choice,
     * and rendering a *type* as a string is not a translation, it is a wrong answer.
     *
     * @phpstan-assert-if-true Expr $expr
     */
    private function isChoiceValue(?Node $expr): bool
    {
        return $expr instanceof String_
            || $expr instanceof InterpolatedString
            || $expr instanceof ClassConstFetch
            || ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toString() === 'sprintf');
    }

    private function choiceValue(Expr $expr): string
    {
        if ($expr instanceof String_) {
            return $this->context->backend->bytes($expr->value);
        }

        if ($expr instanceof InterpolatedString) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('an interpolated value, which only the PHP target carries', $expr->getStartLine());
            }

            $parts = [];
            foreach ($expr->parts as $part) {
                $parts[] = $part instanceof InterpolatedStringPart
                    ? $this->context->backend->bytes($part->value)
                    : $this->stringValue($part, $expr->getStartLine());
            }

            return '(' . implode(' . ', $parts) . ')';
        }

        return $this->translateMessageExpression($expr);
    }

    /**
     * `Helper::method(..)` in value position, inlined from the helper's own source.
     *
     * Null when the class is not one this package can find or the method is not on it, so the caller keeps
     * looking — a static call is also how `TypeCombinator` and friends are spelled, and those have their own
     * translations rather than a body to inline.
     *
     * @return Descriptor|null
     */
    private function inlineStaticProducer(StaticCall $expr, int $line): ?array
    {
        $method = $this->memberName($expr->name, $expr->getStartLine());
        $found = $expr->class instanceof Name ? $this->findClassByName($expr->class->getLast()) : null;
        if ($found === null) {
            return null;
        }

        try {
            $helper = $this->findMethod($found['class'], $method);
        } catch (Refusal) {
            return null;
        }

        return $this->inlineValueProducer($helper, $found, $method, $expr->getArgs(), $line);
    }

    /**
     * A helper that returns one of several string literals, folded into one expression.
     *
     * `resolveReflectionMethodVisibilityAsStrings()` is the shape: three `if (c) return '<word>';` and a
     * closing `return '<word>';`, which is a choice rather than a computation. Returns null when the body is
     * anything else, so the ordinary producer path still gets its chance — this is a recogniser, not a
     * fallback.
     *
     * @param array<Arg> $args
     *
     * @return array{rust: string, kind: string, php?: string}|null
     */
    private function translateMethodAsChoice(ClassMethod $helper, array $args, string $name, int $line): ?array
    {
        $statements = $helper->stmts ?? [];
        if ($statements === []) {
            return null;
        }

        /** @var list<array{Expr, Expr}> $guards */
        $guards = [];
        $final = null;
        $bindings = [];
        foreach ($statements as $statement) {
            // A local the branches read: `$firstArg = $node->getArgs()[0]->value;` before choosing on it.
            // Collected rather than bound here, because binding is a side effect and the shape is not settled
            // until every statement has been seen.
            if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                $bindings[] = $statement->expr;

                continue;
            }

            if ($statement instanceof If_
                && $statement->elseifs === []
                && ! $statement->else instanceof Else_
                && count($statement->stmts) === 1
                && $statement->stmts[0] instanceof Return_
                && $this->isChoiceValue($statement->stmts[0]->expr)
            ) {
                $guards[] = [$statement->cond, $statement->stmts[0]->expr];

                continue;
            }

            if ($statement instanceof Return_ && $this->isChoiceValue($statement->expr)) {
                $final = $statement->expr;

                continue;
            }

            return null;
        }

        if (! $final instanceof Expr || $guards === []) {
            return null;
        }

        // Translated only once the shape is settled, because translating a condition can inline another helper
        // and there is no undoing that if the body turns out not to be a choice after all.
        $savedLocals = $this->context->locals;
        $savedLiterals = $this->context->literals;
        $savedCaches = $this->context->caches;
        $this->context->locals = $this->bindParameters($helper, $args, $name, $line) + $this->context->locals;

        try {
            foreach ($bindings as $binding) {
                $this->bindLocal($binding, $line);
            }

            // Each branch is rendered the way a message is: a written word, a class constant, or a string built
            // from something read off the node. `requestHelperCallLabel()` returns `request('key')` or
            // `request(...)`, and the first of those is an interpolation.
            $expression = $this->choiceValue($final);
            foreach (array_reverse($guards) as [$condition, $value]) {
                $expression = $this->context->backend->conditional(
                    $this->translateCondition($condition),
                    $this->choiceValue($value),
                    $expression,
                );
            }
        } finally {
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
        }

        return ['rust' => '(' . $expression . ')', 'kind' => 'bytes', 'php' => '(' . $expression . ')'];
    }

    /**
     * The parsed class behind a name, with its own import list.
     *
     * The import list matters: a helper resolves `ClassReflection` or `SymfonyClass::COMMAND` through
     * the `use` statements of *its* file, not the calling rule's. Sharing the caller's map silently
     * resolved names to the wrong thing, or failed to resolve them at all.
     *
     * @return Declaration|null
     */
    private function findClassByName(string $shortName): ?array
    {
        return $this->context->index->find($shortName, $this->file);
    }

    /**
     * Whether this statement is a cache's own bookkeeping, recording it if so.
     *
     * The declaration and the keyed fill both are: neither says anything the emitted plugin has to do, and what
     * the cache stood for is settled by the fill and read back at each use.
     */
    private function takesACacheStatement(Stmt $statement): bool
    {
        if ($statement instanceof If_) {
            return $this->fillsACache($statement);
        }

        if (! $statement instanceof Static_) {
            return false;
        }

        $name = $this->emptyStaticCacheName($statement);
        if ($name === null) {
            throw new Refusal(
                'a static variable that is not an empty cache: a cache is dropped because it cannot change the '
                . 'answer, and a seeded one carries data that can',
                $statement->getStartLine(),
            );
        }

        $this->context->caches[$name] = ['rust' => self::PHP_ONLY, 'kind' => 'unfilled-cache', 'php' => self::PHP_ONLY];

        return true;
    }

    /**
     * The name a `static $x = [];` declares, or null when the statement is not that.
     *
     * One variable, initialised to an empty array. Two variables, or a seeded one, is not a cache this can drop.
     */
    private function emptyStaticCacheName(Static_ $statement): ?string
    {
        if (count($statement->vars) !== 1) {
            return null;
        }

        $variable = $statement->vars[0];
        if (! $variable->var instanceof Variable || ! is_string($variable->var->name)) {
            return null;
        }

        return $variable->default instanceof Array_ && $variable->default->items === []
            ? $variable->var->name
            : null;
    }

    /**
     * Whether this `if` fills a cache declared above it, recording what the cache stands for.
     *
     * `if (! array_key_exists($k, $cache)) { $cache[$k] = <expr>; }`, and the `try`/`catch` variant where the
     * computation can throw and the catch stores null — `DetectsFacadeAlias` writes the second. Both mean the
     * same thing to a reader of the cache, so both record the same expression; a computation that cannot be
     * translated then refuses at the expression, which is where the real obstacle is.
     */
    private function fillsACache(If_ $statement): bool
    {
        if ($statement->elseifs !== [] || $statement->else instanceof Else_ || count($statement->stmts) !== 1) {
            return false;
        }

        $cache = $this->cacheMissCondition($statement->cond);
        if ($cache === null) {
            return false;
        }

        $stored = $this->storedIntoCache($statement->stmts[0], $cache);
        if (! $stored instanceof Expr) {
            return false;
        }

        $this->context->caches[$cache] = $this->resolve($stored, $statement->getStartLine());

        return true;
    }

    /** `! array_key_exists($k, $cache)` where `$cache` was declared a cache above: the name, or null. */
    private function cacheMissCondition(Expr $condition): ?string
    {
        if (! $condition instanceof BooleanNot
            || ! $condition->expr instanceof FuncCall
            || ! $condition->expr->name instanceof Name
            || $condition->expr->name->toString() !== 'array_key_exists'
            || count($condition->expr->getArgs()) !== 2
        ) {
            return null;
        }

        $table = $condition->expr->getArgs()[1]->value;
        if (! $table instanceof Variable || ! is_string($table->name)) {
            return null;
        }

        return ($this->context->caches[$table->name]['kind'] ?? null) === 'unfilled-cache' ? $table->name : null;
    }

    /**
     * What a fill body stores into the cache, or null when it stores something else.
     *
     * Either one assignment, or a `try` storing the computation with a `catch` storing null. The catch is not
     * translated: a plugin has no throwing computation to catch, and where the computation itself cannot be
     * carried the refusal lands on it.
     */
    private function storedIntoCache(Stmt $body, string $cache): ?Expr
    {
        if ($body instanceof TryCatch) {
            return count($body->stmts) === 1 ? $this->storedIntoCache($body->stmts[0], $cache) : null;
        }

        if (! $body instanceof Expression
            || ! $body->expr instanceof Assign
            || ! $body->expr->var instanceof ArrayDimFetch
            || ! $body->expr->var->var instanceof Variable
            || $body->expr->var->var->name !== $cache
        ) {
            return null;
        }

        return $body->expr->expr;
    }

    /**
     * `if (COND) { return []; }`, or inside a loop `if (COND) { continue; }`, or in an error helper
     * `if (COND) { return <error>; }`.
     */
    private function translateIf(If_ $stmt): void
    {
        // `if (! array_key_exists($k, $cache)) { $cache[$k] = <expr>; }` — filling a cache declared above.
        // Nothing is emitted; the expression is resolved here, in the scope it was written in, and every later
        // read of `$cache[$k]` resolves to it.
        if ($this->fillsACache($stmt)) {
            return;
        }

        // A branch handling one of several node kinds the plugin registers for, with its own guards and its own
        // report. Its own method, so `return []` inside it declines that branch rather than the whole rule.
        if ($this->context->checkMode && $this->context->inlineDepth === 0 && $this->isBranchCheck($stmt)) {
            $this->translateBranchCheck($stmt);

            return;
        }

        // Inside an error helper the same `if (COND) { return [<error>]; }` is the condition to report *under*,
        // not a conditional report. A helper's return takes the message and emits nothing — the report comes
        // after its guards — so translating it as a block leaves an empty `if` and lets the next branch
        // overwrite the message. Asked before the block shape, and a no-op outside a helper because
        // {@see takeReportCondition} tests that first.
        if ($stmt->elseifs === [] && ! $stmt->else instanceof Else_ && count($stmt->stmts) === 1
            && $this->takeReportCondition($stmt, $stmt->stmts[0])
        ) {
            return;
        }

        // if (COND) { $message = ..; $errors[] = RuleErrorBuilder::..; }  — a conditional report rather than a
        // guard. A rule that reports two different things about the same subject writes one of these per
        // finding, and each carries its own message and identifier.
        if ($stmt->elseifs === [] && ! $stmt->else instanceof Else_ && $this->isConditionalReport($stmt->stmts)) {
            $this->translateConditionalReport($stmt);

            return;
        }

        // `if (COND) { $x = A; } else { $x = B; }` — one name bound two ways, which is a ternary written long.
        if ($this->bindConditionalValue($stmt)) {
            return;
        }

        if ($stmt->elseifs !== [] || count($stmt->stmts) !== 1
            || ($stmt->else instanceof Else_ && ! $this->isFlagAssignment($stmt->stmts[0]))
        ) {
            throw new Refusal('if statement that is not a single-statement guard, but ' . $this->guardBodyShape($stmt), $stmt->getStartLine());
        }

        $only = $stmt->stmts[0];

        // if (COND) { $flag = ..; } [else { $flag = ..; }]  — not a guard, a branch.
        if ($this->isFlagAssignment($only)) {
            $this->translateFlagBranch($stmt);

            return;
        }

        // In an error helper a guard that returns the error is the report condition, not an exit.
        if ($this->takeReportCondition($stmt, $only)) {
            return;
        }

        // `if ($e instanceof RuleError) { $errors[] = $e; }` where `$e` holds an error an inlined helper
        // already reported. The original is collecting what it will hand back; the plugin has nothing to
        // collect, so this is bookkeeping and translates to nothing.
        if ($this->isReportedErrorBookkeeping($stmt)) {
            return;
        }

        // `return $errors;` — an early return of the findings accumulated so far, which is what a rule that
        // checks several things in one pass writes when it decides to stop looking. Every one of those findings
        // was already reported where it was found, so this is the same exit as `return []`: the difference is
        // in what the *original* still has to hand back, and the emitted plugin hands back nothing.
        if ($this->isReturnAccumulator($stmt->stmts)) {
            $this->translateGuard($stmt->cond, $this->context->backend->bail());

            return;
        }

        // `return []` leaves the whole rule; `continue` only ends this iteration. Which one it
        // is comes from the guard's own body, not from whether we happen to be in a loop.
        if ($this->isReturnEmptyArray($stmt->stmts)) {
            $exit = $this->context->backend->bail();
        } elseif (($this->context->isCollector || $this->context->inErrorHelper) && $this->isReturnNull($stmt->stmts)) {
            // `return null` in an inlined helper means "no value", not "stop the rule" — but only when the
            // enclosing loop belongs to the caller. Then it is the current item's answer and the iteration
            // ends; the rule's own check on the produced value follows, so both agree on what null means. A
            // loop the helper opened itself is the other case, and leaving it has to leave the helper.
            $exit = $this->context->loopDepth > 0 && $this->context->loopDepth === $this->context->helperLoopFloor
                ? 'continue;'
                : $this->context->backend->bail();
        } elseif ($only instanceof Continue_ && ! $only->num instanceof Expr) {
            if (! $this->context->inLoop) {
                throw new Refusal('continue outside a loop', $stmt->getStartLine());
            }

            $exit = 'continue;';
        } else {
            // Says what the body *is*. "neither X nor Y" told a reader only what it is not, and the shape that
            // reaches here is usually a helper returning a value rather than a rule declining — a difference
            // the old message left them to find by opening the file.
            throw new Refusal(
                'guard body is neither `return []` nor `continue`, but ' . $this->describe($only),
                $stmt->getStartLine(),
            );
        }

        $this->translateGuard($stmt->cond, $exit);
    }

    /**
     * What an `if` this cannot translate actually is, in the terms someone would build against.
     *
     * The bare message was the largest cluster in the census — sixteen registered rules — and it is not one
     * capability. Six are the withdrawn arithmetic family; of the remaining ten, every body is two statements
     * with no else, and those two statements are four different things: a flag then `continue`, a binding
     * then `return`, a binding then a nested `if`, two bindings. The emitted handling differs for each, so a
     * reader counting the label was sizing four jobs as one.
     *
     * Named the way the sibling refusal names a guard body — "but Stmt_Return" — so the four are countable
     * by grepping the whole line, which is what the census header asks for and this message made impossible.
     *
     * Distinct from {@see ifShape()}, which answers the same question for an `if` inside an *inlined helper*,
     * where the shape that matters is whether the body exits rather than what its statements are.
     */
    private function guardBodyShape(If_ $stmt): string
    {
        if ($stmt->elseifs !== []) {
            return sprintf(
                'a chain of %d elseif%s',
                count($stmt->elseifs),
                $stmt->else instanceof Else_ ? ' and an else' : '',
            );
        }

        if ($stmt->else instanceof Else_ && count($stmt->stmts) === 1) {
            return 'one statement and an else: ' . $stmt->stmts[0]->getType();
        }

        $kinds = array_map(static fn (Stmt $inner): string => $inner->getType(), $stmt->stmts);

        return sprintf(
            '%d statements%s: %s',
            count($kinds),
            $stmt->else instanceof Else_ ? ' and an else' : '',
            implode(' + ', $kinds),
        );
    }

    /**
     * Whether an `if` only files an already-reported error into an accumulator.
     *
     * Both halves are checked: the condition has to be an `instanceof` on a local this transpiler knows was
     * reported, and the body has to append *that* local and nothing else. A looser match would silently drop
     * a guard that does something.
     */
    private function isReportedErrorBookkeeping(If_ $stmt): bool
    {
        $condition = $stmt->cond;
        if (! $condition instanceof Instanceof_
            || ! $condition->expr instanceof Variable
            || ! is_string($condition->expr->name)
            || ! isset($this->context->reportedErrors[$condition->expr->name])
            || count($stmt->stmts) !== 1
            || $stmt->elseifs !== []
            || $stmt->else instanceof Else_
        ) {
            return false;
        }

        $only = $stmt->stmts[0];

        return $only instanceof Expression
            && $only->expr instanceof Assign
            && $only->expr->var instanceof ArrayDimFetch
            && ! $only->expr->var->dim instanceof Expr
            && $only->expr->expr instanceof Variable
            && $only->expr->expr->name === $condition->expr->name;
    }

    /**
     * Whether a block is `return <a report accumulator>;`.
     *
     * Only a *report* accumulator: a list a rule built holds nodes, and returning one of those is a value the
     * caller reads rather than an exit.
     *
     * @param array<Stmt> $statements
     */
    private function isReturnAccumulator(array $statements): bool
    {
        if (count($statements) !== 1) {
            return false;
        }

        $only = $statements[0];

        return $only instanceof Return_
            && $only->expr instanceof Variable
            && is_string($only->expr->name)
            && ($this->context->locals[$only->expr->name]['kind'] ?? null) === 'accumulator'
            && ! isset($this->context->listAccumulators[$only->expr->name]);
    }

    /**
     * Whether a block binds some locals and then appends exactly one finding, as its last statement.
     *
     * @param array<Stmt> $statements
     */
    private function isConditionalReport(array $statements): bool
    {
        if ($statements === []) {
            return false;
        }

        foreach ($statements as $index => $statement) {
            $last = $index === count($statements) - 1;

            // The other way a block ends in one finding: `return [<error>];` rather than `$errors[] = <error>`.
            // A rule that reports at most one thing writes the first; one that collects several writes the
            // second. Nineteen rules across the installed packages write the first, and it was refused as a
            // guard body that is not `return []` — which named the statement rather than what it does.
            if ($last && $this->isSingleErrorReturn($statement)) {
                return true;
            }

            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                return false;
            }

            $appends = $statement->expr->var instanceof ArrayDimFetch
                && ! $statement->expr->var->dim instanceof Expr
                && $this->isRuleErrorBuilder($statement->expr->expr);

            if ($last !== $appends) {
                return false;
            }
        }

        return true;
    }

    /** `return [<one built error>];` — a block that reports and exits rather than collecting. */
    private function isSingleErrorReturn(Stmt $statement): bool
    {
        if (! $statement instanceof Return_ || ! $statement->expr instanceof Array_ || count($statement->expr->items) !== 1) {
            return false;
        }

        $only = $statement->expr->items[0];

        return $only instanceof ArrayItem && $this->isRuleErrorBuilder($only->value);
    }

    /** Emits `if (COND) { report(..); }`, with the block's own statements inside it. */
    private function translateConditionalReport(If_ $stmt): void
    {
        if (Transpiler::$target !== 'php') {
            throw new Refusal('a conditional report, which only the PHP target carries', $stmt->getStartLine());
        }

        $condition = $this->translateCondition($stmt->cond);
        $this->context->lines[] = new Stm('if-open', ['condition' => $condition], $this->context->indent);
        $this->context->indent += 4;

        $inConditionalReport = $this->context->inConditionalReport;
        $this->context->inConditionalReport = true;

        try {
            foreach ($stmt->stmts as $statement) {
                $this->translateStatement($statement);
            }
        } finally {
            $this->context->indent -= 4;
            $this->context->inConditionalReport = $inConditionalReport;
        }

        $this->context->lines[] = new Stm('block-close', [], $this->context->indent);
    }

    /**
     * `$this->something(...)`, with a plain method name.
     *
     * @phpstan-assert-if-true MethodCall&object{name: Identifier} $value
     */
    public function isOwnMethodCall(Expr $value): bool
    {
        return $value instanceof MethodCall
            && $value->var instanceof Variable
            && $value->var->name === 'this'
            && $value->name instanceof Identifier;
    }

    /**
     * `if (COND) { return <error>; }` inside an error helper: a condition to report under, not an exit.
     */
    private function takeReportCondition(If_ $stmt, Stmt $only): bool
    {
        $built = $this->context->inErrorHelper && $only instanceof Return_ && $only->expr instanceof Expr
            ? $this->returnedRuleError($only->expr)
            : null;
        if (! $built instanceof Expr) {
            return false;
        }

        // Read after taking the message, so each branch records the message and identifier *it* reports
        // under rather than whichever was taken last.
        $this->takeMessage($built);
        $this->context->reportConditions[] = [
            'condition' => $this->stripOuterParentheses($this->translateCondition($stmt->cond)),
            'message' => $this->reportedMessage(),
            'code' => $this->reportedCode(),
            'anchor' => $this->context->anchor ?? $this->emitter->defaultAnchor(),
        ];
        // The next branch is free to report something else: this one is now accounted for.
        $this->context->reportTaken = true;

        return true;
    }

    /**
     * The reports an inlined error helper's branches add up to.
     *
     * One shape when every branch reports the same thing: a single guard that bails unless one of the
     * conditions holds, and the rule's own trailing report says what. Another when they differ: a report per
     * branch, and no trailing one. The first is the dominant shape in the corpus and its emitted output is
     * unchanged by this method existing.
     */
    private function emitHelperReports(): bool
    {
        if ($this->context->reportConditions === []) {
            return false;
        }

        $reports = [];
        foreach ($this->context->reportConditions as $branch) {
            $reports[$branch['message'] . '|' . $branch['code'] . '|' . $branch['anchor']] = true;
        }

        // Outside check mode the rule appends one report of its own, so the helper only has to say when to
        // reach it. In check mode there is no trailing report to reach: a guard that bailed would leave the
        // check silent, which is the failure this whole mode exists to avoid.
        if (count($reports) === 1 && ! $this->context->checkMode) {
            // The helper reports when any of them holds, so the rule bails when none does.
            $this->context->lines[] = new Stm('guard', [
                'condition' => '!((' . implode(' || ', array_column($this->context->reportConditions, 'condition')) . '))',
                'exit' => $this->context->backend->bail(),
            ], $this->context->indent);

            return true;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('a helper reporting different findings per branch, which only the PHP target carries');
        }

        // Branches reporting the same finding collapse to one `if` over their disjunction, rather than the
        // same report written once per branch.
        $branches = count($reports) === 1
            ? [['condition' => implode(' || ', array_column($this->context->reportConditions, 'condition'))] + $this->context->reportConditions[0]]
            : $this->context->reportConditions;

        // Each of these branches `return`s in the original, so the fall-through below is only reached when
        // none of them held. Without the exit a name ending in `Trait` would report the trait finding here and
        // the interface finding after it — two findings where the rule gives one.
        $trailing = $this->context->helperTrailingReport;

        foreach ($branches as $branch) {
            $this->context->lines[] = new Stm('if-open', ['condition' => $branch['condition']], $this->context->indent);
            $this->context->indent += 4;
            $this->context->lines[] = new Stm('report', [
                'anchor' => $branch['anchor'],
                'message' => $branch['message'],
                'code' => $branch['code'],
            ], $this->context->indent);

            if ($trailing) {
                $this->context->lines[] = new Stm('bail', [], $this->context->indent);
            }

            $this->context->indent -= 4;
            $this->context->lines[] = new Stm('block-close', [], $this->context->indent);
        }

        // The helper's own last statement reports too, after every condition above it. Dropping it left the
        // plugin silent exactly where the original reports: `processInterfaceSuffix()` guards the trait
        // message and falls through to the interface one.
        if ($trailing) {
            $this->context->lines[] = $this->reportNode();
        }

        $this->context->reportedInline = true;

        return true;
    }

    /**
     * Refuses a rule that asks a *second* independent check in one pass.
     *
     * Each check's guards become the rule's guards, so the first one's "not my case" exits the rule and every
     * later check is unreachable — the merged rule would report only its first sub-rule and look complete doing
     * it. `hihaho/phpstan-rules` merges three rules per node kind exactly like that, for performance.
     *
     * Counted where a check *reports*, not where a helper is inlined: a helper that forwards to another, or
     * hands back a value, is one check however many methods it spans.
     *
     * Reached only outside check mode, where each check gets its own method and the flattening cannot happen —
     * see {@see openCheck()}. It still stands for the targets that carry no such mode.
     */
    private function refuseASecondCheck(int $line): void
    {
        if ($this->context->inlineDepth === 0 && $this->context->checksReported >= 1) {
            throw new Refusal(
                'a rule that asks several independent checks in one pass: flattening them would let the first '
                . "one's guards exit the rule, leaving the rest unreachable",
                $line,
            );
        }
    }

    /**
     * The interface an `implementsInterface()` asks about, as text.
     *
     * A written literal or a constant standing for one is folded here, the same way a method name is
     * {@see methodNameArgument}: `implementsInterface(SymfonyClass::EVENT_SUBSCRIBER_INTERFACE)` names a
     * class through a class of constants, and the value is known at transpile time exactly as a literal
     * would be. Without this the argument went through generic resolution, which refuses a string literal by
     * node kind — so both spellings a rule can write were refused and only a name read off the node worked.
     */
    private function interfaceNameArgument(Expr $expr, int $line): string
    {
        if ($expr instanceof String_ || $expr instanceof ClassConstFetch || $expr instanceof Concat) {
            return $this->bytesValue($expr, $line);
        }

        return $this->nameText($this->resolve($expr, $line), $line);
    }

    /**
     * A descriptor read as the string a name-taking `Support` helper expects.
     *
     * The name a rule hands `hasFunction()` is usually the call's own name *node*, and the helpers take the
     * text. Passing the node instead was a `TypeError` in the worker, which surfaces as an orchestrator
     * protocol error naming neither the rule nor the argument — so the reduction is done here, by kind.
     *
     * @param Descriptor $subject
     */
    private function nameText(array $subject, int $line): string
    {
        return match ($subject['kind']) {
            'bytes', 'class-name', 'config-bytes', 'resolved-name' => $this->operand($subject),
            'local-name', 'name-selector', 'name-expr' => $this->context->backend->call('text_of', [$this->operand($subject)]),
            default => throw new Refusal("cannot read a {$subject['kind']} as a name", $line),
        };
    }

    /** A branch whose body is a guard chain ending in a built rule error, which is a check. */
    public function isBranchCheck(If_ $statement): bool
    {
        if ($statement->elseifs !== [] || $statement->else instanceof Else_) {
            return false;
        }

        if ($this->delegatedCheck($statement) instanceof MethodCall) {
            return true;
        }

        if (count($statement->stmts) < 2) {
            return false;
        }

        $last = $statement->stmts[count($statement->stmts) - 1];
        if (! $last instanceof Return_ || ! $last->expr instanceof Array_ || count($last->expr->items) !== 1) {
            return false;
        }

        $returned = $last->expr->items[0]->value ?? null;

        // The error is built into a local first — `$ruleError = RuleErrorBuilder::..` — and returned wrapped.
        // Anything else returned from a branch is not a finding this can place.
        return $returned instanceof Variable
            && is_string($returned->name)
            && $this->buildsErrorInto($statement->stmts, $returned->name);
    }

    /**
     * The helper call a branch hands its whole case to, or null when the branch is not one.
     *
     * `if ($node instanceof Interface_) { return $this->processInterfaceSuffix($node->name); }` — the branch
     * says which case this is and the helper decides it. That is the same check as a branch that guards and
     * builds inline; the difference is only where the guards are written, and inlining puts them back.
     *
     * The helper has to build a rule error. A branch returning any other call is a value the rule does
     * something else with, and treating it as a check would report where the rule does not.
     */
    private function delegatedCheck(If_ $statement): ?MethodCall
    {
        if (count($statement->stmts) !== 1) {
            return null;
        }

        $only = $statement->stmts[0];
        if (! $only instanceof Return_
            || ! $only->expr instanceof MethodCall
            || ! $this->isOwnMethodCall($only->expr)
            || ! $only->expr->name instanceof Identifier
        ) {
            return null;
        }

        $declaring = $this->declaringOf($only->expr->name->toString());

        return $declaring !== null
            && $this->buildsRuleError($this->findMethod($declaring['class'], $only->expr->name->toString()))
            ? $only->expr
            : null;
    }

    /**
     * Whether one of these statements assigns a built rule error to that name.
     *
     * @param array<Stmt> $statements
     */
    private function buildsErrorInto(array $statements, string $name): bool
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Expression
                && $statement->expr instanceof Assign
                && $statement->expr->var instanceof Variable
                && $statement->expr->var->name === $name
                && $this->isRuleErrorBuilder($statement->expr->expr)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A branch check, as its own method on the plugin.
     *
     * The branch's condition becomes the check's first guard, negated: it says which case this is, so anything
     * else declines. The statements after it are ordinary rule-body statements — guards, bindings, the built
     * error — and the report lands where {@see finishCheck} puts it.
     */
    private function translateBranchCheck(If_ $statement): void
    {
        // A branch that hands its whole case to a helper. The helper's own guards and its built error are what
        // this check is made of, so inlining it *is* the translation — {@see inlineErrorHelper} already turns
        // a helper's guards into the rule's guards and its returned error into the report, and it names the
        // check after the helper, which reads better than a branch number.
        $delegated = $this->delegatedCheck($statement);
        if ($delegated instanceof MethodCall && $delegated->name instanceof Identifier) {
            $this->inlineErrorHelper(
                $delegated->name->toString(),
                $delegated->getArgs(),
                $statement->getStartLine(),
                null,
                '!(' . $this->stripOuterParentheses($this->translateCondition($statement->cond)) . ')',
            );

            return;
        }

        $checkStart = count($this->context->lines);

        $this->context->lines[] = new Stm('guard', [
            'condition' => '!(' . $this->stripOuterParentheses($this->translateCondition($statement->cond)) . ')',
            'exit' => $this->context->backend->bail(),
        ], $this->context->indent);

        foreach ($statement->stmts as $inner) {
            $this->translateStatement($inner);
        }

        $this->context->lines[] = $this->reportNode();
        $this->closeCheck($checkStart, $this->branchCheckName($statement), $this->context->locals);
        $this->context->reportTaken = true;
    }

    /**
     * What to call a branch check, taken from the first node kind its condition names.
     *
     * A branch has no method name to borrow, and numbering them says nothing. The kind does: a reader of the
     * emitted plugin sees `checkClassConstantAccess` and knows which of the registered targets it handles.
     */
    private function branchCheckName(If_ $statement): string
    {
        foreach ((new NodeFinder())->findInstanceOf([$statement->cond], Instanceof_::class) as $test) {
            if (! $test->class instanceof Name) {
                continue;
            }

            $kind = Vocabulary::EXPRESSION_KINDS[$this->resolveClassName($test->class)] ?? null;
            if ($kind !== null) {
                return $kind;
            }
        }

        return 'branch' . (count($this->context->checks) + 1);
    }

    /**
     * Where this check's statements begin, or null when the rule is not emitted per check.
     *
     * In check mode each check gets its own method, so the flattening {@see refuseASecondCheck} guards against
     * cannot happen and the refusal does not apply. {@see closeCheck} moves the statements out once the helper
     * has reported; a helper that turns out to bind a value rather than report leaves the mark unused.
     */
    private function openCheck(int $line): ?int
    {
        if ($this->context->checkMode && $this->context->inlineDepth === 0) {
            return count($this->context->lines);
        }

        $this->refuseASecondCheck($line);

        return null;
    }

    /**
     * Emit whatever an inlined helper decided, and move it into its own method when in check mode.
     *
     * @param int|null                            $checkStart where this check's statements begin, or null outside check mode
     * @param array<string, array<string, mixed>> $available  the locals bound at the call site
     */
    private function finishCheck(?int $checkStart, string $method, array $available): void
    {
        $reported = $this->emitHelperReports();
        if ($reported && $this->context->inlineDepth === 1) {
            // Depth 1 is the outermost inline: the check whose guards land in the rule body.
            ++$this->context->checksReported;
        }

        if ($checkStart === null) {
            return;
        }

        // A helper that took a message rather than collecting conditions reports once, after its guards.
        // Outside check mode the rule appends that report itself; here it belongs to the check, because the
        // check is what the guards above it decline.
        if (! $reported) {
            $this->context->lines[] = $this->reportNode();
        }

        $this->closeCheck($checkStart, $method, $available);
    }

    /**
     * Move the statements one check emitted out of the rule body and into a private method of the plugin.
     *
     * The point of the method is its `return`: a guard inside it declines *this* check, where the same guard
     * in the rule body would decline every check after it too. The statements are already rendered at the
     * body's indentation, which a method body shares, so they move across unchanged.
     *
     * The method takes whatever locals the rule had bound and the check's statements name — the prologue's
     * work, done once and passed in rather than repeated per check. They are typed `mixed`: the transpiler
     * tracks each local's shape well enough to render it, not well enough to name a PHP type for it, and a
     * guessed type is a `TypeError` at analysis time rather than a refusal here.
     *
     * @param array<string, array<string, mixed>> $available the locals bound at the call site
     */
    private function closeCheck(int $from, string $method, array $available): void
    {
        $body = '';
        foreach (array_splice($this->context->lines, $from) as $statement) {
            $body .= $this->context->backend->render($statement);
        }

        $parameters = ['NodeAnalysisContext $context'];
        $arguments = ['$context'];
        foreach ($this->checkSubjects($available) as $variable) {
            if (preg_match('/(?<![\w$])' . preg_quote($variable, '/') . '\b/', $body) !== 1) {
                continue;
            }

            $parameters[] = 'mixed ' . $variable;
            $arguments[] = $variable;
        }

        $name = 'check' . ucfirst((string) preg_replace('/[^A-Za-z0-9]/', '', $method));

        $this->context->checks[] = [
            'name' => $name,
            'signature' => implode(', ', $parameters),
            'body' => $body,
        ];

        $this->context->lines[] = new Stm('check-call', [
            'name' => $name,
            'arguments' => implode(', ', $arguments),
        ], $this->context->indent);

        // Every check reports for itself, so the rule has no trailing report to make.
        $this->context->reportedInline = true;
    }

    /**
     * The variables a check method can be handed, in the order they were bound.
     *
     * The hook node first, then the rule's own locals — but only those that are a plain variable. A local
     * bound to an expression is not something a parameter can carry, and one is not needed: the check's
     * statements name the variable or they do not.
     *
     * @param array<string, array<string, mixed>> $available
     *
     * @return list<string>
     */
    private function checkSubjects(array $available): array
    {
        $subjects = ['$node'];
        foreach ($available as $local) {
            // A descriptor carries a PHP rendering only where one was needed. Absent is the same answer as
            // "not a plain variable": there is nothing a parameter could carry.
            $php = $local['php'] ?? null;
            if (is_string($php) && preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $php) === 1) {
                $subjects[] = $php;
            }
        }

        return array_values(array_unique($subjects));
    }

    private function translateErrorHelperReturn(Return_ $stmt): void
    {
        // Wrapped or bare. A helper typed `list<IdentifierRuleError>` returns the one it built inside an
        // array, and reading only the bare spelling made that look like a helper returning something else.
        $built = $stmt->expr instanceof Expr ? $this->returnedRuleError($stmt->expr) : null;
        if ($built instanceof Expr) {
            $this->takeMessage($built);
            $this->context->helperTrailingReport = true;

            return;
        }

        // A record producer's terminal statement is the record itself, or a further producer it hands off to.
        if ($this->context->recordFields !== null && $stmt->expr instanceof Array_) {
            $this->bindRecordFields($stmt->expr);

            return;
        }

        if ($this->context->recordFields !== null
            && $stmt->expr instanceof MethodCall
            && $this->isOwnMethodCall($stmt->expr)
        ) {
            $this->context->recordFields = $this->inlineRecordProducer($stmt->expr, $stmt->getStartLine());

            return;
        }

        // A producer that hands back the accumulator it folded records into. The fields are already runtime
        // locals — that is what {@see foldRecordInLoop} materialised them for — so the record passes through
        // as itself rather than being resolved to one value.
        if ($this->context->recordFields !== null
            && $stmt->expr instanceof Variable
            && is_string($stmt->expr->name)
            && ($this->context->locals[$stmt->expr->name]['kind'] ?? null) === 'record'
        ) {
            $this->context->recordFields = $this->context->locals[$stmt->expr->name]['record'] ?? [];

            return;
        }

        // A producer of one value rather than a record — `lastBareFlagIndex()` hands back an index — binds
        // under the empty key, which {@see inlineValueProducer} unwraps. Same machinery either way: the guards
        // are the rule's guards and the terminal return is a transpile-time binding.
        if ($this->context->recordFields !== null && $stmt->expr instanceof Expr && ! $this->isNullConstant($stmt->expr)) {
            $this->context->recordFields = ['' => $this->recordField($this->resolve($stmt->expr, $stmt->getStartLine()))];

            return;
        }

        // A trailing `return null` is "no finding". When guards have already collected the conditions
        // under which the helper *does* report, it is the fall-through of those, and emitting a bail
        // here would put an unconditional exit in front of the report.
        //
        // `return []` says the same thing in a helper typed `list<IdentifierRuleError>`, which is the
        // spelling PHPStan's own signature forces. Reading only `null` made the empty list look like a
        // helper returning something else.
        if (! $this->isReturnNull([$stmt]) && ! $this->isReturnEmptyArray([$stmt])) {
            throw new Refusal('an error helper returns something other than null or a built rule error', $stmt->getStartLine());
        }

        if ($this->context->reportConditions === []) {
            $this->context->lines[] = new Stm('bail', [], $this->context->indent);
        }
    }

    /**
     * Hand one check to a whole-project pass, when its question is one no node hook can answer.
     *
     * The check is not translated: {@see Vocabulary::CROSS_FILE_CHECKS} names the pass that answers it, the
     * same way {@see Vocabulary::AGGREGATES} names the metric a collector contributes to. Everything the
     * rule's own source *can* supply still comes from it — the argument expressions are resolved by the
     * ordinary resolver, and the identifier is read out of the class that builds the finding.
     *
     * Only on the PHP target. The Rust targets have no equivalent pass, and falling through leaves them with
     * the refusal that names the real obstacle rather than a silently missing check.
     *
     * @param array<Arg> $args
     *
     * @return bool whether the check was taken, so the caller emits nothing for it
     */
    private function takeCrossFileCheck(string $method, array $args, int $line): bool
    {
        if (Transpiler::$target !== 'php' || ! $this->context->currentClass instanceof ClassLike) {
            return false;
        }

        $declaring = $this->declaringOf($method);
        if ($declaring === null) {
            return false;
        }

        $entry = Vocabulary::CROSS_FILE_CHECKS[$this->fullyQualified($declaring) . '::' . $method] ?? null;
        if ($entry === null) {
            return false;
        }

        $identifier = $this->reportedIdentifierIn($declaring['class'], $line);
        $arguments = ['$context'];
        foreach ($entry['arguments'] as $position) {
            $argument = $args[$position] ?? null;
            if (! $argument instanceof Arg) {
                throw new Refusal("{$method}() has no argument {$position} for its whole-project pass", $line);
            }

            $arguments[] = $this->operand($this->resolve($argument->value, $line));
        }

        $arguments[] = $this->context->backend->bytes($identifier);
        $this->context->afterChecks[] = $entry['pass'] . '(' . implode(', ', $arguments) . ')';
        $this->context->identifiers[] = $identifier;

        return true;
    }

    /**
     * Emit a collaborator call that reports for itself, where the rule hands it the whole decision.
     *
     * `Vocabulary::COLLABORATOR_CALLS` marks such an entry `reports`: the collaborator decides *and* builds
     * the findings, so there is no message to take and no question to turn into a guard. The call is emitted
     * where the rule made it, with every guard above it already translated from the rule's own source — which
     * is what keeps the rule's own reading of "whose docblock, and for which classes" rather than moving it
     * into the pass.
     *
     * The Rust targets fall through: `resolveCollaboratorCall()` answers null for them, so they keep the
     * refusal that names the real obstacle instead of quietly losing the check.
     *
     * @return bool whether the pass was emitted, so the caller stops here
     */
    private function takeReportingPass(MethodCall $call): bool
    {
        $pass = $this->resolveCollaboratorCall($call, $call->getStartLine());
        if (($pass['kind'] ?? null) !== 'reports' || ! is_string($pass['php'] ?? null)) {
            return false;
        }

        $this->context->lines[] = new Stm('pass-call', ['call' => $pass['php']], $this->context->indent);
        $this->context->reportedInline = true;
        $this->context->reportsThroughPass = true;

        return true;
    }

    /**
     * The fully qualified name of a class-like the hierarchy resolved a method to.
     *
     * This transpiler does not run php-parser's `NameResolver`, so a `ClassLike` node carries no
     * `namespacedName`; the namespace travels alongside it instead, read from the file that declared it. A
     * table keyed on the qualified name cannot be matched by a same-named class in another package, which a
     * short name plus a path check only made unlikely.
     *
     * @param Declaration $declaring
     */
    private function fullyQualified(array $declaring): string
    {
        $name = (string) $declaring['class']->name;
        $namespace = $declaring['namespace'];

        return $namespace === null ? $name : $namespace . '\\' . $name;
    }

    /**
     * The single identifier a class builds its findings under.
     *
     * Read rather than tabulated, so an upstream rename flows through instead of being carried in this
     * repository. More than one means the class reports several different things and nothing here says which
     * this check is, so it is refused rather than guessed.
     */
    private function reportedIdentifierIn(ClassLike $class, int $line): string
    {
        $found = [];
        foreach ((new NodeFinder())->findInstanceOf([$class], MethodCall::class) as $call) {
            if ($this->memberName($call->name, $call->getStartLine()) !== 'identifier') {
                continue;
            }

            $argument = $call->getArgs()[0] ?? null;
            if ($argument instanceof Arg && $argument->value instanceof String_) {
                $found[] = $argument->value->value;
            }
        }

        $found = array_values(array_unique($found));
        if (count($found) !== 1) {
            throw new Refusal(sprintf(
                'the class behind this check reports under %d identifiers, so which one it uses is not readable',
                count($found),
            ), $line);
        }

        return $found[0];
    }

    /**
     * Inline a helper whose return value *is* the finding, in statement position.
     *
     * The dominant shape in a real rule package. The rule is a shim:
     *
     *     $error = $this->funcDebugError($node->name->name, $scope);
     *     return $error instanceof IdentifierRuleError ? [$error] : [];
     *
     * and the helper decides *and* builds the message, returning `?RuleError`. This differs from
     * {@see inlineOwnHelper} in what it produces: that one yields a single expression for use inside a
     * condition, which cannot express a helper that reports. Here the helper's guards become the rule's
     * guards and its returned error becomes the rule's message, which is the emitted shape already — a
     * chain of guards followed by one report.
     *
     * The forwarding `return $error instanceof ... ? [$error] : []` needs no translation: by the time it
     * is reached every guard has been emitted and the message taken.
     *
     * @param array<Arg> $args
     */
    private function inlineErrorHelper(string $method, array $args, int $line, ?Expr $target = null, ?string $branchGuard = null): void
    {
        // Remembered so the bookkeeping the original does with the returned error can be dropped: by the time
        // this returns, whatever the helper decided has already been reported.
        if ($target instanceof Variable && is_string($target->name)) {
            $this->context->reportedErrors[$target->name] = true;
        }

        if ($this->takeCrossFileCheck($method, $args, $line)) {
            if ($branchGuard !== null) {
                throw new Refusal(
                    "{$method}() is a cross-file check reached from a branch, so the branch's own condition "
                    . 'would have nowhere to go: a cross-file check runs after every file and the condition '
                    . 'names the node this one fired for',
                    $line,
                );
            }

            return;
        }

        $checkStart = $this->openCheck($line);

        // A branch delegating its whole case: the condition that says which case this is becomes the check's
        // first guard. Pushed after `openCheck` so it lands inside the check method rather than in the rule
        // body, which is what makes `return []` in the helper decline this branch rather than the whole node.
        if ($branchGuard !== null) {
            $this->context->lines[] = new Stm('guard', [
                'condition' => $branchGuard,
                'exit' => $this->context->backend->bail(),
            ], $this->context->indent);
        }

        $declaring = $this->context->currentClass instanceof ClassLike
            ? $this->declaringOf($method)
            : null;

        if ($declaring === null) {
            throw new Refusal("no method {$method}() on the rule, its traits or its parents", $line);
        }

        $helper = $this->findMethod($declaring['class'], $method);

        // Snapshotted before the two steps below, not after. Both bind names into the caller's scope so the
        // inner call's arguments can resolve — a shim's parameters, a consumer's record — and taking the copy
        // afterwards would restore the polluted scope, leaving `$reflectionProvider` and `$site` visible to
        // whatever the rule does next.
        $savedLocals = $this->context->locals;
        $savedLiterals = $this->context->literals;
        $savedCaches = $this->context->caches;

        // A shim forwards and nothing else: `return $this->other(..);`. Following it is what lets the check
        // below see the builder two levels down instead of refusing the shim for not being one. Followed one
        // step at a time and bounded by the same depth limit as any other inlining, so a cycle cannot run
        // away.
        $forwarded = $this->forwardedHelper($helper, $method, $args, $line);
        if ($forwarded !== null) {
            [$method, $args, $declaring, $helper] = $forwarded;
        }

        // A consumer that reads a field out of a produced record is followed the same way a shim is, so the
        // producer's guards land in the rule and the builder it reaches is what gets inlined below.
        $consumed = $this->recordConsumer($helper, $args, $line);
        if ($consumed !== null) {
            [$method, $args, $declaring, $helper] = $consumed;
        }

        if (! $this->buildsRuleError($helper)) {
            // Not every assigned helper builds a finding. A classifier hands back *which* case matched, and
            // the rule then puts that string in its message and its report code. That is a value, not a
            // report, so it binds a local instead of emitting guards.
            // Where the emitted statements stood before any attempt below. `inlineValueProducer` inlines the
            // producer to find out whether it hands back one value, and a producer that does not leaves its
            // statements behind — harmless while the next step was a refusal, and a duplicated loop now that
            // it is a second inline.
            $emittedBefore = count($this->context->lines);

            $classified = $target instanceof Variable && is_string($target->name)
                ? $this->classifierExpression($helper, $args, $method, $declaring, $line)
                : null;

            // A helper that guards and then hands back one value — an index, not a case name — binds the same
            // way, except that its guards land in the rule rather than folding into one expression.
            if ($classified === null && $target instanceof Variable && is_string($target->name)) {
                $produced = $this->inlineValueProducer($helper, $declaring, $method, $args, $line);
                if ($produced !== null) {
                    // The caller's scope, plus the one binding this produced. Anything a shim or a consumer
                    // bound on the way in has served its purpose and must not outlive the inline.
                    $this->context->locals = $savedLocals;
                    $this->context->literals = $savedLiterals;
                    $this->context->caches = $savedCaches;
                    $this->context->locals[$target->name] = $produced;

                    return;
                }
            }

            if ($classified !== null && $target instanceof Variable && is_string($target->name)) {
                // Bound as a nullable string. The rule's own `=== null` guard then bails, and the value goes
                // into the message and the report code, which is what the original does with it.
                $local = Emitter::snake($target->name);
                $this->context->lines[] = new Stm('declare', ['target' => $local, 'value' => $classified], $this->context->indent);
                $this->context->locals = $savedLocals;
                $this->context->literals = $savedLiterals;
                $this->context->caches = $savedCaches;
                $this->context->locals[$target->name] = [
                    'rust' => $local,
                    'kind' => 'bytes',
                    'php' => '$' . $local,
                ];

                return;
            }

            // A record producer assigned inside a loop is the accumulator fold, and it is not a gap in this
            // path — it is the same escape `$anchorNeedsLoop` guards. A record's fields are expressions over
            // the item the emitted `foreach` binds, so a fold that assigns one to a name declared before the
            // loop and reads it after would name a variable that is out of scope there.
            //
            // `hihaho/phpstan-rules` v3.15.2 introduced the shape in `agreedFlagSite()`, and the refusal used
            // to read "is assigned but does not build a rule error", which describes this path's expectations
            // rather than the rule's obstacle.
            if ($this->context->inLoop && $target instanceof Variable && is_string($target->name)) {
                // Materialised into runtime locals rather than folded, which is what lets it leave the loop.
                array_splice($this->context->lines, $emittedBefore);
                $folded = $this->foldRecordInLoop($helper, $declaring, $method, $args, $target->name, $line);
                if ($folded !== null) {
                    $this->context->locals = $savedLocals;
                    $this->context->literals = $savedLiterals;
                    $this->context->caches = $savedCaches;
                    $this->context->locals[$target->name] = $folded;

                    return;
                }

                throw new Refusal(
                    "{$method}() is assigned inside a loop and hands back a record this cannot materialise, "
                    . 'whose fields are expressions over the item the emitted foreach binds, so folding it '
                    . 'into a name declared before the loop would read that item after it is out of scope',
                    $line,
                );
            }

            // A record producer assigned outside a loop binds the record and emits its guards, the same way
            // a consumer reached through `recordConsumer()` does. The difference is only that the rule named
            // the record rather than passing it straight into the builder.
            if ($target instanceof Variable && is_string($target->name)) {
                array_splice($this->context->lines, $emittedBefore);
                $fields = $this->inlineProducer($helper, $declaring, $method, $args, $line);
                if ($fields !== [] && ! array_key_exists('', $fields)) {
                    $this->context->locals = $savedLocals;
                    $this->context->literals = $savedLiterals;
                    $this->context->caches = $savedCaches;
                    $this->context->locals[$target->name] = [
                        'rust' => self::PHP_ONLY,
                        'kind' => 'record',
                        'record' => $fields,
                    ];

                    return;
                }
            }

            throw new Refusal("{$method}() is assigned but does not build a rule error", $line);
        }

        $savedConstants = $this->context->constants;
        $savedInts = $this->context->intConstants;
        $savedArrayConstants = $this->context->arrayConstants;
        $savedClass = $this->context->currentClass;
        $savedUses = $this->context->useMap;
        $savedInHelper = $this->context->inErrorHelper;
        $savedMethod = $this->context->currentMethod;
        $savedConditions = $this->context->reportConditions;
        $savedTrailing = $this->context->helperTrailingReport;

        $this->context->locals = $this->bindParameters($helper, $args, $method, $line);
        $this->context->constants = [];
        $this->context->intConstants = [];
        $this->context->arrayConstants = [];
        $this->context->currentClass = $declaring['class'];
        $this->context->useMap = $declaring['uses'];
        $this->context->inErrorHelper = true;
        $this->context->currentMethod = $helper;
        $this->context->reportConditions = [];
        $this->context->helperTrailingReport = false;
        $this->collectConstants($declaring['class']);
        $this->enterInline($method, 'inlining', $line);

        try {
            foreach ($helper->stmts ?? [] as $statement) {
                $this->translateStatement($statement);
            }

            // The caller's locals, not the helper's: the parameters a check method needs are the ones the
            // rule had bound before it asked, and `$this->context->locals` here is the helper's own scope.
            $this->finishCheck($checkStart, $method, $savedLocals);

            // Whatever this helper took has now been emitted, or handed to the rule's trailing report. A rule
            // that asks several helpers in one pass is free to take a different message from the next one.
            $this->context->reportTaken = true;
        } finally {
            $this->leaveInline();
            $this->context->reportConditions = $savedConditions;
            $this->context->helperTrailingReport = $savedTrailing;
            $this->context->inErrorHelper = $savedInHelper;
            $this->context->currentMethod = $savedMethod;
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
            $this->context->constants = $savedConstants;
            $this->context->intConstants = $savedInts;
            $this->context->arrayConstants = $savedArrayConstants;
            $this->context->currentClass = $savedClass;
            $this->context->useMap = $savedUses;
        }
    }

    /**
     * A consumer that reads one field out of a produced record, or null when the helper is not one.
     *
     * The shape `hihaho/phpstan-rules` factors its detection into, so that one implementation drives both an
     * error rule and a manifest collector:
     *
     * ```php
     * return $this->flagErrorFromSite($this->flagSiteForNew($node, $scope, ...));
     * // and
     * private function flagErrorFromSite(?array $site): ?IdentifierRuleError
     * {
     *     return $site === null ? null : $this->flagError($site['paramName']);
     * }
     * ```
     *
     * Nothing here is a runtime array. `$site === null` means "some guard in the producer failed", which is
     * exactly the bail the producer's own `return null` statements already emit, and the consumer reads one
     * key. So the producer is inlined for its guards, its record becomes transpile-time bindings, and what
     * comes back is the *builder* call to carry on with — `flagError($site['paramName'])`, whose argument now
     * resolves to a binding.
     *
     * @param array<Arg> $args
     *
     * @return array{string, array<Arg>, Declaration, ClassMethod}|null
     */
    private function recordConsumer(ClassMethod $helper, array $args, int $line): ?array
    {
        $args = array_values($args);
        if (count($helper->params) !== 1 || count($args) !== 1) {
            return null;
        }

        $parameter = $helper->params[0]->var;
        $producer = $args[0]->value;
        if (! $parameter instanceof Variable
            || ! is_string($parameter->name)
            || ! $producer instanceof MethodCall
            || ! $this->isOwnMethodCall($producer)
        ) {
            return null;
        }

        $statements = $helper->stmts ?? [];
        if (count($statements) !== 1) {
            return null;
        }

        $only = $statements[0];
        if (! $only instanceof Return_ || ! $only->expr instanceof Ternary) {
            return null;
        }

        $builderCall = $this->nullGuardedBranch($only->expr, $parameter->name);
        if (! $builderCall instanceof MethodCall || ! $this->isOwnMethodCall($builderCall)) {
            return null;
        }

        $builder = $this->memberName($builderCall->name, $line);
        $declaring = $this->context->currentClass instanceof ClassLike
            ? $this->declaringOf($builder)
            : null;
        if ($declaring === null) {
            return null;
        }

        $fields = $this->inlineRecordProducer($producer, $line);
        $this->context->locals[$parameter->name] = [
            'rust' => self::PHP_ONLY,
            'kind' => 'record',
            'record' => $fields,
        ];

        // `$site === null ? null : $this->build($site['x'])` drops its null branch when the record is a map
        // of expressions: those navigate to something wherever they are read, so the branch cannot be taken.
        // A materialised record is the other case. Its locals are declared null before the loop that fills
        // them, so a receiver whose classes none declare the method leaves every field null — and dropping
        // the branch there would report with a null field where the original reports nothing.
        if ($this->isMaterialisedRecord($fields)) {
            $this->context->lines[] = new Stm('guard', [
                'condition' => $this->materialisedWitness($fields, $line) . ' === null',
                'exit' => $this->context->backend->bail(),
            ], $this->context->indent);
        }

        return [$builder, $builderCall->getArgs(), $declaring, $this->findMethod($declaring['class'], $builder)];
    }

    /**
     * The non-null branch of `<param> === null ? null : <call>`, or null when the ternary is not that.
     *
     * Written either way round in real code, so both are read: `=== null ? null : <call>` and
     * `!== null ? <call> : null`. Anything else is not a null guard and is refused by returning null, which
     * leaves the caller to name the construct.
     */
    private function nullGuardedBranch(Ternary $ternary, string $parameter): ?MethodCall
    {
        $condition = $ternary->cond;
        if ((! $condition instanceof Identical && ! $condition instanceof NotIdentical) || ! $ternary->if instanceof Expr) {
            return null;
        }

        $reads = ($condition->left instanceof Variable && $condition->left->name === $parameter && $this->isNullConstant($condition->right))
            || ($condition->right instanceof Variable && $condition->right->name === $parameter && $this->isNullConstant($condition->left));
        if (! $reads) {
            return null;
        }

        // `=== null` puts the bail first; `!== null` puts the build first.
        [$bail, $build] = $condition instanceof Identical
            ? [$ternary->if, $ternary->else]
            : [$ternary->else, $ternary->if];

        if (! $this->isNullConstant($bail) || ! $build instanceof MethodCall) {
            return null;
        }

        return $build;
    }

    private function isNullConstant(?Expr $expr): bool
    {
        return $expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'null';
    }

    /**
     * Inlines a producer for its guards and returns the record it hands back, as transpile-time bindings.
     *
     * Everything the producer does before its record is a guard the rule needs — resolve the written class
     * name, ask whether it is known, ask whether the position is a parameter at all — and every `return null`
     * inside it is the same bail a rule's `return []` is. So the body translates with the ordinary error-helper
     * machinery, and only the terminal `return [...]` is new: each key binds to whatever its value resolves to.
     *
     * A producer that hands off to a further producer is followed, which is what `flagSiteForNew` does with
     * `flagRecord`.
     *
     * @return RecordFields
     */
    private function inlineRecordProducer(MethodCall $call, int $line): array
    {
        $name = $this->memberName($call->name, $line);
        $declaring = $this->context->currentClass instanceof ClassLike
            ? $this->declaringOf($name)
            : null;
        if ($declaring === null) {
            throw new Refusal("no method {$name}() on the rule, its traits or its parents", $line);
        }

        return $this->inlineProducer($this->findMethod($declaring['class'], $name), $declaring, $name, $call->getArgs(), $line);
    }

    /**
     * A helper that guards and then hands back one value, as a descriptor, or null when it is not one.
     *
     * `lastBareFlagIndex()` is the shape: three guards, then `return count($args) - 1`. It builds no finding,
     * so the error-helper path refuses it, and it returns an index rather than a case name, so the classifier
     * path does not fit either. What it *is* is a producer of one value — the same thing a record producer is,
     * with one field instead of several — so it shares the machinery and unwraps the reserved empty key.
     *
     * @param array<Arg> $args
     * @param Declaration $declaring
     *
     * @return RecordField|null
     */
    private function inlineValueProducer(ClassMethod $helper, array $declaring, string $name, array $args, int $line): ?array
    {
        if ($this->buildsRuleError($helper)) {
            return null;
        }

        // The last segment of a qualified name, which rule packages write out by hand rather than reach for a
        // helper. Recognised as a whole: `strrpos`/`substr` in general are not in the vocabulary, and this shape
        // is the only use the corpus makes of them.
        $segment = $this->lastNameSegmentHelper($helper, $args, $line);
        if ($segment !== null) {
            return $segment;
        }

        // The values a list holds more than once, which rule packages count out by hand.
        $repeated = $this->repeatedValuesHelper($helper, $args, $line);
        if ($repeated !== null) {
            return $repeated;
        }

        // A helper that only picks between written words folds to an expression, with no statements emitted.
        // Tried before the statement-walking path because that path translates a `return 'public';` as a
        // rule-body guard and refuses; this shape is recognisable up front, so there is nothing to undo.
        $chosen = $this->translateMethodAsChoice($helper, $args, $name, $line);
        if ($chosen !== null) {
            return $chosen;
        }

        $produced = $this->inlineProducer($helper, $declaring, $name, $args, $line);

        return $produced[''] ?? null;
    }

    /**
     * @param array<Arg> $args
     * @param Declaration $declaring
     *
     * @return RecordFields
     */
    private function inlineProducer(
        ClassMethod $helper,
        array $declaring,
        string $name,
        array $args,
        int $line,
        bool $declineOnNull = false,
    ): array {
        $savedLocals = $this->context->locals;

        $savedLiterals = $this->context->literals;

        $savedCaches = $this->context->caches;
        $savedConstants = $this->context->constants;
        $savedInts = $this->context->intConstants;
        $savedArrayConstants = $this->context->arrayConstants;
        $savedClass = $this->context->currentClass;
        $savedUses = $this->context->useMap;
        $savedInHelper = $this->context->inErrorHelper;
        $savedFields = $this->context->recordFields;
        $savedMethod = $this->context->currentMethod;
        $savedFloor = $this->context->helperLoopFloor;
        // Any loop already open belongs to the caller, so a `return null` inside this helper ends the caller's
        // iteration. A loop the helper opens raises the depth past this floor, and leaving that one has to
        // leave the helper instead.
        // Below every depth when the caller turns a null record into a decline, so the producer's own
        // `return null` renders as the rule's bail rather than as the end of the caller's iteration. See
        // {@see nullRecordDeclines} for why that is read from the caller rather than assumed.
        $this->context->helperLoopFloor = $declineOnNull ? -1 : $this->context->loopDepth;

        $this->context->locals = $this->bindParameters($helper, $args, $name, $line);
        $this->context->constants = [];
        $this->context->intConstants = [];
        $this->context->arrayConstants = [];
        $this->context->currentClass = $declaring['class'];
        $this->context->useMap = $declaring['uses'];
        $this->context->inErrorHelper = true;
        $this->context->recordFields = [];
        // The body being walked, so a lookahead inside it asks about this helper rather than about whatever
        // called it. `inlineErrorHelper` already scopes this the same way.
        $this->context->currentMethod = $helper;
        $this->collectConstants($declaring['class']);
        $this->enterInline($name, 'inlining the producer', $line);

        try {
            // A cache around a pure question is invisible to the answer, and the declaration that opens one is
            // not a statement this can translate. Recognised before the walk rather than skipped inside it,
            // because `static $cache` on its own says nothing about whether dropping the cache is sound.
            $memoised = $this->memoisedExpression($helper);
            if ($memoised instanceof Expr) {
                $fields = ['' => $this->recordField($this->resolve($memoised, $line))];
            } else {
                foreach ($helper->stmts ?? [] as $statement) {
                    $this->translateStatement($statement);
                }

                $fields = $this->context->recordFields ?? [];
            }
        } finally {
            $this->leaveInline();
            $this->context->helperLoopFloor = $savedFloor;
            $this->context->recordFields = $savedFields;
            $this->context->currentMethod = $savedMethod;
            $this->context->inErrorHelper = $savedInHelper;
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
            $this->context->constants = $savedConstants;
            $this->context->intConstants = $savedInts;
            $this->context->arrayConstants = $savedArrayConstants;
            $this->context->currentClass = $savedClass;
            $this->context->useMap = $savedUses;
        }

        if ($fields === []) {
            throw new Refusal("{$name}() is read as a producer but hands back nothing", $line);
        }

        return $fields;
    }

    /**
     * Materialises a record produced inside a loop into runtime locals, or null when it cannot be.
     *
     * An ordinary record is a compile-time map of field to expression, folded into whatever consumes it.
     * That is exact and shorter, and it cannot cross a loop boundary: every expression reads the item the
     * emitted `foreach` binds, so a name declared before the loop and read after it would name an item that
     * is out of scope there. The counter faced the same wall and answered it the same way — stop folding,
     * emit a real variable.
     *
     * One local per field. The assignments go here, inside the loop, where the producer's own guards are
     * emitted too; the declarations are spliced in ahead of the `foreach`, because by the time a producer is
     * reached that statement is already on the list.
     *
     * PHP only. The two Rust targets render a descriptor through `rust`, and a materialised field has no
     * Rust rendering — returning null here refuses them by the same path as any other unsupported shape,
     * which is what keeps their emission unchanged.
     *
     * @param array<Arg>  $args
     * @param Declaration $declaring
     *
     * @return array{rust: string, kind: string, record: RecordFields}|null
     */
    private function foldRecordInLoop(
        ClassMethod $helper,
        array $declaring,
        string $method,
        array $args,
        string $targetName,
        int $line,
    ): ?array {
        if (Transpiler::$target !== 'php' || $this->context->loopOpenIndex === []) {
            return null;
        }

        // What the caller does with a null record decides what the producer's own `return null` has to emit.
        // Folded here it is `return null` from the accumulator, which declines the whole rule, so the
        // producer's guards bail rather than ending the iteration. A caller that instead skipped the item
        // would need `continue`, and the two are not interchangeable: on a receiver with two classes where
        // the first produces nothing, bailing reports nothing and continuing goes on to the second.
        if (! $this->nullRecordDeclines($targetName)) {
            return null;
        }

        $fields = $this->inlineProducer($helper, $declaring, $method, $args, $line, declineOnNull: true);

        // A single unnamed field is a value producer, not a record, and `inlineValueProducer` already had
        // its chance at it above. Nothing to materialise field by field.
        if ($fields === [] || array_key_exists('', $fields)) {
            return null;
        }

        $open = $this->context->loopOpenIndex[array_key_last($this->context->loopOpenIndex)];
        $outerIndent = $this->context->lines[$open]->indent;

        $declares = [];
        $record = [];
        $locals = [];

        foreach ($fields as $key => $descriptor) {
            // A field the producer could not resolve is carried as a reason rather than a value, and a
            // reason cannot be assigned. Carried through unmaterialised, exactly as the non-loop path
            // carries it: `flagRecord` resolves `paramName` and holds `method` and `value` only for the
            // manifest collector, which is refused for writing a file. A consumer that does read one still
            // refuses, at the read, naming the field.
            if (! isset($descriptor['php'])) {
                $record[$key] = $descriptor;

                continue;
            }

            $local = Emitter::snake($targetName . '_' . $key);
            $locals[$key] = $local;

            // Null before the loop, so the record reads as absent when the loop body never runs — which is
            // what `$site = null` meant in the original.
            $declares[] = new Stm('declare', ['target' => $local, 'value' => 'null'], $outerIndent);

            $this->context->lines[] = new Stm('assign', [
                'target' => $local,
                'value' => $descriptor['php'],
            ], $this->context->indent);

            $record[$key] = [
                'rust' => self::PHP_ONLY,
                'kind' => $descriptor['kind'],
                'php' => '$' . $local,
                // Marks the field as a runtime variable rather than a folded expression. A consumer reads it
                // to know whether the record can be absent at the point it is read: a folded expression is
                // whatever it navigates to, but a local declared null before a loop is null when the loop
                // assigned nothing, and the original's `=== null` branch is then live.
                'local' => true,
            ];
            if (isset($descriptor['as'])) {
                $record[$key]['as'] = $descriptor['as'];
            }
        }

        // Nothing resolved is not a record that crossed the loop, it is a record that did not.
        if ($locals === []) {
            return null;
        }

        array_splice($this->context->lines, $open, 0, $declares);

        // The open moved by exactly what was inserted ahead of it. A second record folded in the same loop
        // reads this index, and a stale one would splice its declarations between the first record's and the
        // `foreach`, which still runs but no longer says what it means.
        array_pop($this->context->loopOpenIndex);
        $this->context->loopOpenIndex[] = $open + count($declares);

        $this->context->foldedRecords[$targetName] = $locals;

        return ['rust' => self::PHP_ONLY, 'kind' => 'record', 'record' => $record];
    }

    /**
     * One materialised field of a folded record, to ask a whole-record question of.
     *
     * Every materialised field is declared null before the loop and assigned in the same iteration, so any
     * one of them answers "is this record set" for all of them. The first in declaration order is taken so
     * the emitted question is stable across runs rather than depending on map order.
     *
     * @param RecordFields $fields
     */
    private function materialisedWitness(array $fields, int $line): string
    {
        foreach ($fields as $field) {
            if (($field['local'] ?? false) === true && isset($field['php'])) {
                return $field['php'];
            }
        }

        throw new Refusal('a record with no materialised field to test for null', $line);
    }

    /**
     * Whether the loop that assigns `$name` answers a null record by declining, rather than by skipping it.
     *
     * The fold's own step is `$record = $this->produce(..); if ($record === null) { return null; }`, and that
     * `return null` leaves the accumulator, which leaves the rule. Read from the caller's source rather than
     * assumed, because the other shape is legal and means the opposite: a `continue` there skips this class
     * and goes on to the next, and a receiver with two classes tells the two apart.
     */
    private function nullRecordDeclines(string $name): bool
    {
        $method = $this->context->currentMethod;
        if (! $method instanceof ClassMethod) {
            return false;
        }

        foreach ((new NodeFinder())->findInstanceOf([$method], Foreach_::class) as $loop) {
            $statements = array_values($loop->stmts);
            foreach ($statements as $index => $statement) {
                if (! $statement instanceof Expression
                    || ! $statement->expr instanceof Assign
                    || ! $statement->expr->var instanceof Variable
                    || $statement->expr->var->name !== $name
                ) {
                    continue;
                }

                $next = $statements[$index + 1] ?? null;

                return $next instanceof If_ && $this->isReturnNull($next->stmts);
            }
        }

        return false;
    }

    /**
     * Whether a record's fields are runtime locals rather than folded expressions.
     *
     * @param RecordFields $fields
     */
    private function isMaterialisedRecord(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['local'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copies one materialised record's fields into another's locals, field by field.
     *
     * Both names already hold locals — the accumulator's were declared before the loop, the producer's are
     * assigned inside it — so the copy is assignments between variables and nothing is rebound. That is what
     * keeps the two distinguishable at the agreement check, which is the only reason the fold has two names
     * rather than one.
     */
    private function copyRecord(string $target, string $source, int $line): void
    {
        $into = $this->context->locals[$target]['record'] ?? [];
        $from = $this->context->locals[$source]['record'] ?? [];

        $copied = 0;
        foreach ($into as $field => $descriptor) {
            $value = $from[$field]['php'] ?? null;
            $local = $descriptor['php'] ?? null;
            if (! is_string($value) || ! is_string($local)) {
                continue;
            }

            $this->context->lines[] = new Stm('assign', [
                'target' => ltrim($local, '$'),
                'value' => $value,
            ], $this->context->indent);
            ++$copied;
        }

        if ($copied === 0) {
            throw new Refusal(
                'a record copied into an accumulator that shares no materialised field with it',
                $line,
            );
        }
    }

    /**
     * A resolved value reduced to what a record carries.
     *
     * Only the rendering and the kind survive: a record is read once, by a consumer that puts the value in a
     * message, so the narrowing bindings and refinements that came with it have no meaning on the other side.
     *
     * @param Descriptor $resolved
     *
     * @return array{rust: string, kind: string, php?: string, reason?: string, as?: string}
     */
    private function recordField(array $resolved): array
    {
        $field = ['rust' => $resolved['rust'], 'kind' => $resolved['kind']];
        if (isset($resolved['php'])) {
            $field['php'] = $resolved['php'];
        }

        // What a list holds survives too. It decides whether a later comparison against one of its items folds
        // case, and dropping it here made a producer's list indistinguishable from one the rule filled by hand.
        if (isset($resolved['as'])) {
            $field['as'] = $resolved['as'];
        }

        return $field;
    }

    /** Binds each key of a returned record to what its value resolves to, for the consumer to read. */
    private function bindRecordFields(Array_ $record): void
    {
        $fields = [];
        foreach ($record->items as $item) {
            if ($item === null || ! $item->key instanceof Expr) {
                throw new Refusal('a record with a key that is not written out', $record->getStartLine());
            }

            $key = $this->rawStringLiteral($item->key, $record->getStartLine());

            // A field the consumer never reads costs nothing to skip and would otherwise refuse the whole
            // record for a construct no emitted rule depends on. `flagRecord` carries `method` and `value`
            // only for the manifest collector, which is refused for writing a file rather than reporting.
            try {
                $fields[$key] = $this->recordField($this->resolve($item->value, $record->getStartLine()));
            } catch (Refusal $refusal) {
                $fields[$key] = ['rust' => self::PHP_ONLY, 'kind' => 'unresolved', 'reason' => $refusal->getMessage()];
            }
        }

        $this->context->recordFields = $fields;
    }

    /**
     * The helper a shim forwards to, or null when the helper is not a shim.
     *
     * `positionalFlagErrorForNew()` is exactly `return $this->flagErrorFromSite($this->flagSiteForNew(..));`
     * — it decides nothing itself. Following the outermost call means the shim's own name stops being the
     * thing that refuses, and whatever the chain really needs is what gets named instead.
     *
     * @param array<Arg> $args
     *
     * @return array{string, array<Arg>, Declaration, ClassMethod}|null
     */
    private function forwardedHelper(ClassMethod $helper, string $method, array $args, int $line): ?array
    {
        $statements = $helper->stmts ?? [];
        if (count($statements) !== 1) {
            return null;
        }

        $only = $statements[0];
        if (! $only instanceof Return_) {
            return null;
        }

        $call = $only->expr;
        if (! $call instanceof MethodCall || ! $this->isOwnMethodCall($call)) {
            return null;
        }

        $forwarded = $call->name instanceof Identifier ? $call->name->toString() : null;
        if ($forwarded === $method) {
            return null;
        }

        $declaresForwarded = $this->context->currentClass instanceof ClassLike
            ? $this->declaringOf($forwarded)
            : null;
        if ($declaresForwarded === null) {
            return null;
        }

        // The shim's own parameters are what the forwarded call passes on — `flagSiteForNew($node, $scope,
        // $reflectionProvider, $firstPartyNamespaces)` names all four. Bound before handing the inner
        // arguments back, or they resolve against the rule's scope, where those names do not exist.
        $this->context->locals = $this->bindParameters($helper, $args, $method, $line) + $this->context->locals;

        return [
            $forwarded,
            $call->getArgs(),
            $declaresForwarded,
            $this->findMethod($declaresForwarded['class'], $forwarded),
        ];
    }

    /**
     * `strrpos` then `substr` — a helper handing back the last segment of a qualified name.
     *
     * ```php
     * $position = strrpos($className, '\\');
     *
     * return $position === false ? $className : substr($className, $position + 1);
     * ```
     *
     * Matched whole rather than translated statement by statement. Neither function is in the vocabulary, and
     * putting them there to serve this one shape would let any string arithmetic through; `Support` already has
     * the question this asks.
     *
     * @param array<Arg> $args
     *
     * @return array{rust: string, kind: string, php?: string, reason?: string, as?: string}|null
     */
    private function lastNameSegmentHelper(ClassMethod $helper, array $args, int $line): ?array
    {
        $statements = $helper->stmts ?? [];
        if (count($statements) !== 2
            || ! $statements[0] instanceof Expression
            || ! $statements[0]->expr instanceof Assign
            || ! $statements[0]->expr->var instanceof Variable
            || ! is_string($statements[0]->expr->var->name)
            || ! $statements[1] instanceof Return_
            || ! $statements[1]->expr instanceof Ternary
            || count($args) !== 1
        ) {
            return null;
        }

        $position = $statements[0]->expr->var->name;
        $subject = $this->parameterName($helper);
        if ($subject === null || ! $this->findsLastSeparator($statements[0]->expr->expr, $subject)) {
            return null;
        }

        if (! $this->takesTheTail($statements[1]->expr, $position, $subject)) {
            return null;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal("{$helper->name->toString()}() reads a name's last segment, which only the PHP target carries", $line);
        }

        $of = $this->resolve($args[0]->value, $line);

        return [
            'rust' => self::PHP_ONLY,
            'kind' => 'bytes',
            'php' => $this->context->backend->call('last_name_segment', [$this->nameText($of, $line)]),
        ];
    }

    /**
     * `array_count_values` then a loop keeping the keys counted more than once — the duplicates of a list.
     *
     * ```php
     * $counts = array_count_values($values);
     * $duplicates = [];
     * foreach ($counts as $value => $count) {
     *     if ($count <= 1) { continue; }
     *     $duplicates[] = $value;
     * }
     *
     * return $duplicates;
     * ```
     *
     * Matched whole, for the same reason the last-segment helper is: `array_count_values` and a keyed loop are
     * not in the vocabulary, and admitting them to serve this one shape would let arbitrary list arithmetic
     * through. `Support::repeatedValues()` is the question this asks, and it is built on the same function so
     * the key coercion cannot differ.
     *
     * @param array<Arg> $args
     *
     * @return array{rust: string, kind: string, php?: string, reason?: string, as?: string}|null
     */
    private function repeatedValuesHelper(ClassMethod $helper, array $args, int $line): ?array
    {
        $statements = $helper->stmts ?? [];
        if (count($statements) !== 4 || count($args) !== 1) {
            return null;
        }

        $parameter = $this->parameterName($helper);
        $counts = $this->countsValuesOf($statements[0], $parameter);
        $built = $this->emptyListName($statements[1]);
        if ($parameter === null || $counts === null || $built === null) {
            return null;
        }

        if (! $statements[2] instanceof Foreach_
            || ! $statements[3] instanceof Return_
            || ! $statements[3]->expr instanceof Variable
            || $statements[3]->expr->name !== $built
            || ! $this->keepsRepeatedKeys($statements[2], $counts, $built)
        ) {
            return null;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal("{$helper->name->toString()}() reads a list's duplicates, which only the PHP target carries", $line);
        }

        $of = $this->resolve($args[0]->value, $line);
        if (! in_array($of['kind'], ['list', 'class-names'], true)) {
            throw new Refusal("the duplicates of a {$of['kind']}", $line);
        }

        return [
            'rust' => self::PHP_ONLY,
            'kind' => 'list',
            'php' => $this->context->backend->call('repeated_values', [$this->operand($of)]),
        ];
    }

    /** `$counts = array_count_values($param);` — the name it binds, or null when that is not the statement. */
    private function countsValuesOf(Stmt $statement, ?string $parameter): ?string
    {
        if ($parameter === null
            || ! $statement instanceof Expression
            || ! $statement->expr instanceof Assign
            || ! $statement->expr->var instanceof Variable
            || ! is_string($statement->expr->var->name)
            || ! $statement->expr->expr instanceof FuncCall
            || ! $statement->expr->expr->name instanceof Name
            || $statement->expr->expr->name->toString() !== 'array_count_values'
            || count($statement->expr->expr->getArgs()) !== 1
        ) {
            return null;
        }

        $over = $statement->expr->expr->getArgs()[0]->value;

        return $over instanceof Variable && $over->name === $parameter ? $statement->expr->var->name : null;
    }

    /** `$out = [];` — the name it binds, or null when that is not the statement. */
    private function emptyListName(Stmt $statement): ?string
    {
        if (! $statement instanceof Expression
            || ! $statement->expr instanceof Assign
            || ! $statement->expr->var instanceof Variable
            || ! is_string($statement->expr->var->name)
            || ! $statement->expr->expr instanceof Array_
            || $statement->expr->expr->items !== []
        ) {
            return null;
        }

        return $statement->expr->var->name;
    }

    /**
     * `foreach ($counts as $value => $count) { if ($count <= 1) { continue; } $out[] = $value; }`
     *
     * The `<= 1` is matched exactly. A different bound asks a different question, and reading it as "more than
     * once" would answer that one instead.
     */
    private function keepsRepeatedKeys(Foreach_ $loop, string $counts, string $built): bool
    {
        if (! $loop->expr instanceof Variable
            || $loop->expr->name !== $counts
            || ! $loop->keyVar instanceof Variable
            || ! is_string($loop->keyVar->name)
            || ! $loop->valueVar instanceof Variable
            || ! is_string($loop->valueVar->name)
            || count($loop->stmts) !== 2
        ) {
            return false;
        }

        [$guard, $append] = $loop->stmts;
        if (! $guard instanceof If_
            || ! $guard->cond instanceof SmallerOrEqual
            || ! $guard->cond->left instanceof Variable
            || $guard->cond->left->name !== $loop->valueVar->name
            || ! $guard->cond->right instanceof Int_
            || $guard->cond->right->value !== 1
            || count($guard->stmts) !== 1
            || ! $guard->stmts[0] instanceof Continue_
        ) {
            return false;
        }

        return $append instanceof Expression
            && $append->expr instanceof Assign
            && $append->expr->var instanceof ArrayDimFetch
            && $append->expr->var->var instanceof Variable
            && $append->expr->var->var->name === $built
            && ! $append->expr->var->dim instanceof Expr
            && $append->expr->expr instanceof Variable
            && $append->expr->expr->name === $loop->keyVar->name;
    }

    /** The sole parameter's name, or null when the helper does not take exactly one simple parameter. */
    private function parameterName(ClassMethod $helper): ?string
    {
        if (count($helper->params) !== 1 || ! $helper->params[0]->var instanceof Variable) {
            return null;
        }

        $name = $helper->params[0]->var->name;

        return is_string($name) ? $name : null;
    }

    /** `strrpos($subject, '\\')` — where the last namespace separator is. */
    private function findsLastSeparator(Expr $expr, string $subject): bool
    {
        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'strrpos'
            && count($expr->getArgs()) === 2
            && $expr->getArgs()[0]->value instanceof Variable
            && $expr->getArgs()[0]->value->name === $subject
            && $expr->getArgs()[1]->value instanceof String_
            && $expr->getArgs()[1]->value->value === '\\';
    }

    /** `$position === false ? $subject : substr($subject, $position + 1)` — the whole name, or what follows. */
    private function takesTheTail(Ternary $ternary, string $position, string $subject): bool
    {
        if (! $ternary->cond instanceof Identical
            || ! $ternary->cond->left instanceof Variable
            || $ternary->cond->left->name !== $position
            || $this->isBooleanLiteral($ternary->cond->right) !== 'false'
            || ! $ternary->if instanceof Variable
            || $ternary->if->name !== $subject
            || ! $ternary->else instanceof FuncCall
            || ! $ternary->else->name instanceof Name
            || $ternary->else->name->toString() !== 'substr'
            || count($ternary->else->getArgs()) !== 2
        ) {
            return false;
        }

        $from = $ternary->else->getArgs()[1]->value;

        return $ternary->else->getArgs()[0]->value instanceof Variable
            && $ternary->else->getArgs()[0]->value->name === $subject
            && $from instanceof Plus
            && $from->left instanceof Variable
            && $from->left->name === $position
            && $from->right instanceof Int_
            && $from->right->value === 1;
    }

    /**
     * A classifier helper as one nullable-string expression, or null when the helper is not one.
     *
     * The shape, which `BaseNoDebugRule::matchDebugNamespace()` is exactly:
     *
     * ```php
     * if ($this->namespaceStartsWith($scope, 'App')) {
     *     return 'App';
     * }
     *
     * if ($this->namespaceStartsWith($scope, 'Tests')) {
     *     return 'Tests';
     * }
     *
     * return null;
     * ```
     *
     * Translated to a nested conditional rather than expanded into one report per case. Both would work —
     * `report()` takes its code per call — but a chain of conditionals keeps one report site and one message,
     * which is what the original has, and it stays readable against the rule it came from.
     *
     * @param array<Arg> $args
     * @param Declaration $declaring
     */
    private function classifierExpression(ClassMethod $helper, array $args, string $method, array $declaring, int $line): ?string
    {
        $cases = [];
        $statements = $helper->stmts ?? [];
        foreach ($statements as $index => $statement) {
            if ($statement instanceof Return_) {
                // Only a trailing `return null` closes a classifier; a bare string return would make every
                // case after it unreachable, which is not a shape worth guessing at.
                if ($index !== count($statements) - 1 || ! $this->isNullReturn($statement)) {
                    return null;
                }

                continue;
            }

            if (! $statement instanceof If_ || $statement->elseifs !== [] || $statement->else instanceof Else_) {
                return null;
            }

            $returned = $this->soleReturn($statement->stmts);
            if (! $returned instanceof String_) {
                return null;
            }

            $cases[] = [$statement->cond, $returned->value];
        }

        if ($cases === []) {
            return null;
        }

        return $this->renderClassifier($cases, $helper, $args, $method, $declaring, $line);
    }

    /**
     * @param list<array{Expr, string}> $cases
     * @param array<Arg> $args
     * @param Declaration $declaring
     */
    private function renderClassifier(array $cases, ClassMethod $helper, array $args, string $method, array $declaring, int $line): string
    {
        $savedLocals = $this->context->locals;
        $savedLiterals = $this->context->literals;
        $savedCaches = $this->context->caches;
        $savedConstants = $this->context->constants;
        $savedInts = $this->context->intConstants;
        $savedArrayConstants = $this->context->arrayConstants;
        $savedConstantKeys = $this->context->constantKeys;
        $savedClass = $this->context->currentClass;
        $savedUses = $this->context->useMap;

        $this->context->locals = $this->bindParameters($helper, $args, $method, $line);
        $this->context->constants = [];
        $this->context->intConstants = [];
        $this->context->arrayConstants = [];
        $this->context->constantKeys = [];
        $this->context->currentClass = $declaring['class'];
        $this->context->useMap = $declaring['uses'];
        $this->collectConstants($declaring['class']);
        $this->enterInline($method, 'inlining the classifier', $line);

        try {
            $expression = Transpiler::$target === 'php' ? 'null' : 'None';
            foreach (array_reverse($cases) as [$condition, $value]) {
                $expression = $this->context->backend->conditional(
                    $this->stripOuterParentheses($this->translateCondition($condition)),
                    $this->context->backend->bytes($value),
                    $expression,
                );
            }

            return $expression;
        } finally {
            $this->leaveInline();
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
            $this->context->constants = $savedConstants;
            $this->context->intConstants = $savedInts;
            $this->context->arrayConstants = $savedArrayConstants;
            $this->context->constantKeys = $savedConstantKeys;
            $this->context->currentClass = $savedClass;
            $this->context->useMap = $savedUses;
        }
    }

    /**
     * The single `return` of a guard body, or null when the body is anything else.
     *
     * @param array<Stmt> $statements
     */
    private function soleReturn(array $statements): ?Expr
    {
        if (count($statements) !== 1) {
            return null;
        }

        $only = $statements[0];

        return $only instanceof Return_ ? $only->expr : null;
    }

    private function isNullReturn(Return_ $statement): bool
    {
        return $statement->expr instanceof ConstFetch
            && strtolower($statement->expr->name->toString()) === 'null';
    }

    /**
     * Whether any return in this method hands back a built rule error.
     *
     * Searched at any depth, because the shape that matters is a run of guards with the build inside one
     * of them and a trailing `return null`. Only checking top-level returns missed every real helper.
     */
    public function buildsRuleError(ClassMethod $method): bool
    {
        foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Return_::class) as $return) {
            if ($return->expr instanceof Expr && $this->returnedRuleError($return->expr) instanceof Expr) {
                return true;
            }
        }

        return false;
    }

    /**
     * A helper reached through `$this`.
     *
     * It is usually not declared on the rule itself: rule packages keep the logic in a trait or an abstract
     * base and leave the rule as a shim, so the declaration has to be found across the hierarchy first.
     *
     * @param array<Arg> $args
     */
    private function inlineOwnHelper(string $method, array $args, int $line): string
    {
        $declaring = $this->declaringOf($method);

        if ($declaring === null) {
            throw new Refusal("no method {$method}() on the rule, its traits or its parents", $line);
        }

        return $this->inlineMethod($declaring['class'], $method, array_values($args), $line, $declaring['uses']);
    }

    /**
     * Where a `$this->method()` call is declared, looking at the rule itself when the inlined file has no it.
     *
     * @return Declaration|null
     */
    public function declaringOf(string $method): ?array
    {
        $found = $this->context->currentClass instanceof ClassLike
            ? $this->hierarchy()->declaring($this->context->currentClass, $method, $this->context->useMap, $this->context->ruleNamespace)
            : null;

        if ($found !== null || ! $this->context->ruleClass instanceof ClassLike || $this->context->ruleClass === $this->context->currentClass) {
            return $found;
        }

        return $this->hierarchy()->declaring($this->context->ruleClass, $method, $this->context->ruleUses, $this->context->ruleNamespace);
    }

    public function hierarchy(): Hierarchy
    {
        return new Hierarchy(fn (string $shortName): ?array => $this->findClassByName($shortName));
    }

    /**
     * The verbosity a `describe()` call names, or `unknown` when it is not a `VerbosityLevel::x()` call.
     *
     * Named rather than assumed: the levels render differently, and translating one as another would be right
     * on most types and wrong on literals — the shape of mistake this vocabulary refuses by default.
     */
    private function verbosityLevel(MethodCall $expr): string
    {
        $argument = $expr->getArgs()[0]->value ?? null;

        return $argument instanceof StaticCall
            && $argument->class instanceof Name
            && $argument->class->getLast() === 'VerbosityLevel'
                ? $this->memberName($argument->name, $argument->getStartLine())
                : 'unknown';
    }

    /** A `self::CONST` / `static::CONST` string constant declared by this rule. */
    private function selfConstant(ClassConstFetch $expr): string
    {
        $class = $expr->class instanceof Name ? $expr->class->toString() : '';
        $name = $this->memberName($expr->name, $expr->getStartLine());
        if (in_array($class, ['self', 'static'], true) && isset($this->context->constants[$name])) {
            return $this->context->constants[$name];
        }

        // A constant from elsewhere: resolvable the same way argument literals are.
        return $this->rawStringLiteral($expr, $expr->getStartLine());
    }

    /**
     * A Rust array of `&[u8]`.
     *
     * Each literal needs `.as_slice()`: `b"ab"` is `[u8; 2]`, so a list of different-length literals
     * has no common element type on its own.
     *
     * @param list<string> $options
     */
    private function byteSliceList(array $options): string
    {
        if (Transpiler::$target === 'php') {
            return '[' . implode(', ', array_map(
                fn (string $option): string => $this->context->backend->bytes(str_replace('\\', '\\\\', $option)),
                $options,
            )) . ']';
        }

        return '[' . implode(', ', array_map(
            static fn (string $option): string => 'b"' . str_replace('\\', '\\\\', $option) . '".as_slice()',
            $options,
        )) . ']';
    }

    /** A Rust `&[u8]` expression: a literal, a resolvable constant, or a bound loop variable. */
    private function bytesValue(Expr $expr, int $line): string
    {
        if ($expr instanceof Variable && is_string($expr->name)
            && ($this->context->locals[$expr->name]['kind'] ?? null) === 'bytes'
        ) {
            return $this->operand($this->context->locals[$expr->name]);
        }

        try {
            return $this->context->backend->bytes(str_replace('\\', '\\\\', $this->rawStringLiteral($expr, $line)));
        } catch (Refusal $refusal) {
            // Not a literal. It may still be a string the plugin can build while it runs, which is what a
            // normalised configured value is. Refused with the original reason when it is neither.
            $built = $this->resolve($expr, $line);
            if (in_array($built['kind'], ['bytes', 'class-name', 'config-bytes', 'resolved-name'], true)) {
                return $this->operand($built);
            }

            throw $refusal;
        }
    }

    /**
     * `count($variants) === 1 ? $variants[0] : ParametersAcceptorSelector::selectFromArgs(..)`, as the one
     * variant Mago has — or null when the ternary is not that shape.
     *
     * Mago's `FunctionLikeMetadata` carries a single `parameters` list, so the condition holds by construction
     * and the selector branch has nothing to select from. Recognised as a whole rather than translated branch
     * by branch, because the false branch has no equivalent and translating it is what would have to be
     * refused.
     *
     * @return Descriptor|null
     */
    private function singleVariant(Ternary $ternary, int $line): ?array
    {
        $condition = $ternary->cond;
        if (! $condition instanceof Identical
            || ! $condition->left instanceof FuncCall
            || ! $condition->left->name instanceof Name
            || $condition->left->name->toString() !== 'count'
            || count($condition->left->getArgs()) !== 1
        ) {
            return null;
        }

        try {
            $counted = $this->resolve($condition->left->getArgs()[0]->value, $line);
        } catch (Refusal) {
            return null;
        }

        if ($counted['kind'] !== 'variants' || $this->intLiteral($condition->right, $line) !== 1) {
            return null;
        }

        return $counted;
    }

    /**
     * A question about the parameter a descriptor points at, as a PHP call.
     *
     * Every one takes the same three things — the declaring class, the method name and the position — because
     * Mago has no parameter object to hold them: `getDeclaringMethod($class, $method)->parameters[$i]`.
     *
     * @param Descriptor $subject
     */
    private function parameterQuestion(string $helper, array $subject, int $line): string
    {
        return $this->context->backend->call($helper, [
            '$context',
            $this->handlePart($subject, 'classPhp', $line),
            $this->handlePart($subject, 'methodPhp', $line),
            $this->handlePart($subject, 'indexPhp', $line),
        ]);
    }

    /**
     * One half of a handle a descriptor carries, refused by name when it is missing.
     *
     * A method handle always carries its class and its method, and a parameter handle its position too — but
     * the descriptor shape is shared with every other kind, so the keys are optional there. Reading through
     * here means a mistake in building one refuses instead of emitting a call with a hole in it.
     *
     * @param Descriptor $subject
     */
    private function handlePart(array $subject, string $part, int $line): string
    {
        $half = $subject[$part] ?? null;

        return is_string($half) ? $half : throw new Refusal("a handle with no {$part} behind it", $line);
    }

    private function numericOperator(BinaryOp $expr): string
    {
        return match (true) {
            $expr instanceof GreaterOrEqual => '>=',
            $expr instanceof Greater => '>',
            $expr instanceof SmallerOrEqual => '<=',
            default => '<',
        };
    }

    /**
     * `count(<something>)` as a number, for the lists whose length a rule compares.
     *
     * Only a list this vocabulary produces: counting something else would be counting an expression nobody has
     * established is a list, and the answer would look right whatever it was.
     */
    private function countable(FuncCall $count, int $line): string
    {
        if (Transpiler::$target !== 'php') {
            throw new Refusal('a list length compared numerically, which only the PHP target carries', $line);
        }

        $subject = $this->resolve($count->getArgs()[0]->value, $line);

        // An argument list is not a PHP array on the other side — it is a node whose `Argument` children are
        // the arguments — so it counts through the helper that already answers `count($args) === 0` on the
        // equality path. Reaching here at all was the gap: the same expression compared with `<` refused
        // where compared with `===` it emitted, which reads as the vocabulary not covering argument counts.
        if ($subject['kind'] === 'args') {
            return $this->context->backend->call('arg_count', [$this->operand($subject)]);
        }

        if (! in_array($subject['kind'], ['found-nodes', 'method-members', 'param-decls', 'property-members', 'config-list', 'list'], true)) {
            throw new Refusal("count() of a {$subject['kind']} compared numerically", $line);
        }

        return 'count(' . $this->operand($subject) . ')';
    }

    /**
     * What a subtree search searches, as a PHP expression.
     *
     * A rule passes either the statements a node holds or the node itself, sometimes through a `(array)` cast
     * that says nothing here. Anything else — a list a rule built, a node from somewhere unrelated — is refused,
     * because a search over the wrong subtree is a wrong answer that looks like a right one.
     */
    private function subtreeArgument(Expr $expr, int $line): string
    {
        if ($expr instanceof Expr\Cast\Array_) {
            return $this->subtreeArgument($expr->expr, $line);
        }

        $subject = $this->resolve($expr, $line);
        if (! in_array($subject['kind'], ['subtree', 'hook-node', 'method-decl', 'maybe-method-decl', 'expr'], true)) {
            throw new Refusal("a subtree search over a {$subject['kind']}", $line);
        }

        return $this->operand($subject);
    }

    /**
     * One side of a string built at analysis time, as a PHP expression.
     *
     * A literal folds to its bytes; anything else has to resolve to something string-shaped. Kept separate so
     * the concatenation reads as one line and the refusal names the part that is not a string.
     */
    private function stringOperand(Expr $expr, int $line): string
    {
        try {
            return $this->context->backend->bytes($this->rawStringLiteral($expr, $line));
        } catch (Refusal) {
            $resolved = $this->resolve($expr, $line);
            if (! in_array($resolved['kind'], ['bytes', 'class-name', 'config-bytes', 'resolved-name'], true)) {
                throw new Refusal("a {$resolved['kind']} used as part of a string", $line);
            }

            return $this->operand($resolved);
        }
    }

    /** A Rust expression producing the string PHP would interpolate for this argument. */
    private function stringValue(Expr $expr, int $line): string
    {
        if ($expr instanceof Expr\Cast\String_) {
            return $this->stringValue($expr->expr, $line);
        }

        if ($expr instanceof String_) {
            return Transpiler::$target === 'php'
                ? $this->context->backend->bytes($expr->value)
                : '"' . addcslashes($expr->value, '"\\') . '"';
        }

        if ($expr instanceof ClassConstFetch) {
            $raw = $this->rawStringLiteral($expr, $line);

            return Transpiler::$target === 'php' ? $this->context->backend->bytes($raw) : '"' . addcslashes($raw, '"\\') . '"';
        }

        if ($expr instanceof MethodCall
            && in_array($this->memberName($expr->name, $expr->getStartLine()), ['getLine', 'getStartLine'], true)
            && $expr->var instanceof Variable
            && $expr->var->name === 'node'
        ) {
            return 'support::line_text(context, node.span())';
        }

        $subject = $this->resolve($expr, $line);

        if (Transpiler::$target === 'php') {
            // PHP has no byte-slice-to-string step, so a value that Rust must convert is already a
            // string here; what differs is which helper produces it.
            return match ($subject['kind']) {
                'hint-option', 'hint' => $this->context->backend->call('hint_name', ['$context', $this->operand($subject)]),
                'collected-value', 'bytes', 'class-name' => $this->operand($subject),
                'local-name', 'name-selector', 'name-expr' => $this->context->backend->call('text_of', [$this->operand($subject)]),
                'extends' => $this->context->backend->call('extends_text', ['$context', '$node']),
                // A number a rule counted, put in the message with `%d`. Already a PHP int, so `sprintf` formats
                // it without help — and a configured threshold is the same thing, which a rule quotes back
                // beside the number that crossed it.
                'int', 'config-number' => $this->operand($subject),
                default => throw new Refusal("cannot render a {$subject['kind']} as a message argument", $line),
            };
        }

        return match ($subject['kind']) {
            'hint-option', 'hint' => "support::hint_text(context, {$subject['rust']})",
            'collected-value' => $subject['rust'],
            'local-name' => "String::from_utf8_lossy(support::local_name_text({$subject['rust']}))",
            'bytes', 'class-name' => "String::from_utf8_lossy({$subject['rust']})",
            'name-selector' => "support::selector_text({$subject['rust']})",
            'name-expr' => "support::name_text({$subject['rust']})",
            'extends' => 'support::extends_text(context, node)',
            default => throw new Refusal("cannot render a {$subject['kind']} as a message argument", $line),
        };
    }

    public function translateStatement(Stmt $stmt): void
    {
        if ($stmt instanceof If_) {
            $this->translateIf($stmt);

            return;
        }

        // foreach (<iterable> as $item) { ... }
        if ($stmt instanceof Foreach_) {
            $this->translateForeach($stmt);

            return;
        }

        // `continue;` inside a loop
        if ($stmt instanceof Continue_) {
            if (! $this->context->inLoop) {
                throw new Refusal('continue outside a loop', $stmt->getStartLine());
            }

            $this->context->lines[] = new Stm('continue', [], $this->context->indent);

            return;
        }

        // A collector's terminal statement hands back the datum to record.
        if ($this->context->isCollector && $stmt instanceof Return_) {
            $this->translateCollect($stmt);

            return;
        }

        // Inside an error helper the return value is the finding itself, not a list holding it.
        if ($this->context->inErrorHelper && $stmt instanceof Return_) {
            $this->translateErrorHelperReturn($stmt);

            return;
        }

        // Terminal: return [ ...error... ];  or  $x = RuleErrorBuilder...; return [$x];
        if ($stmt instanceof Return_) {
            // `return [$error];` inside the loop that built it: report here and stop, which is what the original
            // does. Emitted at the return rather than at the assignment, because only the return distinguishes
            // "report the first one and stop" from "collect them all".
            if (($this->context->inLoop || $this->context->inConditionalReport)
                && $this->context->pendingReport !== null
                && $stmt->expr instanceof Array_
                && count($stmt->expr->items) === 1
                && ($only = $stmt->expr->items[0]) !== null
                && $only->value instanceof Variable
                && $only->value->name === $this->context->pendingReport
            ) {
                $this->context->lines[] = $this->reportNode();
                $this->context->lines[] = new Stm('bail', [], $this->context->indent);
                $this->context->reportedInline = true;
                $this->context->pendingReport = null;

                return;
            }

            if ($stmt->expr instanceof Array_) {
                foreach ($stmt->expr->items as $item) {
                    if ($item !== null && $this->isRuleErrorBuilder($item->value)) {
                        $this->takeMessage($item->value);
                        if ($this->context->inLoop || $this->context->inConditionalReport) {
                            // Reporting from inside the loop and returning: emit it here, because
                            // the trailing report would run after the loop has finished.
                            $this->context->lines[] = $this->reportNode();
                            $this->context->lines[] = new Stm('bail', [], $this->context->indent);
                            $this->context->reportedInline = true;
                        }
                    }
                }
            }

            // `return $this->decide(..);` — the rule hands its whole decision to a helper that returns the
            // findings. Nothing above looks at that, so it used to fall through to the bare return below and be
            // *dropped*: the guards translated, the helper's work did not, and the only reason a narrower rule
            // was not emitted is that no message was found either — surfacing much later as "could not find the
            // reported message", which names this transpiler's state and not the cause.
            //
            // Refused where it happens instead. A rule whose message *is* found elsewhere would otherwise emit
            // a plugin missing whatever the helper decides, which is the silent-narrowing shape.
            // Unless a runtime pass stands in for that helper.
            if ($stmt->expr instanceof MethodCall && $this->takeReportingPass($stmt->expr)) {
                return;
            }

            if ($stmt->expr instanceof MethodCall && $this->isOwnMethodCall($stmt->expr)) {
                throw new Refusal(sprintf(
                    'the rule returns whatever %s() decides, and that helper builds the findings rather than '
                    . 'answering a question — so there is nothing here to translate into guards',
                    $this->memberLabel($stmt->expr->name),
                ), $stmt->getStartLine());
            }

            return; // the emitted rule reports once all guards pass
        }

        if ($stmt instanceof Expression && $stmt->expr instanceof Assign) {
            $value = $stmt->expr->expr;

            // $name = $this->something(...);  where a runtime helper answers it. Before the inlining path
            // below, which walks the method's own statements — and a helper stands in for exactly the methods
            // whose statements do not translate, so inlining first refuses inside the method being replaced.
            if ($value instanceof MethodCall
                && $stmt->expr->var instanceof Variable
                && is_string($stmt->expr->var->name)
            ) {
                $answered = $this->resolveCollaboratorCall($value, $stmt->getStartLine());
                if ($answered !== null) {
                    $this->context->locals[$stmt->expr->var->name] = $answered;

                    return;
                }
            }

            // $error = $this->somethingError(...);  where the helper builds the finding itself.
            if ($this->isOwnMethodCall($value)) {
                $this->inlineErrorHelper($value->name->toString(), $value->getArgs(), $stmt->getStartLine(), $stmt->expr->var);

                return;
            }

            // $ruleErrors[] = RuleErrorBuilder::...  — report here and keep looping
            if ($stmt->expr->var instanceof ArrayDimFetch
                && ! $stmt->expr->var->dim instanceof Expr
                && $this->isRuleErrorBuilder($value)
            ) {
                $this->takeMessage($value);
                $this->context->lines[] = $this->reportNode();
                $this->context->reportedInline = true;
                $this->context->reportTaken = true;

                return;
            }

            // $someList[] = <a node>  — an accumulator being filled with what the loop kept, rather than
            // with findings. Only a count is read from it today, and `countable()` is what allows that.
            if ($stmt->expr->var instanceof ArrayDimFetch
                && ! $stmt->expr->var->dim instanceof Expr
                && $stmt->expr->var->var instanceof Variable
                && is_string($stmt->expr->var->var->name)
                && (($this->context->locals[$stmt->expr->var->var->name]['kind'] ?? null) === 'accumulator'
                    || isset($this->context->listAccumulators[$stmt->expr->var->var->name]))
            ) {
                $this->appendToList($stmt->expr->var->var->name, $value, $stmt->getStartLine());

                return;
            }

            if ($this->isRuleErrorBuilder($value)) {
                $this->takeMessage($value);

                // Inside a loop the trailing report is not where this belongs. The loop's guards exit with
                // `continue`, so a report emitted after the loop runs whichever way those guards went — which is
                // every method, including the ones the rule filtered out. Remembered here and emitted at the
                // `return` that follows, because that return is what says whether the rule stops at the first
                // finding or keeps going.
                if ($this->context->inLoop && $stmt->expr->var instanceof Variable && is_string($stmt->expr->var->name)) {
                    $this->context->pendingReport = $stmt->expr->var->name;
                }

                return;
            }

            $this->bindLocal($stmt->expr, $stmt->getStartLine());

            return;
        }

        // `preg_match($pattern, $subject, $matches);` — a match bound through its third argument rather than
        // returned, which is why it arrives as a bare statement. Nothing is emitted: the binding remembers the
        // pattern and the subject, and each read of a group runs the match where it is read. The match is pure,
        // so running it twice costs a little and cannot answer differently.
        if ($stmt instanceof Expression
            && $stmt->expr instanceof FuncCall
            && $stmt->expr->name instanceof Name
            && $stmt->expr->name->toString() === 'preg_match'
        ) {
            $this->bindCaptures($stmt->expr, $stmt->getStartLine());

            return;
        }

        // `++$n;` on a counter. The loop it sits in is already emitted around it, so this is the increment
        // and nothing else — the declaration went in where the counter was bound and the threshold test
        // reads it after the loop closes.
        if ($stmt instanceof Expression
            && ($stmt->expr instanceof PreInc || $stmt->expr instanceof PostInc)
            && $stmt->expr->var instanceof Variable
            && is_string($stmt->expr->var->name)
            && ($this->context->locals[$stmt->expr->var->name]['kind'] ?? null) === 'int'
        ) {
            // Only a counter this transpiler declared, which is the one shape that carries a `php` rendering
            // naming a variable. An integer local folded to its literal has none, and incrementing a literal
            // is not something to emit — it is a rule doing arithmetic this vocabulary does not carry.
            $rendered = $this->context->locals[$stmt->expr->var->name]['php'] ?? null;
            if (! is_string($rendered) || ! str_starts_with($rendered, '$')) {
                throw new Refusal('an increment of something other than a counter', $stmt->getStartLine());
            }

            $this->context->lines[] = new Stm('assign', [
                'target' => substr($rendered, 1),
                'value' => $rendered . ' + 1',
            ], $this->context->indent);

            return;
        }

        // `static $cache = [];` part-way through a helper. Nothing is emitted: a cache is invisible to the
        // answer, and what it stands for is settled by its fill and read back at each use.
        if ($stmt instanceof Static_ && $this->takesACacheStatement($stmt)) {
            return;
        }

        throw new Refusal('statement outside the vocabulary: ' . $this->describe($stmt), $stmt->getStartLine());
    }

    /**
     * A guard either refines a local (emitting a binding) or tests it (emitting an `if ... return`).
     */
    private function translateGuard(Expr $cond, ?string $exit = null): void
    {
        // Refining an instanceof guard into a narrowing binding is only sound when the guard's exit
        // is the plain bail; a `continue` inside a loop must stay a guard. Compare against the
        // backend's bail rather than null, because callers pass it explicitly.
        $exit ??= $this->context->backend->bail();
        if ($exit === $this->context->backend->bail() && $this->tryRefine($cond)) {
            return;
        }

        $this->context->unreachableGuard = null;
        $bail = $this->stripOuterParentheses($this->translateCondition($cond));
        if ($bail === 'false') {
            // Dropping a guard widens the rule, so it is only allowed where the guard is *provably*
            // unreachable and the translation said which proof applies. Without one, refuse: a silently
            // widened rule reports what the original filtered out, and nothing downstream can see it.
            if ($this->context->unreachableGuard === null) {
                throw new Refusal('guard translates to a constant with no reason it cannot hold', $cond->getStartLine());
            }

            $this->context->lines[] = new Stm('comment', ['text' => 'guard dropped: ' . $this->context->unreachableGuard], $this->context->indent);
            $this->context->unreachableGuard = null;

            return;
        }

        if ($bail === 'true') {
            // The other direction of the same fold, and never right: a guard that always exits means the rule can
            // never report anything, so emitting it would produce a plugin that loads and does nothing.
            throw new Refusal(
                'a guard that always exits, so the rule could never report: ' . ($this->context->unreachableGuard ?? 'no reason recorded'),
                $cond->getStartLine(),
            );
        }

        // An identical guard immediately before this one has already decided the same question, so emitting it
        // again is dead weight in the contract. It happens when a helper forwards to the one that decides and
        // both check their argument: `ForwardingHelperRule` emitted
        // `Support::isName(Support::nthExpression($context, $node, 0))` twice in a row, and analysing the
        // generated plugins is what surfaced it — PHPStan proved the second redundant.
        //
        // Only an *adjacent* duplicate, and only at the same indent and exit. Every condition the vocabulary
        // emits is a pure `Support::` predicate over the node, so between two adjacent tests of the same text
        // nothing can have changed; with a statement in between, something could have.
        $previous = $this->context->lines === [] ? null : $this->context->lines[count($this->context->lines) - 1];
        if ($previous instanceof Stm && $previous->kind === 'guard'
            && $previous->indent === $this->context->indent
            && ($previous->args['exit'] ?? null) === $exit
        ) {
            $already = $this->unwrap($previous->args['condition'] ?? '');
            $here = $this->unwrap($bail);

            if ($already === $here) {
                return;
            }

            // And the same question as the *left* half of this one. A guard's condition is the *negated* test —
            // `!(A)` exits unless A holds — so a chain of helpers each re-checking their argument emits `!(A)`
            // and then `!(A && B)`. Past the first, A is true, so the second is `!(B)`; PHPStan reads the
            // untouched form as a conjunct that cannot be false.
            $establishedTest = $this->negatedTest($already);
            $hereTest = $this->negatedTest($here);
            if ($establishedTest !== null && $hereTest !== null
                && str_starts_with($hereTest, $establishedTest . ' && ')
            ) {
                $bail = '!(' . substr($hereTest, strlen($establishedTest . ' && ')) . ')';
            }
        }

        $this->context->lines[] = new Stm('guard', ['condition' => $bail, 'exit' => $exit], $this->context->indent);
    }

    /**
     * The test inside a guard's `!( .. )`, unwrapped, or null when the condition is not that shape.
     *
     * A guard exits when its condition holds, so the condition a rule's `if (! $x) { return []; }` becomes is
     * the negation of what the rule is asking. Comparing two guards means comparing what they *ask*.
     */
    private function negatedTest(string $condition): ?string
    {
        $condition = trim($condition);
        if (! str_starts_with($condition, '!(') || ! str_ends_with($condition, ')')) {
            return null;
        }

        return $this->unwrap(substr($condition, 1));
    }

    /**
     * A condition with every fully-wrapping parenthesis removed, for comparing one guard against another.
     *
     * `stripOuterParentheses()` is deliberately conservative about what it rewrites; this only ever feeds a
     * comparison, so it can take them all off. Inlining leaves six layers on a chained predicate, and two
     * conditions that differ only in those are the same question.
     */
    private function unwrap(string $condition): string
    {
        $condition = trim($condition);
        while (str_starts_with($condition, '(') && str_ends_with($condition, ')')) {
            $depth = 0;
            $wraps = true;
            foreach (str_split(substr($condition, 0, -1)) as $character) {
                $depth += $character === '(' ? 1 : ($character === ')' ? -1 : 0);
                if ($depth === 0) {
                    $wraps = false;

                    break;
                }
            }

            if (! $wraps) {
                break;
            }

            $condition = trim(substr($condition, 1, -1));
        }

        return $condition;
    }

    /**
     * `! $x instanceof K { return []; }` -> a narrowing let-binding, when K has a refinement.
     *
     * @phpstan-impure declares the binding it refines into
     */
    private function tryRefine(Expr $cond): bool
    {
        if (! $cond instanceof BooleanNot || ! $cond->expr instanceof Instanceof_) {
            return false;
        }

        $instanceof = $cond->expr;
        if (! $instanceof->class instanceof Name) {
            return false;
        }

        $wanted = $this->resolveClassName($instanceof->class);
        if (! isset(Vocabulary::REFINEMENTS[$wanted])) {
            return false;
        }

        $refinement = Vocabulary::REFINEMENTS[$wanted];

        $subject = $this->resolve($instanceof->expr, $instanceof->getStartLine());
        if ($subject['kind'] !== 'expr') {
            return false;
        }

        $bind = $this->freshName($instanceof->expr, $wanted);
        $adapter = $refinement['adapter'];
        $this->context->lines[] = new Stm('bind-adapter', ['bind' => $bind, 'adapter' => $adapter, 'subject' => $this->operand($subject)], $this->context->indent);

        if (isset($refinement['field'])) {
            // The binding *is* the field, so record it under the property the rule will read.
            $this->rememberRefined($instanceof->expr, [$refinement['field'] => [$bind, 'expr', '$' . $bind]]);

            return true;
        }

        $fields = [];
        foreach ($refinement['fields'] as $property => $spec) {
            [$template, $kind] = $spec;
            $entry = [str_replace('{bind}', $bind, $template), $kind];
            if (isset($spec[2])) {
                $entry[2] = str_replace('{bind}', '$' . $bind, $spec[2]);
            }

            $fields[$property] = $entry;
        }

        $this->rememberRefined($instanceof->expr, $fields);

        return true;
    }

    /**
     * Records that the given PHP expression has been narrowed, so later reads of its properties
     * resolve to the binding. Keyed by the PHP source shape, so both `$node->var` and the local
     * that aliases it see the refinement.
     * @param array<string, array{0: string, 1: string, 2?: string}> $fields
     */
    private function rememberRefined(Expr $subject, array $fields): void
    {
        $key = $this->exprKey($subject);
        $this->context->refinements[$key] = $fields;

        // A local that aliases this expression inherits the refinement.
        foreach ($this->context->locals as $name => $descriptor) {
            if (($descriptor['key'] ?? null) === $key) {
                $this->context->locals[$name]['fields'] = $fields;
            }
        }
    }

    private function exprKey(Expr $expr): string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            $local = $this->context->locals[$expr->name] ?? null;

            return $local['key'] ?? ('$' . $expr->name);
        }

        if ($expr instanceof PropertyFetch && $expr->var instanceof Variable) {
            return $this->exprKey($expr->var) . '->' . $this->memberName($expr->name, $expr->getStartLine());
        }

        return spl_object_hash($expr);
    }

    private function freshName(Expr $subject, string $kind): string
    {
        $base = $subject instanceof PropertyFetch ? (string) $subject->name
            : ($subject instanceof Variable && is_string($subject->name) ? $subject->name : 'value');

        $short = substr($kind, (int) strrpos('\\' . $kind, '\\'));
        ++$this->context->bindCounter;

        return Emitter::snake($base) . '_' . Emitter::snake($short) . ($this->context->bindCounter > 1 ? (string) $this->context->bindCounter : '');
    }

    /** A collector skips a node by returning null, where a rule returns an empty array.
     * @param Stmt[] $stmts */
    private function isReturnNull(array $stmts): bool
    {
        if (count($stmts) !== 1) {
            return false;
        }

        $only = $stmts[0];

        return $only instanceof Return_
            && $only->expr instanceof ConstFetch
            && strtolower($only->expr->name->toString()) === 'null';
    }

    /**
     * @param Stmt[] $stmts
     */
    private function isReturnEmptyArray(array $stmts): bool
    {
        if (count($stmts) !== 1) {
            return false;
        }

        $only = $stmts[0];

        return $only instanceof Return_
            && $only->expr instanceof Array_
            && $only->expr->items === [];
    }

    /**
     * `if (COND) { $x = A; } else { $x = B; }` bound as one name, or false when it is not that shape.
     *
     * A ternary written long, and three corpus rules write it: a static call's receiver is a resolved name
     * when the class is written and a rendered type when it is not, and the message quotes whichever it was.
     * Nothing is emitted — the name is bound to a conditional expression, the same way every other local is
     * bound to whatever it stands for.
     *
     * Both branches have to assign the *same* name, and both values have to be text. A name bound to two
     * different kinds would be a descriptor whose kind depends on a runtime branch, which is not a thing the
     * rest of the translation can read.
     */
    private function bindConditionalValue(If_ $stmt): bool
    {
        $else = $stmt->else;
        if ($stmt->elseifs !== [] || ! $else instanceof Else_ || count($stmt->stmts) !== 1 || count($else->stmts) !== 1) {
            return false;
        }

        $then = $this->assignedName($stmt->stmts[0]);
        $otherwise = $this->assignedName($else->stmts[0]);
        if ($then === null || $then !== $otherwise) {
            return false;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('a value bound by a branch, which only the PHP target carries', $stmt->getStartLine());
        }

        $line = $stmt->getStartLine();
        /** @var Expression $thenStatement */
        $thenStatement = $stmt->stmts[0];
        /** @var Expression $elseStatement */
        $elseStatement = $else->stmts[0];
        /** @var Assign $thenAssign */
        $thenAssign = $thenStatement->expr;
        /** @var Assign $elseAssign */
        $elseAssign = $elseStatement->expr;

        // Both sides read as text, through the same reduction a name-taking helper argument goes through: a
        // written name and a rendered type are both strings by the time the message quotes one.
        $first = $this->nameText($this->resolve($thenAssign->expr, $line), $line);
        $second = $this->nameText($this->resolve($elseAssign->expr, $line), $line);

        $this->context->locals[$then] = [
            'rust' => self::PHP_ONLY,
            'kind' => 'bytes',
            'php' => '(' . $this->translateCondition($stmt->cond) . ' ? ' . $first . ' : ' . $second . ')',
        ];

        return true;
    }

    /** The variable a statement assigns, when it is a plain assignment to a simple name. */
    private function assignedName(Stmt $stmt): ?string
    {
        return $stmt instanceof Expression
            && $stmt->expr instanceof Assign
            && $stmt->expr->var instanceof Variable
            && is_string($stmt->expr->var->name)
                ? $stmt->expr->var->name
                : null;
    }

    private function isFlagAssignment(Stmt $stmt): bool
    {
        return $stmt instanceof Expression
            && $stmt->expr instanceof Assign
            && $stmt->expr->var instanceof Variable
            && is_string($stmt->expr->var->name)
            && ($this->context->locals[$stmt->expr->var->name]['kind'] ?? null) === 'bool'
            && $this->isBooleanLiteral($stmt->expr->expr) !== null;
    }

    /** `if (COND) { $flag = ..; } else { $other = ..; }` — a branch, not a guard. */
    private function translateFlagBranch(If_ $stmt): void
    {
        if ($stmt->else instanceof Else_
            && (count($stmt->else->stmts) !== 1 || ! $this->isFlagAssignment($stmt->else->stmts[0]))
        ) {
            throw new Refusal('else branch that does more than set a flag', $stmt->getStartLine());
        }

        $condition = $this->translateCondition($stmt->cond);

        $this->context->lines[] = new Stm('if-open', ['condition' => $condition], $this->context->indent);
        $this->context->indent += 4;
        $this->translateStatement($stmt->stmts[0]);
        $this->context->indent -= 4;

        if ($stmt->else instanceof Else_) {
            $this->context->lines[] = new Stm('else', [], $this->context->indent);
            $this->context->indent += 4;
            $this->translateStatement($stmt->else->stmts[0]);
            $this->context->indent -= 4;
        }

        $this->context->lines[] = new Stm('block-close', [], $this->context->indent);
    }

    /**
     * Lowers `foreach` to a Rust `for`.
     *
     * Only iterables with a known element kind are accepted: the loop variable's descriptor is what
     * makes the body translatable, so iterating something unmodelled would produce guesses.
     */
    private function translateForeach(Foreach_ $stmt): void
    {
        if ($stmt->byRef) {
            throw new Refusal('foreach by reference', $stmt->getStartLine());
        }

        if ($stmt->keyVar instanceof Expr && ! $this->isCollectedSubject($stmt->expr)) {
            $mapped = $this->translateMapForeach($stmt);
            if ($mapped) {
                return;
            }

            // Otherwise the only keyed iteration modelled is over collected data, whose key is the file path.
            throw new Refusal('foreach with a key', $stmt->getStartLine());
        }

        if ($stmt->valueVar instanceof Array_ || $stmt->valueVar instanceof List_) {
            $this->translateDestructuringForeach($stmt);

            return;
        }

        if (! $stmt->valueVar instanceof Variable || ! is_string($stmt->valueVar->name)) {
            throw new Refusal('foreach into something other than a simple variable', $stmt->getStartLine());
        }

        $subject = $this->resolve($stmt->expr, $stmt->getStartLine());

        // PHPStan hands collected data back as file => list-of-data, so rules walk it with two nested
        // loops. Mago's store is flat and each datum carries its own position, so the outer loop has
        // nothing to iterate: it collapses, and only the inner one is emitted.
        if ($subject['kind'] === 'collected' && $stmt->keyVar instanceof Expr) {
            $savedLocals = $this->context->locals;
            $savedLiterals = $this->context->literals;
            $savedCaches = $this->context->caches;
            $this->context->locals[$stmt->valueVar->name] = ['rust' => $subject['rust'], 'kind' => 'collected'];
            try {
                foreach ($stmt->stmts as $inner) {
                    $this->translateStatement($inner);
                }
            } finally {
                $this->context->locals = $savedLocals;
                $this->context->literals = $savedLiterals;
                $this->context->caches = $savedCaches;
            }

            return;
        }

        // A rule looping the classes a type names iterates the list, not the single-class reduction.
        if ($subject['kind'] === 'sole-class' && Transpiler::$target === 'php') {
            $subject = [
                'rust' => self::PHP_ONLY,
                'kind' => 'class-names',
                'php' => $this->handlePart($subject, 'listPhp', $stmt->getStartLine()),
            ];
        }

        if (! isset(Vocabulary::ITERABLES[$subject['kind']])) {
            throw new Refusal($this->noIterationRefusal($stmt->expr, $subject['kind']), $stmt->getStartLine());
        }

        $iterable = Vocabulary::ITERABLES[$subject['kind']];
        $variable = Emitter::snake($stmt->valueVar->name);

        $savedLocals = $this->context->locals;

        $savedLiterals = $this->context->literals;

        $savedCaches = $this->context->caches;
        $savedLoop = $this->context->inLoop;
        $this->context->locals[$stmt->valueVar->name] = ['rust' => $variable, 'kind' => $iterable['item']];
        if (isset($subject['as'])) {
            // Every item of a list of found nodes is of the kind that was searched for.
            $this->context->locals[$stmt->valueVar->name]['as'] = $subject['as'];
        }

        if (Transpiler::$target === 'php') {
            $this->context->locals[$stmt->valueVar->name]['php'] = '$' . $variable;
        }

        $this->context->inLoop = true;
        ++$this->context->loopDepth;

        // Remembered before the open is pushed, so a record folded inside this loop can splice its
        // declarations in front of it. See {@see TranslationContext::$loopOpenIndex}.
        $this->context->loopOpenIndex[] = count($this->context->lines);

        $this->context->lines[] = new Stm('foreach-open', [
            'variable' => $variable,
            // Rust iterates with `.iter()`; PHP's `foreach` takes the list directly, so a descriptor
            // kind may carry a second template for this target.
            'iterable' => Transpiler::$target === 'php'
                ? str_replace('{rust}', $this->operand($subject), $iterable['phpIter'] ?? '{rust}')
                : str_replace('{rust}', $subject['rust'], $iterable['iter']),
        ], $this->context->indent);
        $this->context->indent += 4;

        try {
            foreach ($stmt->stmts as $inner) {
                $this->translateStatement($inner);
            }
        } finally {
            $this->context->indent -= 4;
            $this->context->inLoop = $savedLoop;
            --$this->context->loopDepth;
            array_pop($this->context->loopOpenIndex);
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
        }

        $this->context->lines[] = new Stm('block-close', [], $this->context->indent);
    }

    private function isCollectedSubject(Expr $expr): bool
    {
        try {
            return $this->resolve($expr, $expr->getStartLine())['kind'] === 'collected';
        } catch (Refusal) {
            return false;
        }
    }

    /** `foreach ($collected as [$a, $b, $c])` — the datum's values, by position. */
    private function translateDestructuringForeach(Foreach_ $stmt): void
    {
        $subject = $this->resolve($stmt->expr, $stmt->getStartLine());
        if ($subject['kind'] !== 'collected') {
            throw new Refusal('destructuring foreach over something other than collected data', $stmt->getStartLine());
        }

        $savedLocals = $this->context->locals;

        $savedLiterals = $this->context->literals;

        $savedCaches = $this->context->caches;
        $savedLoop = $this->context->inLoop;
        $this->context->inLoop = true;
        ++$this->context->loopDepth;

        $pad = str_repeat(' ', $this->context->indent);
        $this->context->lines[] = new Stm('for-open', ['subject' => $subject['rust']], $this->context->indent);
        $this->context->indent += 4;

        $bindings = [];
        if (! $stmt->valueVar instanceof Array_) {
            throw new Refusal('destructuring foreach over something other than a list', $stmt->getStartLine());
        }

        foreach ($stmt->valueVar->items as $index => $item) {
            if ($item === null || ! $item->value instanceof Variable || ! is_string($item->value->name)) {
                throw new Refusal('destructuring into something other than simple variables', $stmt->getStartLine());
            }

            $name = Emitter::snake($item->value->name);
            $bindings[count($this->context->lines)] = $name;
            $this->context->lines[] = new Stm('collected-value', ['name' => $name, 'index' => (string) $index], $this->context->indent);
            $this->context->locals[$item->value->name] = ['rust' => $name, 'kind' => 'collected-value'];
        }

        $this->context->lines[] = new Stm('blank');
        $bodyStart = count($this->context->lines);

        $savedSpan = $this->context->reportSpan;
        $this->context->reportSpan = 'item.span';

        try {
            foreach ($stmt->stmts as $inner) {
                $this->translateStatement($inner);
            }
        } finally {
            $this->context->indent -= 4;
            $this->context->inLoop = $savedLoop;
            --$this->context->loopDepth;
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
            $this->context->reportSpan = $savedSpan;
        }

        // A datum the body never reads still has to be destructured to keep the positions lined up
        // with the collector's tuple, but Rust warns about it unless the name says so.
        $body = $this->emitter->renderRange($bodyStart);
        foreach ($bindings as $index => $name) {
            if (! str_contains($body, $name)) {
                $this->context->lines[$index]->unused = true;
            }
        }

        $this->context->lines[] = "{$pad}}\n\n";
    }

    /** `return [$a, $b];` in a collector becomes a push into the cross-file store. */
    private function translateCollect(Return_ $stmt): void
    {
        if (! $stmt->expr instanceof Array_) {
            throw new Refusal('collector returns something other than a list of values', $stmt->getStartLine());
        }

        $values = [];
        foreach ($stmt->expr->items as $item) {
            if ($item === null) {
                throw new Refusal('collector returns a list with a hole', $stmt->getStartLine());
            }

            $values[] = $this->stringValue($item->value, $stmt->getStartLine()) . '.to_string()';
        }

        $pad = str_repeat(' ', $this->context->indent);
        $this->context->lines[] = new Stm('raw', ['text' => $pad . "support::collect(\"{$this->context->collectorName}\", context, node.span(), vec![\n"
            . $pad . '    ' . implode(",\n{$pad}    ", $values) . ",\n"
            . $pad . "]);\n"]);
        $this->context->collected = true;
    }

    /**
     * A resolved expression descriptor, rendered for the current target.
     *
     * Descriptors carry Rust in `rust` and, where the mapping is known, PHP in `php`. The PHP SDK's
     * `Node` exposes only id, kind, span and parent, so a Rust field access like `node.class` has no
     * PHP counterpart and must become a navigation helper; a descriptor without a `php` key means
     * that recipe has not been written, and refusing is the only honest answer.
     *
     * @param Descriptor $descriptor
     */
    private function operand(array $descriptor): string
    {
        if (Transpiler::$target !== 'php') {
            return $descriptor['rust'];
        }

        if (! isset($descriptor['php'])) {
            throw new Refusal(sprintf(
                'no PHP navigation for %s (kind %s) on a %s node',
                $descriptor['rust'],
                $descriptor['kind'],
                $this->context->nodeKind === '' ? 'unknown' : $this->context->nodeKind,
            ));
        }

        return $descriptor['php'];
    }

    /** The message a report carries, quoted when the rule wrote a literal and bare when it computed one. */
    private function reportedMessage(): string
    {
        $message = $this->context->message ?? throw new Refusal('reporting before the message is known');
        $literal = str_starts_with($message, '"') && str_ends_with($message, '"');

        return $literal ? $this->context->backend->bytes(substr($message, 1, -1)) : $message;
    }

    private function reportNode(): Stm
    {
        if (Transpiler::$target !== 'php') {
            return new Stm('raw', ['text' => $this->emitter->reportStatement()]);
        }

        if ($this->context->message === null) {
            throw new Refusal('reporting before the message is known');
        }

        if ($this->context->anchorNeedsLoop && ! $this->context->inLoop) {
            throw new Refusal(
                'a report anchored on a loop item but emitted outside the loop, where the item is no longer bound',
            );
        }

        return new Stm('report', [
            'anchor' => $this->context->anchor ?? $this->emitter->defaultAnchor(),
            'message' => $this->reportedMessage(),
            // PHPStan's own identifier is the code, so a finding is labelled the same by both tools. Written
            // as PHP here rather than in the backend: a rule that classifies what it found computes its code,
            // and only this side knows whether the code is a literal to quote or an expression to keep.
            'code' => $this->reportedCode(),
        ], $this->context->indent);
    }

    /**
     * The span `->line(<expr>)` names, as a PHP expression, refusing anything that is not a node's own line.
     *
     * `getLine()` and `getStartLine()` are the same question of a declaration. A computed line number is not:
     * a report points at a node here, not at an integer, so there is nothing to translate it to.
     */
    private function reportAnchor(Expr $expr, int $line): string
    {
        if (Transpiler::$target !== 'php') {
            throw new Refusal('a report moved off the hook node, which only the PHP target carries', $line);
        }

        if (! $expr instanceof MethodCall
            || ! in_array($this->memberName($expr->name, $line), ['getLine', 'getStartLine'], true)
        ) {
            throw new Refusal("a report line that is not a node's own", $line);
        }

        $subject = $this->resolve($expr->var, $line);

        return 'Support::anchor($context, ' . $this->operand($subject) . ')';
    }

    /**
     * An identifier interpolating a classified value, as a target expression, or null when it is a literal.
     *
     * `->identifier("hihaho.debug.noDebugIn{$namespace}")` reports under a code decided at analysis time, and
     * `LifecycleContext::report()` takes its code per call, so a computed one is as valid as a literal. The
     * transpiler used to refuse a second identifier in one rule; the shape here is one identifier
     * *expression*, which is a different thing.
     *
     * Marked so it is emitted as an expression rather than quoted as a literal.
     */
    private function interpolatedIdentifier(Expr $expr, int $line): ?string
    {
        if (! $expr instanceof InterpolatedString) {
            return null;
        }

        $parts = [];
        foreach ($expr->parts as $part) {
            if ($part instanceof InterpolatedStringPart) {
                $parts[] = $this->context->backend->bytes($part->value);

                continue;
            }

            if (! $part instanceof Expr) {
                throw new Refusal('an identifier interpolates something that is not an expression', $line);
            }

            $subject = $this->resolve($part, $line);
            if (! in_array($subject['kind'], ['bytes', 'class-name'], true)) {
                throw new Refusal("an identifier cannot interpolate a {$subject['kind']}", $line);
            }

            $parts[] = $this->operand($subject);
        }

        if (count($parts) < 2) {
            return null;
        }

        $this->context->identifierIsExpression = true;

        return Transpiler::$target === 'php'
            ? implode(' . ', $parts)
            : 'format!("{}", ' . implode(', ', $parts) . ')';
    }

    /** The reported code, written as PHP: quoted when it is a literal, kept as-is when the rule computes it. */
    private function reportedCode(): string
    {
        $identifier = $this->context->identifier ?? throw new Refusal('no identifier to use as the reported code');

        return $this->context->identifierIsExpression ? $identifier : $this->context->backend->bytes($identifier);
    }

    /** Pulls the message and the identifier out of a `RuleErrorBuilder::message(..)->..->build()` chain. */
    private function takeMessage(Expr $chain): void
    {
        while ($chain instanceof MethodCall) {
            if ((string) $chain->name === 'identifier' && count($chain->getArgs()) === 1) {
                // Reset first: a rule that reports several things may compute one code and write the next as a
                // literal, and a flag that only ever turns on left the literal unquoted — a plugin naming an
                // undefined constant. `interpolatedIdentifier()` turns it back on when it applies.
                $this->context->identifierIsExpression = false;
                $identifier = $this->interpolatedIdentifier($chain->getArgs()[0]->value, $chain->getStartLine())
                    ?? $this->rawStringLiteral($chain->getArgs()[0]->value, $chain->getStartLine());
                // A second identifier is only a problem if the first was never reported under: a rule that
                // reports two different things takes one per finding, and the report in between is what makes
                // the change deliberate rather than an overwrite nobody sees.
                if ($this->context->identifier !== null && $this->context->identifier !== $identifier && ! $this->context->reportTaken) {
                    throw new Refusal('a second identifier before the first was reported', $chain->getStartLine());
                }

                $this->context->identifier = $identifier;
                $this->context->identifiers[] = $identifier;
            }

            // `->line($classMethod->getLine())` moves the finding off the node the hook fired for and onto the
            // member the rule is really talking about. A rule looping a class-like's methods reports one finding
            // per method, and every one of them would otherwise land on the class's own line.
            if ((string) $chain->name === 'line' && count($chain->getArgs()) === 1) {
                $this->context->anchor = $this->reportAnchor($chain->getArgs()[0]->value, $chain->getStartLine());
                $this->context->anchorNeedsLoop = $this->context->inLoop;
            }

            $chain = $chain->var;
        }

        if (! $chain instanceof StaticCall || (string) $chain->name !== 'message') {
            throw new Refusal('error builder chain does not start with message()', $chain->getStartLine());
        }

        $args = $chain->getArgs();
        if (count($args) !== 1) {
            throw new Refusal('message() with more than one argument', $chain->getStartLine());
        }

        $message = $this->translateMessageExpression($args[0]->value);
        if ($this->context->message !== null && $this->context->message !== $message && ! $this->context->reportTaken) {
            throw new Refusal('a second message before the first was reported', $chain->getStartLine());
        }

        $this->context->message = $message;
        $this->context->reportTaken = false;
    }

    /**
     * The builder chain a `return` hands back, unwrapped from the list a rule error is usually wrapped in.
     *
     * PHPStan's own signature is `list<IdentifierRuleError>`, so a helper that builds one finding writes
     * `return [RuleErrorBuilder::message(..)->build()];`. Every place that recognises a returned finding was
     * written against the other spelling — a bare `?RuleError`, which is what a helper called for its value
     * returns — and answered no to the wrapped one. That is why `ExplicitClassPrefixSuffixRule` refused with
     * `guard body is neither return [] nor continue`: the guard bodies inside its three helpers each return
     * one built error inside a one-element array.
     *
     * Only a single-element literal unwraps. Two findings in one return is a different question, and a
     * variable holding the list is not a literal to look inside.
     */
    private function returnedRuleError(Expr $expr): ?Expr
    {
        if ($expr instanceof Array_ && count($expr->items) === 1) {
            $inner = $expr->items[0]->value ?? null;
            if ($inner instanceof Expr && $this->isRuleErrorBuilder($inner)) {
                return $inner;
            }

            return null;
        }

        return $this->isRuleErrorBuilder($expr) ? $expr : null;
    }

    private function isRuleErrorBuilder(Node $expr): bool
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof StaticCall
            && $expr->class instanceof Name
            && $expr->class->getLast() === 'RuleErrorBuilder';
    }

    /**
     * Appends to a list accumulator, declaring it at its binding site the first time.
     *
     * Restricted to appending a *node* — what a subtree search or a member loop yielded — because that is the
     * only accumulator shape the corpus has, and a list of anything else would need a rendering for its items
     * that nothing has pinned down.
     */
    private function appendToList(string $name, Expr $value, int $line): void
    {
        if (Transpiler::$target !== 'php') {
            throw new Refusal('a list a rule builds, which only the PHP target carries', $line);
        }

        $item = $this->resolve($value, $line);
        // A class name renders as a PHP string, so a list of them is as well defined as a list of nodes. The
        // restriction below is about having a rendering for the item, not about what a list may hold.
        if (! in_array($item['kind'], ['expr', 'found-node', 'method-decl', 'class-name', 'bytes'], true)) {
            throw new Refusal("appending a {$item['kind']} to a list", $line);
        }

        if (! isset($this->context->listAccumulators[$name])) {
            [$slot, $indent] = $this->context->accumulatorSlots[$name]
                ?? throw new Refusal("appending to \${$name}, which was never bound to an empty array", $line);

            array_splice($this->context->lines, $slot, 0, [new Stm('declare-list', ['target' => $name], $indent)]);
            // Every later slot moved down by one, so a second accumulator declares in the right place too.
            foreach ($this->context->accumulatorSlots as $other => [$otherSlot, $otherIndent]) {
                if ($otherSlot > $slot) {
                    $this->context->accumulatorSlots[$other] = [$otherSlot + 1, $otherIndent];
                }
            }

            $this->context->listAccumulators[$name] = true;
        }

        $this->context->listItemKinds[$name] = $item['kind'];
        $this->context->lines[] = new Stm('append', ['target' => $name, 'value' => $this->operand($item)], $this->context->indent);
    }

    /**
     * `foreach ($configuredMap as $key => $value)` — a configured map walked for both sides.
     *
     * The only keyed iteration besides collected data. A configured map is the one thing the plugin holds where
     * the key carries meaning: `traitRequiresInterface` is trait => interface, and a rule reading only the
     * values would have nothing to check them against.
     *
     * Both sides bind as configured strings, and every comparison against one folds case — metadata lowercases
     * what it holds, which is the same folding PHPStan gets by canonicalising through reflection.
     */
    private function translateMapForeach(Foreach_ $stmt): bool
    {
        if (! $stmt->keyVar instanceof Variable
            || ! is_string($stmt->keyVar->name)
            || ! $stmt->valueVar instanceof Variable
            || ! is_string($stmt->valueVar->name)
        ) {
            return false;
        }

        $subject = $this->resolve($stmt->expr, $stmt->getStartLine());
        if ($subject['kind'] !== 'config-list') {
            return false;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal('a configured map walked by key, which only the PHP target carries', $stmt->getStartLine());
        }

        $key = Emitter::snake($stmt->keyVar->name);
        $value = Emitter::snake($stmt->valueVar->name);

        $savedLocals = $this->context->locals;
        $savedLiterals = $this->context->literals;
        $savedCaches = $this->context->caches;
        $savedLoop = $this->context->inLoop;
        $this->context->inLoop = true;
        unset($this->context->literals[$stmt->keyVar->name], $this->context->literals[$stmt->valueVar->name]);
        $this->context->locals[$stmt->keyVar->name] = ['rust' => $key, 'kind' => 'config-bytes', 'php' => '$' . $key];
        $this->context->locals[$stmt->valueVar->name] = ['rust' => $value, 'kind' => 'config-bytes', 'php' => '$' . $value];

        $this->context->lines[] = new Stm('foreach-keyed-open', [
            'iterable' => $this->operand($subject),
            'key' => $key,
            'variable' => $value,
        ], $this->context->indent);
        $this->context->indent += 4;
        ++$this->context->loopDepth;

        try {
            foreach ($stmt->stmts as $statement) {
                $this->translateStatement($statement);
            }
        } finally {
            --$this->context->loopDepth;
            $this->context->indent -= 4;
            $this->context->inLoop = $savedLoop;
            $this->context->locals = $savedLocals;
            $this->context->literals = $savedLiterals;
            $this->context->caches = $savedCaches;
        }

        $this->context->lines[] = new Stm('block-close', [], $this->context->indent);

        return true;
    }

    /**
     * Binds the third argument of `preg_match()` to the match, without emitting anything.
     *
     * A rule reads a named group out of it — `$matches['method_name']` — and each read runs the match where it
     * is read. That is a deliberate trade: the alternative is emitting a real `preg_match` and carrying its
     * `$matches` array through the translated control flow, and a match is pure, so running it twice costs a
     * little and cannot answer differently.
     */
    private function bindCaptures(FuncCall $match, int $line): void
    {
        if (Transpiler::$target !== 'php') {
            throw new Refusal('a regular-expression match, which only the PHP target carries', $line);
        }

        $args = $match->getArgs();
        if (count($args) !== 3 || ! $args[2]->value instanceof Variable || ! is_string($args[2]->value->name)) {
            throw new Refusal('preg_match() without a plain variable to bind the match to', $line);
        }

        $pattern = $this->stringLiteral($args[0]->value, $line);
        $subject = $this->resolve($args[1]->value, $line);
        if (! in_array($subject['kind'], ['bytes', 'docblock', 'message'], true)) {
            throw new Refusal("preg_match() over a {$subject['kind']}", $line);
        }

        $this->context->locals[$args[2]->value->name] = [
            'rust' => self::PHP_ONLY,
            'kind' => 'captures',
            'php' => self::PHP_ONLY,
            'patternPhp' => $this->context->backend->bytes($pattern),
            'subjectPhp' => $this->operand($subject),
        ];
    }

    /**
     * One named group of a bound match, as a PHP expression.
     *
     * @param Descriptor $captures
     */
    private function capturedGroup(array $captures, string $group, int $line): string
    {
        return 'Support::captured('
            . $this->handlePart($captures, 'patternPhp', $line) . ', '
            . $this->handlePart($captures, 'subjectPhp', $line) . ', '
            . $this->context->backend->bytes($group) . ')';
    }

    /**
     * Whether a name is incremented anywhere in the method being translated.
     *
     * A local assigned an integer literal is ordinarily folded — a read of it emits the literal, which is
     * exact and shorter. A *counter* cannot be: `$n = 0` followed by `++$n` in a loop is a value that
     * changes, and folding it would emit `0` at every read and report a count that is always the initial
     * one. So the binding has to know, at the assignment, what happens to the name later.
     *
     * Looked up in the method rather than guessed from the assignment, because nothing about `$n = 0` says
     * which of the two it is. `SingleRequiredMethodRule` counts `#[Required]` methods this way and reports
     * the count; `TaggedIteratorOverRepeatedServiceCallRule` reaches the same shape through
     * `array_count_values()`.
     */
    /**
     * The field names a record copied into `$name` inside a loop will carry, or null when there is no copy.
     *
     * `$site = null; foreach (..) { $record = $this->flagRecord(..); ..; $site = $record; }` is the shape.
     * `$site` is not an unassigned scalar there — it is the accumulator the loop folds records into, and its
     * fields have to be declared before the loop.
     *
     * Read from the producer's returned array literal rather than by inlining it. Inlining resolves each
     * field against the loop item, which is not bound at the point `$site = null` is translated; the names
     * are all that is needed to declare the locals, and they are written out in the source.
     *
     * PHP only, like the fold it prepares. Asking the same question on a Rust target and answering it here
     * would emit declarations for a materialisation that then refuses.
     *
     * @return list<string>|null
     */
    private function carriedRecordFields(string $name): ?array
    {
        $method = $this->context->currentMethod;
        if (Transpiler::$target !== 'php' || ! $method instanceof ClassMethod) {
            return null;
        }

        $source = null;
        foreach ((new NodeFinder())->findInstanceOf([$method], Assign::class) as $assign) {
            if ($assign->var instanceof Variable
                && $assign->var->name === $name
                && $assign->expr instanceof Variable
                && is_string($assign->expr->name)
            ) {
                $source = $assign->expr->name;

                break;
            }
        }

        if ($source === null) {
            return null;
        }

        // What that name was assigned from: an own-method call, whose returned literal names the fields.
        foreach ((new NodeFinder())->findInstanceOf([$method], Assign::class) as $assign) {
            if (! $assign->var instanceof Variable
                || $assign->var->name !== $source
                || ! $assign->expr instanceof MethodCall
                || ! $this->isOwnMethodCall($assign->expr)
            ) {
                continue;
            }

            $producer = $this->context->currentClass instanceof ClassLike
                ? $this->declaringOf($this->memberName($assign->expr->name, $assign->getStartLine()))
                : null;
            if ($producer === null) {
                return null;
            }

            $body = $this->findMethod($producer['class'], $this->memberName($assign->expr->name, $assign->getStartLine()));

            return $this->returnedRecordKeys($body);
        }

        return null;
    }

    /**
     * The keys of the array literal a producer returns, or null when it returns something else.
     *
     * @return list<string>|null
     */
    private function returnedRecordKeys(ClassMethod $producer): ?array
    {
        $keys = null;
        foreach ((new NodeFinder())->findInstanceOf([$producer], Return_::class) as $return) {
            if (! $return->expr instanceof Array_) {
                continue;
            }

            $found = [];
            foreach ($return->expr->items as $item) {
                if ($item === null || ! $item->key instanceof String_) {
                    return null;
                }

                $found[] = $item->key->value;
            }

            // Two returned literals with different keys are two record shapes, and one set of declarations
            // cannot stand for both. Refused by answering null, which leaves the fold to name the obstacle.
            if ($keys !== null && $keys !== $found) {
                return null;
            }

            $keys = $found;
        }

        return $keys === [] ? null : $keys;
    }

    private function isIncremented(string $name): bool
    {
        $method = $this->context->currentMethod;
        if (! $method instanceof ClassMethod) {
            return false;
        }

        foreach ((new NodeFinder())->findInstanceOf([$method], Expr::class) as $found) {
            if (($found instanceof PreInc || $found instanceof PostInc)
                && $found->var instanceof Variable
                && $found->var->name === $name
            ) {
                return true;
            }
        }

        return false;
    }

    private function bindLocal(Assign $assign, int $line): void
    {
        if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
            throw new Refusal('assignment to something other than a simple local', $line);
        }

        $name = $assign->var->name;
        $value = $assign->expr;

        // `$n = 0` that something later increments is a counter, not a constant. Declared as a real variable
        // and read as one, where an integer local is ordinarily folded to its literal — folding a counter
        // would emit `0` at every read and report a count that never moved off its initial value.
        if ($value instanceof Int_ && $this->isIncremented($name)) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a counter carried across a loop, which only the PHP target carries', $line);
            }

            $local = Emitter::snake($name);
            $this->context->lines[] = new Stm('declare', ['target' => $local, 'value' => (string) $value->value], $this->context->indent);
            $this->context->locals[$name] = ['rust' => '$' . $local, 'kind' => 'int', 'php' => '$' . $local];

            return;
        }

        // $ruleErrors = [];  — the accumulator a rule fills in a loop. Reports are emitted where they
        // are appended, so the binding itself produces no code.
        if ($value instanceof Array_ && $value->items === []) {
            $this->context->locals[$name] = ['rust' => '', 'kind' => 'accumulator'];
            $this->context->accumulatorSlots[$name] = [count($this->context->lines), $this->context->indent];

            return;
        }

        // $x = <a condition>  — a predicate given a name, which reads better in the rule and inlines here.
        // (`preg_match()` is handled where statements are, since it binds through its third argument.)
        // Restricted to expressions that can only be conditions, because translating speculatively can inline
        // a helper as a side effect, and a value that turned out not to be a condition would leave those lines
        // behind. Refused rather than rebound if the name is already taken: an alias that was reassigned would
        // silently stand for the first expression everywhere.
        // `===` and `!==` join them for the same reason: their result can only be a boolean, so translating
        // one as a condition cannot turn out to have been a value. `composer/pcre` names both halves of its
        // test — `$isRegex = $node->class->toString() === Regex::class;` — before combining them.
        if ($value instanceof BooleanAnd || $value instanceof BooleanOr || $value instanceof BooleanNot
            || $value instanceof Identical || $value instanceof NotIdentical
        ) {
            if (isset($this->context->locals[$name])) {
                throw new Refusal("\${$name} is assigned a condition twice, and the second would be ignored", $line);
            }

            $condition = '(' . $this->translateCondition($value) . ')';
            $this->context->locals[$name] = ['rust' => $condition, 'kind' => 'bool', 'php' => $condition];

            return;
        }

        // $flag = true;  /  $flag = false;  — state carried across loop iterations, which needs a
        // real mutable binding rather than a compile-time alias like every other local here.
        $boolean = $this->isBooleanLiteral($value);
        if ($boolean !== null) {
            $this->assignBoolean($name, $boolean === 'true', $line);

            return;
        }

        // $x = sprintf(..)  /  $x = self::SOME_MESSAGE
        if ($value instanceof FuncCall
            && $value->name instanceof Name
            && $value->name->toString() === 'sprintf'
        ) {
            $this->context->locals[$name] = ['rust' => $this->translateSprintf($value), 'kind' => 'message'];

            return;
        }

        // `$x = $scope->getType(<expr>)` — resolved as the expression it is, rather than described here.
        // This used to bind the *node* with the kind `type` and let the type query substitute the receiver,
        // which read as a shortcut and was a lie: it worked only because the receiver was the one position a
        // type could come from. Once any sub-expression can be asked about, the lie emits a helper call with
        // the node where the type belongs — and `getRequirements()` never learns a type was wanted.
        if ($value instanceof MethodCall
            && (string) $value->name === 'getType'
            && $value->var instanceof Variable
            && $value->var->name === 'scope'
            && count($value->getArgs()) === 1
        ) {
            $this->context->locals[$name] = $this->resolve($value, $line);

            return;
        }

        // `$x = $this->reflectionProvider->getConstant($node->name, $scope)` — bound to the node, not to a
        // reflection object. Every question the rule then asks of `$x` is answered from the constant read
        // itself, and mago has no reflection to stand in the middle: `isDeprecated()`, `getName()` and the
        // existence check all go back through {@see Constants::constantMetadata()}, which resolves the name
        // the way PHP does. Binding a descriptor that pretends to be a reflection would put a second
        // spelling of the same lookup in the emitted plugin.
        if ($value instanceof MethodCall
            && (string) $value->name === 'getConstant'
            && count($value->getArgs()) === 2
            && $value->var instanceof PropertyFetch
            && ($this->context->injected[$this->memberName($value->var->name, $line)] ?? null) === 'reflectionProvider'
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a constant lookup, which only the PHP target carries', $line);
            }

            $subject = $this->resolve($value->getArgs()[0]->value, $line);
            $this->context->locals[$name] = [
                'rust' => $this->operand($subject),
                'kind' => 'constant-read',
                'php' => $this->operand($subject),
            ];

            return;
        }

        // $x = $scope->getClassReflection()
        if ($value instanceof MethodCall
            && (string) $value->name === 'getClassReflection'
            && $value->var instanceof Variable
            && $value->var->name === 'scope'
        ) {
            $this->context->locals[$name] = ['rust' => 'context', 'kind' => 'class-reflection'];

            return;
        }

        // $x = $node->getArgs()
        if ($value instanceof MethodCall && (string) $value->name === 'getArgs') {
            $this->context->locals[$name] = Transpiler::$target === 'php'
                ? ['rust' => $this->argListPath($line), 'kind' => 'args', 'php' => $this->argListPath($line)]
                : ['rust' => $this->argListPath($line), 'kind' => 'args'];

            return;
        }

        // $x = <args>[N]  or  $x = <args>[N]->value  or  $x = $node->getArgs()[N]
        $argIndex = $this->argIndexOf($value);
        if ($argIndex !== null) {
            [$index, $unwrapped, $list] = $argIndex;
            $bind = 'arg' . ($index === 0 ? '' : (string) $index) . '_value';
            $pad = str_repeat(' ', $this->context->indent);
            $this->context->lines[] = new Stm('bind-arg', ['bind' => $bind, 'args' => $this->operand($list), 'index' => (string) $index], $this->context->indent);
            $this->context->locals[$name] = ['rust' => $bind, 'kind' => $unwrapped ? 'expr' : 'arg', 'key' => 'arg' . $index];
            if (Transpiler::$target === 'php') {
                // The binding is a PHP variable, so later reads of the local render as one.
                $this->context->locals[$name]['php'] = '$' . $bind;
            }

            return;
        }

        // $x = <expr>->value  (unwrap an Arg node)
        if ($value instanceof PropertyFetch
            && (string) $value->name === 'value'
            && $value->var instanceof Variable
            && ($this->context->locals[$value->var->name]['kind'] ?? null) === 'arg'
        ) {
            $this->context->locals[$name] = ['rust' => $this->context->locals[$value->var->name]['rust'], 'kind' => 'expr', 'key' => $this->exprKey($value)];
            if (isset($this->context->locals[$value->var->name]['php'])) {
                $this->context->locals[$name]['php'] = $this->context->locals[$value->var->name]['php'];
            }

            return;
        }

        // $x = (string) <expr>  — the cast is not a translation step
        if ($value instanceof Expr\Cast\String_) {
            $subject = $this->resolve($value->expr, $line);
            $this->context->locals[$name] = $subject + ['key' => $this->exprKey($value->expr)];

            return;
        }

        // $x = $node->name->toString()  (a string local, compared against literals later)
        if ($value instanceof MethodCall && (string) $value->name === 'toString') {
            $subject = $this->resolve($value->var, $line);
            $this->context->locals[$name] = ['rust' => $subject['rust'], 'kind' => $subject['kind'], 'key' => $subject['key'] ?? ''];
            if (isset($subject['php'])) {
                // An alias is the same expression under another name, so it renders the same way.
                $this->context->locals[$name]['php'] = $subject['php'];
            }

            return;
        }

        // `$x = null;` — an accumulator opened before the loop that fills it.
        //
        // Bound rather than refused so that the refusal names the fold instead of the initialiser. `null` is
        // an `Expr_ConstFetch` to php-parser, so the old message was "access path outside the vocabulary:
        // Expr_ConstFetch", which reads as a missing table row for constants in general; the real gap is the
        // loop that assigns into this name and the `return` that hands it back.
        //
        // The kind carries no value, so every read of it refuses. That is the point: binding the name is not
        // support for the shape, only an honest place to stand while the shape is refused.
        if ($value instanceof ConstFetch && strtolower((string) $value->name) === 'null') {
            // Unless the method goes on to copy a record into this name inside a loop. Then `null` is the
            // record's own absent state and the fields have to exist here, before the loop, or the copy has
            // nowhere to write and the `!== null` test before it has nothing to ask. Only the field *names*
            // are needed to declare them, and the producer's returned literal carries those — inlining it
            // here would resolve expressions over a loop item that is not bound yet.
            $carried = $this->carriedRecordFields($name);
            if ($carried !== null) {
                $record = [];
                foreach ($carried as $field) {
                    $local = Emitter::snake($name . '_' . $field);
                    $this->context->lines[] = new Stm('declare', ['target' => $local, 'value' => 'null'], $this->context->indent);
                    $record[$field] = ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => '$' . $local, 'local' => true];
                }

                $this->context->locals[$name] = ['rust' => self::PHP_ONLY, 'kind' => 'record', 'record' => $record];

                return;
            }

            $this->context->locals[$name] = ['rust' => self::PHP_ONLY, 'kind' => 'unassigned', 'php' => 'null'];

            return;
        }

        // `$site = $record;` — the fold's own step, and a copy rather than an alias. Aliasing would make the
        // two names one set of locals, and the agreement check that compares them would then compare a value
        // with itself and never hold. Emitted as one assignment per field the accumulator declared.
        if ($value instanceof Variable
            && is_string($value->name)
            && ($this->context->locals[$name]['kind'] ?? null) === 'record'
            && ($this->context->locals[$value->name]['kind'] ?? null) === 'record'
        ) {
            $this->copyRecord($name, $value->name, $line);

            return;
        }

        // $x = <resolvable path>  (plain alias, inheriting any refinement)
        try {
            $subject = $this->resolve($value, $line);
        } catch (Refusal $refusal) {
            throw new Refusal('assignment value outside the vocabulary: ' . $refusal->getMessage(), $line);
        }

        $this->context->locals[$name] = $subject + ['key' => $this->exprKey($value)];
    }

    /** `true` / `false` as a string, or null when the expression is not a boolean literal. */
    private function isBooleanLiteral(Expr $expr): ?string
    {
        if (! $expr instanceof ConstFetch) {
            return null;
        }

        $name = strtolower($expr->name->toString());

        return in_array($name, ['true', 'false'], true) ? $name : null;
    }

    /** Declares the binding on first assignment, and assigns thereafter. */
    private function assignBoolean(string $name, bool $value, int $line): void
    {
        $rust = Emitter::snake($name);
        $literal = $value ? 'true' : 'false';

        if (($this->context->locals[$name]['kind'] ?? null) === 'bool') {
            $this->context->lines[] = new Stm('assign', ['target' => $this->context->locals[$name]['rust'], 'value' => $literal], $this->context->indent);

            return;
        }

        if (isset($this->context->locals[$name])) {
            throw new Refusal("\${$name} is already bound to something that is not a flag", $line);
        }

        $this->context->lines[] = new Stm('declare', ['target' => $rust, 'value' => $literal], $this->context->indent);
        $this->context->locals[$name] = ['rust' => $rust, 'kind' => 'bool'];
        if (Transpiler::$target === 'php') {
            $this->context->locals[$name]['php'] = '$' . $rust;
        }
    }

    /** @return array{int, bool, Descriptor}|null */
    private function argIndexOf(Expr $value): ?array
    {
        $unwrapped = false;
        if ($value instanceof PropertyFetch && (string) $value->name === 'value') {
            $inner = $value->var;
            if ($inner instanceof ArrayDimFetch) {
                $value = $inner;
                $unwrapped = true;
            }
        }

        if (! $value instanceof ArrayDimFetch || ! $value->dim instanceof Int_) {
            return null;
        }

        // The list, so the caller knows *whose* arguments these are: a rule reads `$methodCall->getArgs()[0]` of a
        // call it found, and the hook's own node is not that call.
        $container = $value->var;
        if ($container instanceof MethodCall && (string) $container->name === 'getArgs') {
            return [$value->dim->value, $unwrapped, $this->resolve($container, $container->getStartLine())];
        }

        if ($container instanceof Variable && is_string($container->name)
            && ($this->context->locals[$container->name]['kind'] ?? null) === 'args'
        ) {
            return [$value->dim->value, $unwrapped, $this->context->locals[$container->name]];
        }

        return null;
    }

    /** @param Descriptor|null $subject the node whose arguments these are, or null for the hook's own */
    private function argListPath(int $line, ?array $subject = null): string
    {
        // A found call is a call: its arguments live in the same place, and the only thing the hook's kind decides
        // is whether *this* node has an argument list at all.
        if ($subject !== null) {
            $kind = $subject['as'] ?? null;
            if ($kind === null || ! in_array($kind, self::ARGUMENT_LIST_KINDS, true)) {
                throw new Refusal('no argument list on a ' . ($kind ?? $subject['kind']) . ' node', $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal("a found {$kind}'s arguments, which only the PHP target carries", $line);
            }

            return 'Support::argumentList($context, ' . $this->operand($subject) . ')';
        }

        $kinds = self::ARGUMENT_LIST_KINDS;

        // An instantiation carries one too, and `new Foo;` carries none — which the PHP helper answers as an
        // empty list, the same thing PHPStan's `getArgs()` returns. The Rust field is not optional in the same
        // way, so that target keeps refusing rather than guessing. A nullsafe call is PHP-only for its own
        // reason: {@see Vocabulary::HOOKS} refuses that hook on the Rust targets before this is reached.
        if (Transpiler::$target === 'php') {
            $kinds[] = 'Instantiation';
            $kinds[] = 'NullSafeMethodCall';
        }

        if (! in_array($this->context->nodeKind, $kinds, true)) {
            throw new Refusal("no argument list on a {$this->context->nodeKind} node", $line);
        }

        return Transpiler::$target === 'php'
            ? 'Support::argumentList($context, $node)'
            : '&node.argument_list';
    }

    /**
     * Returns a Rust expression that is true exactly when the PHP condition is true.
     *
     * Impure, and load-bearingly so: translating a condition records whether it needs the class metadata,
     * whether it narrowed the rule to classes, and why a guard cannot hold in Mago's model. A caller reads
     * that state back afterwards, so it has to be understood as written *during* this call.
     *
     * @phpstan-impure
     */
    private function translateCondition(Expr $cond): string
    {
        if ($cond instanceof BooleanNot) {
            $inner = $cond->expr;
            if ($inner instanceof BooleanAnd || $inner instanceof BooleanOr) {
                // The inner form may already be parenthesised, and `!((..))` is a lint.
                return '!(' . $this->stripOuterParentheses($this->translateCondition($inner)) . ')';
            }

            return $this->translatePredicate($inner, negated: true);
        }

        if ($cond instanceof BooleanAnd) {
            return $this->combine(
                '&&',
                $this->parenthesiseDisjunction($cond->left),
                $this->parenthesiseDisjunction($cond->right),
            );
        }

        if ($cond instanceof BooleanOr) {
            return $this->combine('||', $this->translateCondition($cond->left), $this->translateCondition($cond->right));
        }

        return $this->translatePredicate($cond, negated: false);
    }

    /**
     * Joins two translated operands, folding away one that cannot hold in Mago's model.
     *
     * A guard can mix a question Mago answers with one it settles by construction — `! $node->name instanceof
     * Identifier || $this->isVirtualNullsafeCall($node)`, where the second is about a synthetic node PHPStan
     * dispatches and Mago has no equivalent of. `unreachable()` is the wrong tool for that: it marks the whole
     * guard as dropped, which would take the first question with it. Folding the operand keeps the guard and
     * removes only the part that is constant.
     *
     * The parenthesis-stripping is not incidental: an inlined helper hands its expression back wrapped, so the
     * first version of this compared against a bare `false`, missed `(false)`, and emitted `|| (false)` into a
     * plugin. `tests/Fixtures/expected/PositionalFlagOnReceiverRule.php` is what pins that.
     */
    private function combine(string $operator, string $left, string $right): string
    {
        $constant = $operator === '||' ? 'false' : 'true';
        if ($this->stripOuterParentheses($left) === $constant) {
            return $right;
        }

        if ($this->stripOuterParentheses($right) === $constant) {
            return $left;
        }

        return $left . ' ' . $operator . ' ' . $right;
    }

    /**
     * Drops parentheses that wrap the whole expression.
     *
     * An inlined helper is parenthesised so it can sit inside a larger condition, but as the entire
     * condition of an `if` those parentheses are what Rust warns about.
     */
    private function stripOuterParentheses(string $expression): string
    {
        if (! str_starts_with($expression, '(') || ! str_ends_with($expression, ')')) {
            return $expression;
        }

        $depth = 0;
        $length = strlen($expression);
        foreach (str_split($expression) as $index => $character) {
            $depth += ($character === '(' ? 1 : ($character === ')' ? -1 : 0));
            if ($depth === 0 && $index < $length - 1) {
                return $expression; // the opening paren closes early, so it is not a wrapper
            }
        }

        return substr($expression, 1, -1);
    }

    /** `&&` binds tighter than `||` in both languages, but only when the parentheses are kept. */
    private function parenthesiseDisjunction(Expr $cond): string
    {
        $translated = $this->translateCondition($cond);

        return $cond instanceof BooleanOr ? "({$translated})" : $translated;
    }

    private function translatePredicate(Expr $expr, bool $negated): string
    {
        $check = $this->predicate($expr);

        // Both constants fold, not just one. A predicate settled by construction is `true`, and the guard around it
        // is `! true` — left unfolded that emitted `if (!(true))`, a guard that can never hold, carrying whatever
        // comment happened to precede it. Folded, {@see translateGuard()} drops it and states the reason.
        if ($check === 'false' || $check === 'true') {
            $holds = $check === 'true';

            return $negated === $holds ? 'false' : 'true';
        }

        return $negated ? '!(' . $this->stripOuterParentheses($check) . ')' : $check;
    }

    /** Returns a Rust expression that is true exactly when the PHP predicate is true. */
    private function predicate(Expr $expr): string
    {
        if ($expr instanceof Variable && is_string($expr->name)
            && ($this->context->locals[$expr->name]['kind'] ?? null) === 'bool'
        ) {
            return $this->operand($this->context->locals[$expr->name]);
        }

        if ($expr instanceof StaticCall) {
            return $this->staticHelperPredicate($expr);
        }

        if ($expr instanceof Instanceof_) {
            return $this->instanceofPredicate($expr);
        }

        if ($expr instanceof MethodCall) {
            return $this->methodPredicate($expr);
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            return $this->functionPredicate($expr);
        }

        if ($expr instanceof NotIdentical || $expr instanceof Identical) {
            $equal = $this->equality($expr->left, $expr->right, $expr->getStartLine());

            return $expr instanceof NotIdentical ? "!({$equal})" : $equal;
        }

        if ($expr instanceof GreaterOrEqual || $expr instanceof Greater
            || $expr instanceof SmallerOrEqual || $expr instanceof Smaller
        ) {
            return $this->intComparison($expr);
        }

        if ($expr instanceof Bool_) {
            return $this->boolCast($expr);
        }

        if ($expr instanceof Isset_) {
            return $this->issetOverConstant($expr);
        }

        return $this->remainingPredicate($expr);
    }

    /**
     * The predicate shapes that are not calls, comparisons or `instanceof`.
     *
     * Split off so {@see predicate()} stays a dispatch. A `match` used as a set membership test, and a bare
     * flag read on an argument, are the two that reach here.
     */
    private function remainingPredicate(Expr $expr): string
    {
        if ($expr instanceof Match_) {
            return $this->matchAsOneOf($expr);
        }

        // `$arg->unpack` is a bare flag read used as a condition. php-parser sets it on the `Arg`; Mago spells
        // a spread only in the argument's text, which is what the helper reads.
        if ($expr instanceof PropertyFetch) {
            $subject = $this->resolve($expr, $expr->getStartLine());
            if ($subject['kind'] === 'argument-unpack') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a spread-argument test, which only the PHP target carries', $expr->getStartLine());
                }

                return 'Support::argumentIsUnpacked(' . $this->operand($subject) . ')';
            }
        }

        throw new Refusal('condition outside the vocabulary: ' . $this->describe($expr), $expr->getStartLine());
    }

    private function staticHelperPredicate(StaticCall $expr): string
    {
        if (! $expr->class instanceof Name) {
            throw new Refusal('static call on a dynamic class name', $expr->getStartLine());
        }

        $helper = $expr->class->getLast();
        $method = $this->memberName($expr->name, $expr->getStartLine());
        $args = $expr->getArgs();

        // `Strings::match($subject, $pattern)` as a bare condition rather than through `=== null`. The truthy
        // and the non-null answers are the same one: Nette returns the capture array or null, and a match of
        // the empty string still gives `['']`, which is a non-empty array. So this reduces to the test
        // {@see patternTest()} already emits, and reaching it from here is the whole of the change — the
        // helper, its semantics and its `bytesValue()` handling of a pattern's backslashes were already there.
        $match = $this->patternTest($expr, $expr->getStartLine());
        if ($match !== null) {
            return $match;
        }

        if ($helper === 'NamingHelper' && $method === 'isName' && count($args) === 2) {
            $literal = $this->stringLiteral($args[1]->value, $expr->getStartLine());

            return $this->nameEquals($this->resolve($args[0]->value, $expr->getStartLine()), $literal, $expr->getStartLine());
        }

        // The plural, whose body is `array_any($names, fn ($name) => self::isName($node, $name))` — the same
        // question of a list, which {@see oneOf} already answers. Found by auditing what refusals name rather
        // than by reading the vocabulary: without this the call falls through to generic resolution, which
        // tries to resolve the list and refuses with `access path outside the vocabulary: Expr_Array`. Two
        // rules were refused that way, and the array literal was never the obstacle.
        if ($helper === 'NamingHelper' && $method === 'isNames' && count($args) === 2) {
            return $this->oneOf(
                $args[0]->value,
                $this->stringList($args[1]->value, $expr->getStartLine()),
                'NamingHelper::isNames()',
                $expr->getStartLine(),
                $this->classNameList($args[1]->value, $expr->getStartLine()),
            );
        }

        if ($helper === 'MethodCallNameAnalyzer' && $method === 'isThisMethodCall' && count($args) === 2) {
            $literal = $this->stringLiteral($args[1]->value, $expr->getStartLine());

            return $this->context->backend->call('is_this_method_call', [
                ...(Transpiler::$target === 'php' ? ['$context', '$node'] : ['node']),
                $this->context->backend->bytes($literal),
            ]);
        }

        // `self::other()` inside an analyzer class already being inlined: the class is the one we are in, so it
        // needs no lookup. Without this, `findClassByName('self')` finds nothing and the refusal names `self`,
        // which points at no file anyone can open.
        if (in_array($helper, ['self', 'static'], true) && $this->context->currentClass instanceof ClassLike) {
            return $this->inlineMethod($this->context->currentClass, $method, $args, $expr->getStartLine(), $this->context->useMap);
        }

        // Any other static helper whose source we can find is inlined rather than hand-translated — unless a
        // runtime helper stands in for it. Asked here rather than at the call site because the table is keyed
        // on the fully qualified name, and only the index knows which file a short name resolved to.
        $helperClass = $this->findClassByName($helper);
        if ($helperClass !== null) {
            $stood = $this->staticHelperStandIn($helperClass, $method, $expr->getStartLine());
            if ($stood !== null) {
                return $stood;
            }

            return $this->inlineMethod($helperClass['class'], $method, $args, $expr->getStartLine(), $helperClass['uses']);
        }

        throw new Refusal("unknown static helper {$helper}::{$method}()", $expr->getStartLine());
    }

    /**
     * The runtime helper that stands in for a static collaborator method, or null when none does.
     *
     * The same table {@see resolveCollaboratorCall()} reads, reached from the other kind of call. A helper
     * stands in for exactly the methods whose statements do not translate, so this has to be asked *before*
     * the inliner runs — otherwise the refusal names a statement inside a file the rule under test does not
     * even declare.
     *
     * Only the PHP target. The Rust targets have no runtime to call, and refusing by name here leaves them
     * saying what is missing rather than inlining a body that will refuse a few lines further in.
     *
     * @param array{class: ClassLike, uses: array<string, string>, namespace: string|null} $helperClass
     */
    private function staticHelperStandIn(array $helperClass, string $method, int $line): ?string
    {
        $entry = Vocabulary::COLLABORATOR_CALLS[$this->fullyQualified($helperClass) . '::' . $method] ?? null;
        if ($entry === null) {
            return null;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal("{$method}() is answered by a runtime helper, which only the PHP target carries", $line);
        }

        if ($entry['receiverType'] ?? false) {
            $this->context->usesReceiverType = true;
        }

        $this->context->runtimeHelpers[explode('::', $entry['helper'])[0]] = true;

        return $entry['helper'] . '($context, $node)';
    }

    private function instanceofPredicate(Instanceof_ $expr): string
    {
        if (! $expr->class instanceof Name) {
            throw new Refusal('instanceof with a dynamic class', $expr->getStartLine());
        }

        $wanted = $this->resolveClassName($expr->class);
        $subject = $this->resolve($expr->expr, $expr->getStartLine());

        // `$type instanceof TypeWithClassName` asks whether the type names one class, and every rule that asks
        // goes on to use that name — so the descriptor is the name, and the test is that there is one. The same
        // reduction the receiver-typed rules already use for `getObjectClassReflections()`.
        if ($subject['kind'] === 'type' && $wanted === 'PHPStan\Type\TypeWithClassName') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a class-named type test, which only the PHP target carries', $expr->getStartLine());
            }

            return 'Support::soleObjectClass(' . $this->operand($subject) . ') !== null';
        }

        // `$type instanceof ConstantStringType` asks whether the type is one literal string. Mago renders such
        // a type as plain `string`, and carries the literal on the scalar's refinement — probed, because the
        // rendering answers "not constant" for every string there is.
        if ($subject['kind'] === 'type' && $wanted === 'PHPStan\Type\Constant\ConstantStringType') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a constant-string type test, which only the PHP target carries', $expr->getStartLine());
            }

            return 'Support::constantStringOf(' . $this->operand($subject) . ') !== null';
        }

        // `$type instanceof ObjectType` is a *type* test, not a node test.
        if ($wanted === ObjectType::class) {
            // A null-stripped type is still a type; the kind only records that `removeNull` was applied.
            if (! in_array($subject['kind'], ['type', 'type-without-null'], true)) {
                throw new Refusal('ObjectType test on something that is not a resolved type', $expr->getStartLine());
            }

            if (Transpiler::$target === 'php') {
                // The descriptor already carries how the type is reached, and which requirement that needs was
                // recorded where it was built. This used to insist on the receiver, on the belief that no other
                // position was exposed; a probe says a node hook can ask about any sub-expression.
                return $this->context->backend->call('type_is_named_object', [$this->operand($subject)]);
            }

            return "support::type_is_named_object(context, {$subject['rust']})";
        }

        // `$node instanceof MethodCall` on the hook node of a rule registered for every expression: the branch
        // says which concrete kind it handles, and the plugin registered all of them, so the test is a
        // node-kind test. Only where the hook names its kinds — elsewhere the hook *is* the kind and the
        // question is settled by which hook fired.
        // `$type instanceof UnionType` — Mago models a type as its atomic parts, so a union is one with more
        // than one part. True of `A|null` on both sides, which is the case a count would otherwise disagree on.
        if ($wanted === 'PHPStan\\Type\\UnionType' && in_array($subject['kind'], ['type', 'type-without-null'], true)) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a union-type test, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->context->backend->call('type_is_union', [$this->operand($subject)]);
        }

        // `$node->name instanceof Identifier` — php-parser types a written member name as an identifier and a
        // computed one as an expression. Mago spells the same split structurally, which is what this reads.
        if ($wanted === Identifier::class && $subject['kind'] === 'name-part') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a written-name test, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->context->backend->call('is_written_name', [$this->operand($subject)]);
        }

        // `$node->class instanceof Expr` — php-parser types a written class part as `Name` and anything computed
        // as an expression, so this asks "is the class dynamic". Mago has no such split in the tree; the
        // question is whether the part is a written name.
        if ($wanted === Expr::class && in_array($subject['kind'], ['expr', 'name-expr', 'name-part'], true)) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a dynamic-name test, which only the PHP target carries', $expr->getStartLine());
            }

            // Two spellings of the same question, and they need different helpers. A *class* part arrives
            // unwrapped, so a written one is a name node. A *member* part arrives as its selector, which is
            // never a name node — asking `isName()` of one answers false for every call there is, and the
            // guard would then report on all of them.
            $helper = $subject['kind'] === 'name-part' ? 'is_written_name' : 'is_name';

            return '! ' . $this->context->backend->call($helper, [$this->operand($subject)]);
        }

        if ($subject['kind'] === 'hook-node'
            && isset(Vocabulary::EXPRESSION_KINDS[$wanted])
            && $this->context->hookKinds !== []
        ) {
            if (! in_array(Vocabulary::EXPRESSION_KINDS[$wanted], $this->context->hookKinds, true)) {
                return $this->unreachable("this plugin does not register {$wanted}, so the branch never runs");
            }

            // `instanceof MethodCall` also holds for `?->` on PHPStan's side: it desugars a nullsafe call into
            // a `MethodCall` carrying a `virtualNullsafeMethodCall` attribute, which is why
            // `hihaho/phpstan-rules` has a trait method that tests for exactly that. Mago keeps the two kinds
            // apart, so the test has to name both or the port stays silent where the original reports —
            // measured on a fixture pair, where PHPStan reported the nullsafe call and the port did not.
            $kinds = [Vocabulary::EXPRESSION_KINDS[$wanted]];
            if ($wanted === MethodCall::class && in_array('NullSafeMethodCall', $this->context->hookKinds, true)) {
                $kinds[] = 'NullSafeMethodCall';
            }

            $tests = [];
            foreach ($kinds as $kind) {
                $tests[] = $this->context->backend->call('node_kind_is', ['$context', '$node', $this->context->backend->bytes($kind)]);
            }

            return count($tests) === 1 ? $tests[0] : '(' . implode(' || ', $tests) . ')';
        }

        if ($wanted === Class_::class && $subject['kind'] === 'hook-node') {
            return $this->classHookIsClass();
        }

        if ($subject['kind'] === 'hint-option' || $subject['kind'] === 'hint') {
            $suffix = $subject['kind'] === 'hint-option' ? '_option' : '';
            $hintPredicates = [
                UnionType::class => 'hint_is_union',
                IntersectionType::class => 'hint_is_intersection',
                Name::class => "hint{$suffix}_is_name",
            ];
            if (! isset($hintPredicates[$wanted])) {
                throw new Refusal("instanceof {$wanted} on a type hint", $expr->getStartLine());
            }

            // `hint_option_is_name` and `hint_is_name` differ only in whether the hint may be absent,
            // which a null-tolerant PHP helper covers with one name.
            $helper = Transpiler::$target === 'php'
                ? str_replace('_option', '', $hintPredicates[$wanted])
                : $hintPredicates[$wanted];

            return $this->context->backend->call($helper, [$this->operand($subject)]);
        }

        if ($wanted === ClassReflection::class) {
            // `$x instanceof ClassReflection` where `$x` came from `getClass(<a value>)`. The reflection object
            // has no equivalent here — the handle *is* the name — so the test is whether the codebase knows
            // that name, which is the `hasClass()` the producing helper guards with. Emitted rather than
            // folded away: whether that guard has already run is a fact about the inliner, and a redundant
            // test is cheap where a wrong assumption is not.
            if ($subject['kind'] === 'named-class') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a named-class test, which only the PHP target carries', $expr->getStartLine());
                }

                return $this->context->backend->call('class_exists', ['$context', $this->operand($subject)]);
            }

            if ($subject['kind'] !== 'class-reflection') {
                // Names what it is. A rule holding a `?ClassReflection` the package *wires* — the facade
                // reflection two debug rules take in their constructor — is stopped by that service having no
                // equivalent, not by the `instanceof` around it, and the old message hid which.
                throw new Refusal(sprintf(
                    'ClassReflection test on a %s%s',
                    $subject['kind'],
                    $subject['kind'] === 'service' ? ', which the plugin has no equivalent for' : '',
                ), $expr->getStartLine());
            }

            return $this->context->classFrom === 'metadata'
                ? $this->alwaysHolds('a declaration hook fires inside a class-like, so there is always an enclosing class')
                : $this->context->backend->call('is_in_class', Transpiler::$target === 'php' ? ['$context', '$node'] : ['context']);
        }

        if ($subject['kind'] === 'name-selector') {
            if ($wanted === Identifier::class) {
                return $this->context->backend->call('selector_is_identifier', [$this->operand($subject)]);
            }

            throw new Refusal("instanceof {$wanted} on a member selector", $expr->getStartLine());
        }

        // `$node->name instanceof Identifier` on the class-like under analysis asks whether it is named. Mago makes
        // an anonymous class a separate node kind, so this hook only ever fires for a named one — the same
        // reasoning that makes `isAnonymous()` unreachable here.
        if ($wanted === Identifier::class
            && ($subject['key'] ?? null) === '$node->name'
            && in_array($this->context->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)
        ) {
            return $this->alwaysHolds(
                'an anonymous class is a separate node kind, so this hook only fires for a named class-like',
            );
        }

        // `$node instanceof ClassMethod` inside a helper that takes `ClassLike|ClassMethod`: the caller passed a
        // method declaration, so the narrowing holds by construction here.
        if ($subject['kind'] === 'method-decl' && $wanted === ClassMethod::class) {
            return $this->alwaysHolds('the caller passed a method declaration, so this narrowing holds by construction');
        }

        // `$firstItem instanceof ArrayItem` asks whether the literal has an element at that position, since
        // php-parser can hold a hole there and the read answers null.
        if ($subject['kind'] === 'array-element') {
            return $this->operand($subject) . ' !== null';
        }

        // The same test on a method *looked up by name* is a real question, and dropping it was a defect: the
        // lookup answers null for a class that declares no such method, and every predicate below is
        // null-tolerant, so a missing provider reported as a non-static one.
        if ($subject['kind'] === 'maybe-method-decl' && $wanted === ClassMethod::class) {
            return $this->operand($subject) . ' !== null';
        }

        // `$docComment instanceof Doc` asks whether the declaration has a docblock at all, and the descriptor is
        // already its text, so the question is whether that text exists.
        // `$docComment instanceof Doc` asks whether the declaration has a docblock at all, and `$parsed
        // instanceof PhpDocNode` asks the same of a parse that only happens when there is one. Both are "is
        // there a docblock here", and the descriptor is already its text.
        if ($subject['kind'] === 'docblock') {
            if ($wanted !== Doc::class && $wanted !== 'PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode') {
                throw new Refusal("instanceof {$wanted} on a docblock", $expr->getStartLine());
            }

            return $this->operand($subject) . ' !== null';
        }

        // `$arg->value instanceof ConstFetch` asks whether the value is a bare constant name. Mago splits that
        // across two node kinds — a keyword `Literal` for true/false/null, a `ConstantAccess` for anything else
        // — so it is one helper rather than a node-kind test.
        if ($wanted === ConstFetch::class) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a constant-name test, which only the PHP target carries', $expr->getStartLine());
            }

            return 'Support::isConstantName(' . $this->operand($subject) . ')';
        }

        // `$arg->name instanceof Identifier` asks whether the call named the parameter. php-parser answers it
        // with a nullable field; Mago answers it with the node kind under `Argument`.
        if ($subject['kind'] === 'argument-name') {
            if ($wanted !== Identifier::class) {
                throw new Refusal("instanceof {$wanted} on an argument name", $expr->getStartLine());
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('a named-argument test, which only the PHP target carries', $expr->getStartLine());
            }

            return 'Support::argumentIsNamed(' . $this->operand($subject) . ')';
        }

        // `$classLike->name instanceof Identifier` asks whether a class-like is named, which php-parser
        // answers with a nullable field because it models an anonymous class as a `Class_` with no name. Mago
        // gives an anonymous class its own node kind, `AnonymousClass` — probed, not assumed — so a search for
        // class-likes cannot return one and the question is already settled for everything the loop sees.
        if ($subject['kind'] === 'class-like-name') {
            if ($wanted !== Identifier::class) {
                throw new Refusal("instanceof {$wanted} on a class-like's name", $expr->getStartLine());
            }

            return $this->alwaysHolds(
                'a class-like found by a subtree search is always named: Mago models an anonymous class as its '
                . 'own node kind, which a search for classes, interfaces, traits and enums never returns',
            );
        }

        // `$node->namespacedName instanceof Name` on a class-like declaration. PHPStan makes that property
        // null for an anonymous class, which is the only way the test can fail — and a class-like hook never
        // receives one, for the reason the branch above already measured: Mago models an anonymous class as its
        // own `AnonymousClass` kind, and the hook registers `Class`, `Enum` and `Interface`. Probed a second
        // time from this side: a `Class` hook over a file holding an anonymous class fired once, for the named
        // one.
        //
        // Folded rather than translated, because the translation was wrong. `instanceof Name` on a name *string*
        // emitted `Support::isName()`, whose parameter is a `Part` — so the plugin would have died with a
        // TypeError the first time the rule ran. Nothing caught it because neither corpus rule reaching this
        // emits, which is luck rather than safety.
        if ($wanted === Name::class && $subject['kind'] === 'class-name'
            && in_array($this->context->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)
        ) {
            return $this->alwaysHolds(
                'a class-like declaration reaching this hook always has a qualified name: PHPStan leaves it '
                . 'null only for an anonymous class, and Mago gives those their own node kind, which this hook '
                . 'does not register',
            );
        }

        // `$node instanceof Interface_` inside a class-like hook that registers several kinds. The rule is
        // asking which of its targets fired, and the node's own kind answers it — `Support::declarationKindIs`
        // was already there for the reflection spelling of the same question, so nothing new runs.
        //
        // Against the node's kind rather than the rule's `instanceof` class, because the two disagree on the
        // one that matters: php-parser's `Class_` is the base of `Interface_` and `Trait_` in name only, and
        // Mago gives each declaration its own kind.
        if ($subject['kind'] === 'hook-node'
            && in_array($this->context->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)
            && isset(self::DECLARATION_KINDS[$wanted])
        ) {
            return $this->declarationKindIs(self::DECLARATION_KINDS[$wanted], self::DECLARATION_KINDS[$wanted]);
        }

        if ($subject['kind'] === 'extends') {
            if ($wanted === Name::class) {
                return $this->context->backend->call('has_extends', Transpiler::$target === 'php' ? ['$context', '$node'] : ['node']);
            }

            throw new Refusal("instanceof {$wanted} on an extends clause", $expr->getStartLine());
        }

        // `$node->class instanceof FullyQualified` is *not* a question about the spelling, which is what it
        // reads like. PHPStan resolves names before a rule sees the tree, so an imported `Guard::keep()`
        // arrives as a `FullyQualified` node holding the resolved name — measured, not reasoned about: a
        // fixture translating it as a written-name test went silent on the imported call while the original
        // reported it. Answering it needs the resolved name on both sides of the comparison that follows, and
        // `Support::nameEquals()` compares the text as written, so this is refused rather than approximated.
        if ($wanted === FullyQualified::class) {
            throw new Refusal(
                'instanceof FullyQualified, which PHPStan answers after its own name resolution: an imported '
                . 'name arrives as one too, so the test is about resolution rather than spelling and the '
                . 'comparison after it would have to read resolved names',
                $expr->getStartLine(),
            );
        }

        if (! isset(Vocabulary::NODE_PREDICATES[$wanted])) {
            throw new Refusal("no node predicate for instanceof {$wanted} on a {$subject['kind']}", $expr->getStartLine());
        }

        return $this->nodePredicate(Vocabulary::NODE_PREDICATES[$wanted], $subject, $wanted, $expr->getStartLine());
    }

    /**
     * A trinary-logic tail: `->yes()` or `->no()` on a type or scope query.
     *
     * @param 'no'|'yes' $tail
     */
    private function trinaryTailPredicate(MethodCall $inner, string $tail, int $line): string
    {
        $name = $this->memberName($inner->name, $line);
        $args = $inner->getArgs();

        // `->no()` is not `! ->yes()`, and collapsing it that way is wrong on exactly the types PHPStan
        // answers `Maybe` for. Measured on a real corpus: of 243822 inferred types, 4.23 % would make an
        // `isNull()` a `Maybe` and 0.88 % an `isBoolean()` — so the third state is reachable, not theoretical.
        //
        // Refused rather than emitted, and it costs nothing today: 86 of the 93 trinary tails in the installed
        // packages are `->yes()`, six are `->no()`, one is `->maybe()`, and **no emitting rule uses `->no()`**.
        // What this stops is the next one arriving quietly and reporting where the original stays silent.
        //
        // `hasVariableType()->no()` is exempt below, because that one is not a collapse: it maps onto a helper
        // that answers the same question directly rather than onto the negation of another.
        if ($tail === 'no' && $name !== 'hasVariableType') {
            throw new Refusal(
                "->{$name}()->no(), which is not the negation of ->yes(): PHPStan answers Maybe for a type "
                . 'that is partly this and partly not, and the port has one boolean to answer with',
                $line,
            );
        }

        if ($name === 'hasMethod' && count($args) === 1) {
            // The name may be a literal or a value the rule computed — the second array element of
            // `[$this, 'handle']`, read as a constant string — so it arrives as a descriptor either way.
            return $this->negateUnless(
                $tail === 'yes',
                $this->typeQuery($inner, 'type_has_method', $this->methodNameArgument($args, $name, $line), $line),
            );
        }

        // `(new ObjectType(A))->isSuperTypeOf(new ObjectType($b))->yes()` — "is `$b` an A", asked between two
        // types the rule constructed rather than inferred. Mago answers it from the codebase's ancestry, which
        // is the same question without the intermediate objects.
        if ($name === 'isSuperTypeOf' && count($args) === 1) {
            $parent = $this->objectTypeName($inner->var, $line);
            $child = $this->objectTypeName($args[0]->value, $line);
            if ($parent !== null && $child !== null) {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a class-ancestry test between constructed types, which only the PHP target carries', $line);
                }

                return $this->negateUnless(
                    $tail === 'yes',
                    $this->context->backend->call('class_descends_from', ['$context', $child, $parent]),
                );
            }
        }

        // `$type->isCallable()->yes()` — whether the type can be called. Mago carries a callable as one of a
        // type's atomic parts, and a closure as a named object, which is what the helper reads.
        if ($name === 'isCallable' && $args === []) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a callable-type test, which only the PHP target carries', $line);
            }

            return $this->negateUnless(
                $tail === 'yes',
                $this->context->backend->call('type_is_callable', [$this->operand($this->resolve($inner->var, $line))]),
            );
        }

        // `$constantReflection->isDeprecated()->yes()`. PHPStan answers a trinary because a reflection can be
        // unsure; mago answers a flag bit, so this is a definite yes or no and the `maybe` the trinary exists
        // for does not arise. Gated on the subject being a constant read, so no other `isDeprecated()` is
        // silently caught by it.
        if ($name === 'isDeprecated' && $args === []
            && $this->resolve($inner->var, $line)['kind'] === 'constant-read'
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a constant-deprecation test, which only the PHP target carries', $line);
            }

            return $this->negateUnless(
                $tail === 'yes',
                $this->context->backend->call('constant_is_deprecated', [
                    '$context',
                    $this->operand($this->resolve($inner->var, $line)),
                ]),
            );
        }

        // `$type->isBoolean()->yes()` — whether the whole type is boolean. Every atomic has to be one, which
        // is what PHPStan's `yes` means: `bool|null` is a `maybe` there and is not one here either.
        if ($name === 'isBoolean' && $args === []) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a boolean-type test, which only the PHP target carries', $line);
            }

            return $this->negateUnless(
                $tail === 'yes',
                $this->context->backend->call('type_is_boolean', [$this->operand($this->resolve($inner->var, $line))]),
            );
        }

        if ($name === 'isInstanceOf' && count($args) === 1) {
            $literal = $this->classLiteral($args[0]->value, $line);

            return $this->negateUnless(
                $tail === 'yes',
                $this->typeQuery($inner, 'type_is_instance_of', ['rust' => $literal, 'kind' => 'bytes', 'php' => $this->context->backend->bytes($literal)], $line),
            );
        }

        if (
            $name === 'hasVariableType' && count($args) === 1
            && $inner->var instanceof Variable && $inner->var->name === 'scope'
        ) {
            // The rule asks about the scope *before* this node, which only the pre hook can answer.
            $this->context->readsPriorScope = true;
            $variable = $this->variableNameExpression($args[0]->value, $line);

            return $this->negateUnless($tail === 'no', "support::variable_is_undefined(context, {$variable})");
        }

        throw new Refusal("trinary tail on an unsupported query ->{$name}()", $line);
    }

    /**
     * A question asked of an inferred type, with the thing asked about as a descriptor.
     *
     * @param Descriptor $about the name the question names
     */
    private function typeQuery(MethodCall $inner, string $helper, array $about, int $line): string
    {
        $subject = $this->resolve($inner->var, $line);
        $this->requireType($subject, $line);

        if (Transpiler::$target !== 'php') {
            return "support::{$helper}(context, {$subject['rust']}, b\"{$about['rust']}\")";
        }

        // Every type descriptor already carries how it is reached — `$context->receiverType` where the SDK
        // hands it over ready-made, `Support::expressionType()` where the rule asks by node. The old form of
        // this check refused anything but the receiver, on the belief that nothing else was exposed; a probe
        // says otherwise, and the requirement each one needs is recorded where the descriptor is built.
        return $this->context->backend->call($helper, ['$context', $this->operand($subject), $this->operand($about)]);
    }

    /**
     * The class `new ObjectType(<name>)` names, as a PHP expression, or null when the expression is not one.
     *
     * A rule building a type to compare against writes `new ObjectType(Request::class)`; the name is all that
     * survives the translation, because the comparison becomes an ancestry question on the codebase.
     */
    private function objectTypeName(Expr $expr, int $line): ?string
    {
        if (! $expr instanceof New_
            || ! $expr->class instanceof Name
            || $expr->class->getLast() !== 'ObjectType'
            || count($expr->getArgs()) !== 1
        ) {
            return null;
        }

        $argument = $expr->getArgs()[0]->value;

        try {
            return $this->context->backend->bytes($this->classLiteral($argument, $line));
        } catch (Refusal) {
            $resolved = $this->resolve($argument, $line);

            return in_array($resolved['kind'], ['class-name', 'bytes', 'resolved-name'], true)
                ? $this->operand($resolved)
                : null;
        }
    }

    /** Keeps a predicate as it is, or negates it, without the caller repeating the ternary. */
    private function negateUnless(bool $asIs, string $predicate): string
    {
        if ($asIs) {
            return $predicate;
        }

        // Folded rather than wrapped. A predicate settled by construction — "this hook only fires for a named
        // class" — is `true`, and the guard around it is `! true`. Left unfolded that emitted `if (!(true))`, dead
        // code carrying whatever comment happened to precede it; folded, {@see translateGuard()} drops the guard
        // and states the reason.
        return match ($predicate) {
            'true' => 'false',
            'false' => 'true',
            default => "!({$predicate})",
        };
    }

    /**
     * Whether every node kind this plugin registers sits inside a class-like.
     *
     * `$this->context->nodeKind` alone is not the question. A rule declaring `FunctionLike` gets `Method` as its primary
     * kind — which is always in a class — while the plugin registers `Function`, `Closure` and `ArrowFunction`
     * as well, and a plain function is not. Asking the primary kind would fold `isInClass()` and
     * `getClassReflection() === null` to constants for exactly the rules where the answer varies at runtime.
     *
     * `$this->context->hookKinds` holds the set only when there is more than one, so a single-kind hook falls back to
     * the kind itself.
     */
    private function everyHookKindIsInAClass(): bool
    {
        $kinds = $this->context->hookKinds === [] ? [$this->context->nodeKind] : $this->context->hookKinds;

        foreach ($kinds as $kind) {
            if (! in_array($kind, self::HOOK_KINDS_ALWAYS_IN_A_CLASS, true)) {
                return false;
            }
        }

        return true;
    }

    private function alwaysHolds(string $reason): string
    {
        $this->context->unreachableGuard = $reason;

        return 'true';
    }

    private function methodPredicate(MethodCall $expr): string
    {
        $method = $this->memberName($expr->name, $expr->getStartLine());
        $args = $expr->getArgs();

        if (($method === 'yes' || $method === 'no') && $expr->var instanceof MethodCall) {
            return $this->trinaryTailPredicate($expr->var, $method, $expr->getStartLine());
        }

        if ($method === 'isInClass' && $expr->var instanceof Variable && $expr->var->name === 'scope') {
            // Folded only where every kind the plugin registers is inside a class-like, and the fold *says
            // so*: it used to return a bare `true`, which left `$unreachableGuard` unset and refused the rule
            // its own fold had just made trivially true. Three rules were refused that way.
            return $this->everyHookKindIsInAClass()
                ? $this->alwaysHolds('this hook fires only on a class-like or one of its members, so the scope it carries is always in a class')
                : $this->context->backend->call('is_in_class', Transpiler::$target === 'php' ? ['$context', '$node'] : ['context']);
        }

        // $classReflection->is($type) — the enclosing class, against a literal or a loop variable
        if ($method === 'is' && count($args) === 1) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('is() on something other than the scope class', $expr->getStartLine());
            }

            return $this->enclosingClassIs($this->bytesValue($args[0]->value, $expr->getStartLine()));
        }

        // `isAbstract()` on the class-like *declaration* the hook fired for, which is a modifier on it rather
        // than a metadata flag.
        if ($method === 'isAbstract' && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            if ($subject['kind'] === 'hook-node') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('isAbstract() on a declaration, which only the PHP target carries', $expr->getStartLine());
                }

                return 'Support::declarationIsAbstract($context, ' . $this->operand($subject) . ')';
            }
        }

        // The same two predicates asked of a class the rule *named* rather than of the declaration a hook
        // fired for. `$reflectionProvider->getClass($someName)->isAbstract()` is a question about a class the
        // plugin only knows while it runs, so it goes to the codebase instead of to the hook's own node. Kept
        // ahead of the declaration arm and matched on the subject's kind, so that arm's folds — which are
        // about *which hook fired* — cannot be widened onto a subject they do not describe.
        if (in_array($method, ['isAbstract', 'isInterface'], true) && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            if ($subject['kind'] === 'named-class') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal("{$method}() of a named class, which only the PHP target carries", $expr->getStartLine());
                }

                $helper = $method === 'isAbstract' ? 'named_class_is_abstract' : 'named_class_is_interface';

                return $this->context->backend->call($helper, ['$context', $this->operand($subject)]);
            }
        }

        // Reflection predicates. Inside a declaration hook these come from the class metadata, and
        // two of them are settled by which hook it is: the class hook fires only for classes, and
        // never for anonymous ones, which are a separate node in Mago.
        if (in_array($method, ['isClass', 'isAnonymous', 'isAbstract', 'isInterface', 'isTrait', 'isEnum'], true) && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());

            // Either spelling of the same subject. A rule may ask `$scope->getClassReflection()->isAnonymous()`
            // or `$node->isAnonymous()` straight off the declaration the hook fired for, and every answer below
            // is about *which hook fired* rather than about how the rule reached it — so gating on the
            // reflection spelling refused `NoConstructorAndRequiredTogetherRule` for writing the shorter one.
            $onTheDeclaration = $subject['kind'] === 'hook-node'
                && in_array($this->context->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true);

            if ($subject['kind'] !== 'class-reflection' && ! $onTheDeclaration) {
                throw new Refusal(
                    sprintf(
                        '%s() on a %s, which is neither a class reflection nor the class-like declaration a hook fired for',
                        $method,
                        $subject['kind'],
                    ),
                    $expr->getStartLine(),
                );
            }

            if ($this->context->classFrom !== 'metadata') {
                throw new Refusal("{$method}() outside a declaration hook", $expr->getStartLine());
            }

            $this->context->usesMetadata = true;

            return match ($method) {
                'isClass' => $this->classHookIsClass(),
                'isAnonymous' => $this->unreachable('an anonymous class is a separate node kind, so the class declaration hook never fires for one'),
                // A trait is never a target: PHPStan's `InClassNode` does not fire for one either, so the
                // question is settled whichever breadth the rule got.
                'isTrait' => $this->unreachable('no declaration hook fires for a trait, which InClassNode does not visit either'),
                // These two are only settled where the rule narrowed to classes itself. Where it did not, the
                // plugin targets enums and interfaces as well and the question is a real one — folding it there
                // would report on precisely what the rule excludes.
                'isInterface' => $this->declarationKindIs('Interface', 'an interface'),
                'isEnum' => $this->declarationKindIs('Enum', 'an enum'),
                // The class metadata carries this on the Rust side; on the PHP side the hook's own node *is* the
                // declaration, so the modifier is right there. Without a rendering here the PHP target emitted
                // Rust and refused on it one step later, which named the operand instead of the question.
                default => Transpiler::$target === 'php'
                    ? 'Support::declarationIsAbstract($context, $node)'
                    : 'support::metadata_is_abstract(metadata)',
            };
        }

        // `$classReflection->hasMethod($name)` on the declaration the hook fired for: whether anything in its
        // hierarchy declares that method. Only that shape — a class the rule *named* has a more direct
        // translation further down, and answering here would replace it with a lookup saying the same thing
        // less plainly. Answered through the declaring-class lookup, which is what keeps this consistent with
        // the declaring-class read that usually follows it: a name it can attribute is a name that exists.
        if ($method === 'hasMethod'
            && count($args) === 1
            && $this->resolve($expr->var, $expr->getStartLine())['kind'] === 'class-reflection'
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a declared-method test, which only the PHP target carries', $expr->getStartLine());
            }

            // Through the name-argument path, which takes a written literal without resolving it: a
            // `Scalar_String` is not an access path, and `hasMethod('rules')` is the commonest spelling there is.
            return $this->context->backend->call('class_has_method', [
                '$context',
                '$node',
                $this->operand($this->methodNameArgument($args, $method, $expr->getStartLine())),
            ]);
        }

        // `$classReflection->implementsInterface($name)` — asked of the declaration the hook fired for.
        if ($method === 'implementsInterface' && count($args) === 1) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('implementsInterface() on something other than a class reflection', $expr->getStartLine());
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('an implemented-interface test, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->context->backend->call('class_implements', [
                '$context',
                '$node',
                $this->interfaceNameArgument($args[0]->value, $expr->getStartLine()),
            ]);
        }

        if ($method === 'getName' && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());

            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('getName() on something other than a class reflection', $expr->getStartLine());
            }

            $this->context->usesMetadata = true;

            throw new Refusal('getName() used as a predicate', $expr->getStartLine());
        }

        if ($method === 'isSubclassOf' && count($args) === 1) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('isSubclassOf() on something other than the scope class', $expr->getStartLine());
            }

            $literal = $this->classLiteral($args[0]->value, $expr->getStartLine());

            // PHPStan's isSubclassOf() excludes the class itself; Mago's is_instance_of() includes
            // it. For the interface/abstract parents this vocabulary sees, the two coincide.
            return $this->enclosingClassIs($this->context->backend->bytes($literal));
        }

        // `hasConstructor()` and `hasMethod($name)` on a class handle. Mago answers both the same way, through
        // the declaring-method lookup, which is what PHPStan's question means: an inherited method counts.
        if (in_array($method, ['hasConstructor', 'hasMethod'], true) && count($args) <= 1) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());

            // A class name a loop bound — one of the classes a type names — answers the same question a
            // reflection handle does: both are just the name.
            if ($subject['kind'] === 'class-name') {
                $subject = ['rust' => $subject['rust'], 'kind' => 'named-class'] + $subject;
                $subject['kind'] = 'named-class';
            }

            // Asked of a *type* rather than of a named class: the class is whichever one the type names, which
            // is the same reduction `instanceof TypeWithClassName` makes above.
            if ($subject['kind'] === 'type' && Transpiler::$target === 'php') {
                $subject = [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'named-class',
                    'php' => 'Support::soleObjectClass(' . $this->operand($subject) . ')',
                ];
            }

            if ($subject['kind'] === 'named-class') {
                $name = $method === 'hasConstructor'
                    ? $this->context->backend->bytes('__construct')
                    : $this->operand($this->methodNameArgument($args, $method, $expr->getStartLine()));

                return $this->context->backend->call('method_exists', ['$context', $this->operand($subject), $name]);
            }
        }

        // `isVariadic()` on a parameter. A variadic parameter has no single argument position, so every rule
        // that names the parameter an argument lands in skips one.
        if ($method === 'isVariadic' && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            if ($subject['kind'] === 'parameter') {
                return $this->parameterQuestion('parameter_is_variadic', $subject, $expr->getStartLine());
            }
        }

        if (in_array($method, ['isPublic', 'isPrivate', 'isProtected', 'isStatic', 'isMagic'], true) && $args === []) {
            $visibility = $this->visibilityPredicate($method, $expr);
            if ($visibility !== null) {
                return $visibility;
            }
        }

        if ($method === 'isFirstClassCallable') {
            return $this->unreachable('Mago parses `f(...)` as a partial application, which never reaches a call hook');
        }

        if ($expr->var instanceof Variable && $expr->var->name === 'this' && $this->context->currentClass instanceof ClassLike) {
            return $this->inlineOwnHelper($method, $args, $expr->getStartLine());
        }

        $reflected = $this->reflectionProviderCall($expr, $method, $args);
        if ($reflected !== null) {
            return $reflected;
        }

        if (in_array($method, ['isRelative', 'isSpecialClassName'], true) && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            $support = $method === 'isRelative' ? 'is_relative_name' : 'is_special_class_name';

            return $this->context->backend->call($support, [$this->operand($subject)]);
        }

        $this->refuseCallOnService($expr, $method);

        $configured = $this->resolveValueObjectGetter($expr, $method, $expr->getStartLine());
        if ($configured !== null) {
            return $this->operand($configured);
        }

        // A collaborator call the runtime answers, asked before inlining rather than after. A helper the
        // vocabulary has ported is not one to inline: `passesAsBoolean()` reads as a condition, so it arrives
        // here rather than through the value path, and inlining it refused inside the helper's own body on
        // the early return this port exists to remove.
        $ported = $this->resolveCollaboratorCall($expr, $expr->getStartLine());
        if ($ported !== null) {
            return $this->operand($ported);
        }

        // A collaborator that does not declare this method is not a collaborator for this call, and saying so
        // by name beats "no method X() to inline", which points at a class the reader had no reason to expect.
        $collaborator = $this->collaboratorClass($expr->var, $expr->getStartLine());
        if ($collaborator !== null && $this->declaresMethod($collaborator['class'], $method)) {
            return $this->inlineMethod($collaborator['class'], $method, array_values($args), $expr->getStartLine(), $collaborator['uses']);
        }

        throw new Refusal("method call outside the vocabulary ->{$method}()", $expr->getStartLine());
    }

    private function functionPredicate(FuncCall $expr): string
    {
        if (! $expr->name instanceof Name) {
            throw new Refusal('call to a dynamic function name', $expr->getStartLine());
        }

        $name = $expr->name->toString();
        $args = $expr->getArgs();

        if (in_array($name, ['str_ends_with', 'str_starts_with', 'str_contains'], true) && count($args) === 2) {
            $subject = $this->resolve($args[0]->value, $expr->getStartLine());
            $needle = $this->bytesValue($args[1]->value, $expr->getStartLine());

            if ($subject['kind'] === 'file') {
                $support = ['str_ends_with' => 'file_ends_with', 'str_starts_with' => 'file_starts_with', 'str_contains' => 'file_contains'][$name];

                return $this->context->backend->call($support, Transpiler::$target === 'php' ? ['$context', $needle] : ['context', $needle]);
            }

            // A method's written name is a name node here and a string to the rule, so it goes through the byte
            // helpers like any other name once its text is read.
            if ($subject['kind'] === 'method-name' && Transpiler::$target === 'php') {
                $support = ['str_ends_with' => 'bytes_end_with', 'str_starts_with' => 'bytes_start_with', 'str_contains' => 'bytes_contain'][$name];

                return $this->context->backend->call($support, ['Support::methodName(' . $this->operand($subject) . ')', $needle]);
            }

            if (in_array($subject['kind'], ['class-name', 'bytes', 'name-expr'], true)) {
                $support = ['str_ends_with' => 'bytes_end_with', 'str_starts_with' => 'bytes_start_with', 'str_contains' => 'bytes_contain'][$name];
                // A name node carries its text; the byte helpers take the text, which is the same route
                // `in_array()` and a message argument take for this kind.
                $value = $subject['kind'] === 'name-expr' && Transpiler::$target === 'php'
                    ? $this->context->backend->call('text_of', [$this->operand($subject)])
                    : $this->operand($subject);

                return $this->context->backend->call($support, [$value, $needle]);
            }

            throw new Refusal("{$name}() on a {$subject['kind']}", $expr->getStartLine());
        }

        // array_any(<list of strings>, fn ($x) => <predicate using $x>)
        if (in_array($name, ['array_any', 'array_all'], true) && count($args) === 2) {
            $options = $this->stringList($args[0]->value, $expr->getStartLine());
            $closure = $args[1]->value;
            if (! $closure instanceof ArrowFunction || count($closure->params) !== 1) {
                throw new Refusal("{$name}() with something other than a one-parameter arrow function", $expr->getStartLine());
            }

            $parameter = $closure->params[0]->var;
            if (! $parameter instanceof Variable || ! is_string($parameter->name)) {
                throw new Refusal("{$name}()'s parameter is not a simple variable", $expr->getStartLine());
            }

            $saved = $this->context->locals;
            $savedLiterals = $this->context->literals;
            $savedCaches = $this->context->caches;
            unset($this->context->literals[$parameter->name]);
            try {
                $this->context->locals[$parameter->name] = ['rust' => 'item', 'kind' => 'bytes', 'php' => '$item'];
                $predicate = $this->translateCondition($closure->expr);
            } finally {
                $this->context->locals = $saved;
                $this->context->literals = $savedLiterals;
                $this->context->caches = $savedCaches;
            }

            $list = $this->byteSliceList($options);
            $combinator = $name === 'array_any' ? 'any' : 'all';

            if (Transpiler::$target === 'php') {
                // PHP 8.4 has array_any(), but the generated rules should run on 8.1, so the support
                // runtime carries the combinator instead.
                return $this->context->backend->call($combinator === 'any' ? 'any_of' : 'all_of', [
                    $list,
                    "static fn (\$item): bool => {$predicate}",
                ]);
            }

            return "{$list}.iter().copied().{$combinator}(|item| {$predicate})";
        }

        if ($name === 'in_array' && count($args) >= 2) {
            return $this->inArrayPredicate($args, $expr->getStartLine());
        }

        if ($name === 'file_exists' && count($args) === 1) {
            return $this->pathExistsPredicate($args[0]->value, $expr->getStartLine());
        }

        if ($name === 'is_string' && count($args) === 1) {
            $target = $args[0]->value;
            if ($target instanceof PropertyFetch && (string) $target->name === 'name') {
                $subject = $this->resolve($target->var, $expr->getStartLine());

                return Transpiler::$target === 'php'
                    ? $this->context->backend->call('direct_variable_name', ['$context', $this->operand($subject)]) . ' !== null'
                    : "support::direct_variable_name({$subject['rust']}).is_some()";
            }

            // A nullable string a helper handed back: `is_string($x)` is how a rule spells "the helper found
            // one", and the value is null when it did not.
            $subject = $this->resolve($target, $expr->getStartLine());
            if (in_array($subject['kind'], ['bytes', 'message'], true)) {
                return $this->operand($subject) . ' !== null';
            }

            throw new Refusal('is_string() on something outside the vocabulary', $expr->getStartLine());
        }

        // `fast_node_named($node, 'name')` — a global function `symplify/phpstan-rules` ships in
        // `src/functions/fast-functions.php`. Its body is
        // `$node instanceof Identifier || $node instanceof Name ? $node->toString() === $desiredName : false`,
        // which is the question `NamingHelper::isName()` asks and `nameEquals()` already answers. Two call sites
        // in the corpus, and the refusal named it correctly — it just had no row.
        if ($name === 'fast_node_named' && count($args) === 2) {
            $literal = $this->stringLiteral($args[1]->value, $expr->getStartLine());

            return $this->nameEquals($this->resolve($args[0]->value, $expr->getStartLine()), $literal, $expr->getStartLine());
        }

        throw new Refusal("function call outside the vocabulary {$name}()", $expr->getStartLine());
    }

    /**
     * `isPublic()` and friends, of a method the codebase knows or of one as written.
     *
     * Two subjects answer the same question differently. A *reflection* answers from
     * `FunctionLikeMetadata->visibility` — not from its flags, which carry `STATIC`, `ABSTRACT` and `FINAL` and
     * no visibility at all, so a flags check would answer every method the same. A *declaration* answers from
     * its modifiers, which is the method as written and what a rule looping a class-like's body holds.
     *
     * Returns null when the subject is neither, so the caller keeps looking.
     */
    private function visibilityPredicate(string $method, MethodCall $expr): ?string
    {
        $subject = $this->resolve($expr->var, $expr->getStartLine());
        $line = $expr->getStartLine();

        if ($subject['kind'] === 'method-handle' && in_array($method, ['isPublic', 'isPrivate'], true)) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal("{$method}() on a method reflection, which only the PHP target carries", $line);
            }

            $helper = $method === 'isPublic' ? 'reflectedMethodIsPublic' : 'reflectedMethodIsPrivate';

            return 'Support::' . $helper . '($context, '
                . $this->handlePart($subject, 'classPhp', $line) . ', '
                . $this->handlePart($subject, 'methodPhp', $line) . ')';
        }

        // The method-declaration hook's own node is the declaration, so the same helpers answer for it — once
        // it is a part, which is what they navigate.
        if ($subject['kind'] === 'hook-node' && $this->context->nodeKind === 'Method') {
            $subject = ['rust' => self::PHP_ONLY, 'kind' => 'method-decl', 'php' => 'Support::asPart($context, $node)'];
        }

        if (! in_array($subject['kind'], ['method-decl', 'maybe-method-decl'], true)) {
            return null;
        }

        if (Transpiler::$target !== 'php') {
            throw new Refusal("{$method}() on a declaration, which only the PHP target carries", $line);
        }

        $helper = [
            'isPublic' => 'methodIsPublic',
            'isPrivate' => 'methodIsPrivate',
            'isProtected' => 'methodIsProtected',
            'isStatic' => 'methodIsStatic',
            'isMagic' => 'methodIsMagic',
        ][$method];

        return 'Support::' . $helper . '(' . $this->operand($subject) . ')';
    }

    /**
     * One of the vocabulary's node predicates, called on the operand.
     *
     * A predicate that answers from the node's *kind* has to look the node up, so it takes the context; one
     * that answers from the part alone does not. Named here rather than in the table so the table stays a
     * mapping from php-parser class to helper.
     *
     * @param Descriptor $subject
     */
    private function nodePredicate(string $predicate, array $subject, string $wanted, int $line): string
    {
        // Two independent facts about a predicate, kept apart because conflating them dropped a guard: whether
        // only the PHP runtime has it, and whether it has to look the node up. `is_dir_constant` is PHP-only
        // and needs no context; `is_literal_string` is both.
        if (in_array($predicate, self::PHP_ONLY_PREDICATES, true) && Transpiler::$target !== 'php') {
            throw new Refusal("instanceof {$wanted}, which only the PHP target carries", $line);
        }

        $arguments = in_array($predicate, self::CONTEXT_PREDICATES, true)
            ? ['$context', $this->operand($subject)]
            : [$this->operand($subject)];

        return $this->context->backend->call($predicate, $arguments);
    }

    /**
     * Whether a list holds names that came from Mago's metadata.
     *
     * Those arrive lowercased, so comparing against one folds case — which is what the original's strict
     * comparison against canonical names asks for. A list the rule filled itself holds whatever it put there,
     * and folding case for that would be wider than the `true` it was given, so it is not treated the same.
     *
     * @param Descriptor $haystack
     */
    private function holdsMetadataNames(array $haystack): bool
    {
        return $haystack['kind'] === 'class-names'
            || ($haystack['kind'] === 'list' && ($haystack['as'] ?? '') === 'class-name');
    }

    /**
     * `file_exists(<a path the rule built>)`.
     *
     * A plugin is PHP, so it asks the filesystem the same question the rule asks. Only of bytes: of anything
     * else there is no path to look up.
     */
    private function pathExistsPredicate(Expr $path, int $line): string
    {
        if (Transpiler::$target !== 'php') {
            throw new Refusal('a filesystem check, which only the PHP target carries', $line);
        }

        $subject = $this->resolve($path, $line);
        if (! in_array($subject['kind'], ['bytes', 'message'], true)) {
            throw new Refusal("file_exists() of a {$subject['kind']}", $line);
        }

        return $this->context->backend->call('path_exists', [$this->operand($subject)]);
    }

    /**
     * `count(<something>) === N`, for the two things a rule counts.
     *
     * An argument list compares against any number. A sole receiver class compares only against one: the helper
     * behind it hands back that class or null, so "exactly one" is "there is one" — and a rule asking for two
     * would be asking a question this cannot answer, which refuses rather than guessing.
     */
    private function countComparison(FuncCall $count, Expr $right, int $line): string
    {
        $subject = $this->resolve($count->getArgs()[0]->value, $line);

        // A declaration's parameter list is a plain PHP list, so its length is its length — any number is a
        // meaningful comparison, unlike the sole-receiver question below.
        if (in_array($subject['kind'], ['param-decls', 'array-items', 'list'], true)) {
            return 'count(' . $this->operand($subject) . ') === ' . $this->intLiteral($right, $line);
        }

        if ($subject['kind'] === 'sole-class') {
            if ($this->intLiteral($right, $line) !== 1) {
                throw new Refusal('a receiver-class count other than one', $line);
            }

            return $this->operand($subject) . ' !== null';
        }

        if ($subject['kind'] !== 'args') {
            throw new Refusal('count() of something other than an argument list', $line);
        }

        $equals = Transpiler::$target === 'php' ? '===' : '==';

        return $this->context->backend->call('arg_count', [$this->operand($subject)])
            . " {$equals} " . $this->intLiteral($right, $line);
    }

    /**
     * `$classReflections === []` — "the type names no class at all".
     *
     * The *list* being empty, not the single-class reduction being null: a union receiver names two classes and
     * satisfies neither reading of the other one.
     *
     * @param Descriptor $subject
     */
    private function noClassNamed(array $subject, int $line): ?string
    {
        return $subject['kind'] === 'sole-class' && Transpiler::$target === 'php'
            ? $this->handlePart($subject, 'listPhp', $line) . ' === []'
            : null;
    }

    /** `count($node->getArgs()) === N` and `$args === []`. */
    private function equality(Expr $left, Expr $right, int $line): string
    {
        if ($left instanceof FuncCall && $left->name instanceof Name && $left->name->toString() === 'count') {
            return $this->countComparison($left, $right, $line);
        }

        // `preg_match($pattern, $subject) === 1`, which is how the corpus spells a pattern test that is not
        // written through Nette. The `1` is the only value worth comparing against — `0` is no match and
        // `false` is a broken pattern — and the helper answers the same boolean either way.
        if ($right instanceof Int_ && $right->value === 1) {
            $match = $this->patternTest($left, $line);
            if ($match !== null) {
                return $match;
            }
        }

        // A value that cannot exist in Mago's model, compared against anything, is false. Folded here rather
        // than refused: the comparison is real PHPStan code, and the answer is knowable.
        try {
            if ($this->resolve($left, $line)['kind'] === 'never') {
                return 'false';
            }
        } catch (Refusal) {
            // Not resolvable on its own; the paths below say what it is instead.
        }

        // <args> === []  /  <members> === []
        if ($right instanceof Array_ && $right->items === []) {
            $subject = $this->resolve($left, $line);

            // An argument list is iterable *and* countable, and only the count answers this: the PHP helper
            // hands back the list node, which is never equal to an empty array. Checked before the iterable
            // path, which would otherwise emit a comparison that is false whatever the call looks like.
            if ($subject['kind'] === 'args') {
                return $this->context->backend->call('arg_count', [$this->operand($subject)])
                    . (Transpiler::$target === 'php' ? ' === 0' : ' == 0');
            }

            $emptyClasses = $this->noClassNamed($subject, $line);
            if ($emptyClasses !== null) {
                return $emptyClasses;
            }

            // A list the rule built is as emptiable as one the vocabulary produced; it is absent from `ITERABLES`
            // only because nothing iterates it back.
            if (isset(Vocabulary::ITERABLES[$subject['kind']]) || $subject['kind'] === 'list') {
                if (Transpiler::$target === 'php') {
                    return $this->operand($subject) . ' === []';
                }

                return "{$subject['rust']}.is_empty()";
            }

            throw new Refusal("empty-array comparison against a {$subject['kind']}", $line);
        }

        // $flag === true / $flag === false
        if ($left instanceof Variable && is_string($left->name)
            && ($this->context->locals[$left->name]['kind'] ?? null) === 'bool'
            && ($wanted = $this->isBooleanLiteral($right)) !== null
        ) {
            $flag = $this->operand($this->context->locals[$left->name]);

            return $wanted === 'true' ? $flag : "!{$flag}";
        }

        // `$classReflection->is(TestCase::class) === false` — a predicate compared to a boolean literal. The
        // literal is what makes translating the left side as a predicate safe: an expression compared to
        // `true` or `false` can only have been one, so this cannot turn out to have been a value whose
        // inlining left lines behind.
        if ($left instanceof MethodCall && ($wanted = $this->isBooleanLiteral($right)) !== null) {
            $predicate = $this->methodPredicate($left);

            return $wanted === 'true' ? $predicate : '!(' . $this->stripOuterParentheses($predicate) . ')';
        }

        $againstNull = $this->nullComparison($left, $right, $line);
        if ($againstNull !== null) {
            return $againstNull;
        }

        // strtoupper($x) === $x  — the idiomatic "is this already uppercase" test
        if ($left instanceof FuncCall
            && $left->name instanceof Name
            && $left->name->toString() === 'strtoupper'
            && count($left->getArgs()) === 1
        ) {
            $inner = $this->resolve($left->getArgs()[0]->value, $line);
            $other = $this->resolve($right, $line);
            if ($inner['rust'] !== $other['rust']) {
                throw new Refusal('strtoupper() compared against something else', $line);
            }

            return $this->context->backend->call('is_uppercase', [$this->operand($inner)]);
        }

        $betweenStrings = $this->stringComparison($left, $right, $line);
        if ($betweenStrings !== null) {
            return $betweenStrings;
        }

        // <name>->toString() === 'literal'   /   <string local> === 'literal'
        if ($left instanceof MethodCall && (string) $left->name === 'toString') {
            return $this->nameEquals($this->resolve($left->var, $line), $this->stringLiteral($right, $line), $line);
        }

        // `->toLowerString() === 'null'` is the same comparison with the case folded, and the fold is what the
        // rule wrote rather than something extra to emit — but only where the helper it lands on folds too.
        // Most do. A *member selector* does not, deliberately: `selectorIs()` compares method names as written.
        // So the fold has to travel, and it did not: `IllegalConstructorMethodCallRule` writes
        // `->toLowerString() !== '__construct'`, the port emitted the case-sensitive comparison, and it was
        // silent on `$subject->__CONSTRUCT()` where PHPStan reports. The comment here used to claim the helpers
        // already folded, which was true of every arm but the one that mattered.
        if ($left instanceof MethodCall
            && $left->name instanceof Identifier
            && $left->name->toString() === 'toLowerString'
        ) {
            return $this->nameEquals($this->resolve($left->var, $line), $this->stringLiteral($right, $line), $line, true);
        }

        if ($left instanceof PropertyFetch && (string) $left->name === 'name') {
            $subject = $this->resolve($left->var, $line);
            $literal = $this->stringLiteral($right, $line);
            if (Transpiler::$target === 'php') {
                return $this->context->backend->call('direct_variable_name', ['$context', $this->operand($subject)])
                    . ' === ' . $this->context->backend->bytes($literal);
            }

            return "support::direct_variable_name({$subject['rust']}) == Some(&b\"{$literal}\"[..])";
        }

        $subject = $this->resolve($left, $line);
        if (in_array($subject['kind'], ['name-selector', 'name-expr', 'extends', 'hint', 'hint-option'], true)) {
            return $this->nameEquals($subject, $this->stringLiteral($right, $line), $line);
        }

        // A value the rule computed, against a literal: `strtolower($name->getLast()) !== 'request'` is two
        // strings, and the case fold is the rule's own. Compared as strings rather than through the name
        // helpers, which fold case again and would make the fold invisible.
        // `attribute-name` joins them because an attribute's name *is* a computed string once the rule has
        // called `->toString()` on it, and a rule comparing it to `Attribute::class` is comparing two strings.
        if (in_array($subject['kind'], ['bytes', 'class-name'], true) && Transpiler::$target === 'php') {
            try {
                return $this->operand($subject) . ' === ' . $this->context->backend->bytes($this->stringLiteral($right, $line));
            } catch (Refusal $notLiteral) {
                // Two computed strings rather than one against a written word. The fold's agreement check is
                // the shape that needs it — `$record['paramName'] !== $site['paramName']` asks whether two
                // declarers named the flag parameter the same thing, and neither side is a literal. Reached
                // only after the literal reading fails, so nothing that compared against a word changes.
                $other = $this->resolve($right, $line);
                if (! in_array($other['kind'], ['bytes', 'class-name'], true)) {
                    throw $notLiteral;
                }

                return $this->operand($subject) . ' === ' . $this->operand($other);
            }
        }

        throw new Refusal(
            'comparison outside the vocabulary: ' . $this->describe($left) . ' against ' . $this->describe($right),
            $line,
        );
    }

    /**
     * Both sides of a comparison as rendered numbers, when both sides are numbers.
     *
     * Only on the PHP target, and only for kinds that are already a number: a threshold rule compares what it
     * measured against what the neon configured, and neither operand is a node. Returning null leaves the
     * node-shaped comparisons below to the paths written for them.
     *
     * @return array{string, string}|null
     */
    private function numericOperands(BinaryOp $expr): ?array
    {
        if (Transpiler::$target !== 'php') {
            return null;
        }

        $numeric = ['int', 'config-number'];
        $line = $expr->getStartLine();
        try {
            $left = $this->resolve($expr->left, $line);
            $right = $this->resolve($expr->right, $line);
        } catch (Refusal) {
            return null;
        }

        if (! in_array($left['kind'], $numeric, true) || ! in_array($right['kind'], $numeric, true)) {
            return null;
        }

        return [$this->operand($left), $this->operand($right)];
    }

    /** `$intNode->value >= 1`, `count($found) <= 1` and friends. */
    private function intComparison(BinaryOp $expr): string
    {
        $left = $expr->left;
        $operator = $this->numericOperator($expr);

        // `count(<a list>) <= N` — a plain PHP comparison, since both sides are numbers rather than nodes.
        if ($left instanceof FuncCall && $left->name instanceof Name && $left->name->toString() === 'count') {
            $counted = $this->countable($left, $expr->getStartLine());

            return $counted . ' ' . $operator . ' ' . $this->intLiteral($expr->right, $expr->getStartLine());
        }

        // Two numbers the rule already has: a measured value against a configured threshold. Both sides
        // resolve to numbers rather than to nodes, so the comparison is the plain PHP one — no helper, and
        // nothing about the node tree involved.
        $numeric = $this->numericOperands($expr);
        if ($numeric !== null) {
            return $numeric[0] . ' ' . $operator . ' ' . $numeric[1];
        }

        if (! $left instanceof PropertyFetch || (string) $left->name !== 'value') {
            throw new Refusal('numeric comparison outside the vocabulary', $expr->getStartLine());
        }

        $subject = $this->resolve($left->var, $expr->getStartLine());
        $number = $this->intLiteral($expr->right, $expr->getStartLine());
        if (Transpiler::$target === 'php') {
            // Rust's `is_some_and` folds the absent case into the comparison; PHP has no equivalent, so
            // the operator is passed to the helper rather than emitted twice around a repeated call.
            return $this->context->backend->call('int_compares', [
                $this->operand($subject),
                $this->context->backend->bytes($operator),
                (string) $number,
            ]);
        }

        return "support::int_literal_value({$subject['rust']}).is_some_and(|value| value {$operator} {$number})";
    }

    /**
     * @param Descriptor $subject
     * @param bool $foldingCase whether the rule folded case itself, which only a selector comparison needs told
     */
    private function nameEquals(array $subject, string $literal, int $line, bool $foldingCase = false): string
    {
        // A comparison the rule folded, against something that is already a string: a constant name, a loop
        // item, a helper's parameter. The `bytes` arm below compares with `===`, which is case-sensitive, so
        // the fold the rule wrote was dropped — `AssertSameNullExpectedRule` writes
        // `->toLowerString() === 'null'` and the port was silent on `assertSame(NULL, $x)` where PHPStan
        // reports. The same defect the selector arm below records, one kind along.
        if ($foldingCase && in_array($subject['kind'], ['bytes', 'class-name'], true)) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a case-folded string comparison, which only the PHP target carries', $line);
            }

            return 'Support::nameIs(' . $this->operand($subject) . ', ' . $this->context->backend->bytes($literal) . ')';
        }

        // The one arm that compares as written. `nameIs()` over the selector's text is the same comparison the
        // other arms make, so a rule that wrote the fold gets it.
        if ($foldingCase && $subject['kind'] === 'name-selector') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a case-folded selector comparison, which only the PHP target carries', $line);
            }

            return 'Support::nameIs(Support::textOf(' . $this->operand($subject) . '), '
                . $this->context->backend->bytes($literal) . ')';
        }

        return match ($subject['kind']) {
            // A declaration's own name. The descriptor carries a PHP rendering — `Vocabulary::FIELDS` maps
            // `->name` on a declaration to `Support::declarationName()` — and this arm emitted Rust regardless,
            // so the PHP target refused the rule with "operand is still Rust" and a Rust fragment for a reason.
            // The comparison is the one the `bytes` arm makes: text against the literal.
            'local-name' => Transpiler::$target === 'php'
                ? $this->operand($subject) . ' === ' . $this->context->backend->bytes($literal)
                : "support::local_name_is({$subject['rust']}, b\"{$literal}\")",
            'name-selector' => $this->context->backend->call('selector_is', [$this->operand($subject), $this->context->backend->bytes($literal)]),
            'name-expr' => $this->context->backend->call('name_equals', [$this->operand($subject), $this->context->backend->bytes($literal)]),
            // Already a string — a loop's bound item, a helper's parameter, the enclosing namespace. Compared
            // directly, because there is no node left to ask.
            'bytes', 'class-name' => Transpiler::$target === 'php'
                ? $this->operand($subject) . ' === ' . $this->context->backend->bytes($literal)
                : $this->operand($subject) . ' == b"' . $literal . '"',
            'extends' => $this->context->backend->call('extends_is', Transpiler::$target === 'php'
                ? ['$context', '$node', $this->context->backend->bytes($literal)]
                : ['context', 'node', $this->context->backend->bytes($literal)]),
            'hint', 'hint-option' => Transpiler::$target === 'php'
                ? $this->context->backend->call('hint_name_is', ['$context', $this->operand($subject), $this->context->backend->bytes($literal)])
                : sprintf(
                    'support::hint%s_name_is(context, %s, b"%s")',
                    $subject['kind'] === 'hint-option' ? '_option' : '',
                    $subject['rust'],
                    $literal,
                ),
            // Both are already resolved names, so the comparison is a string one — and both are compared against
            // a fully-qualified name, because that is what php-parser hands a rule after PHPStan has resolved
            // the AST.
            'attribute-name' => 'Support::nameIs(Support::attributeName($context, ' . $this->operand($subject) . '), ' . $this->context->backend->bytes($literal) . ')',
            'method-name' => 'Support::nameIs(Support::methodName(' . $this->operand($subject) . '), ' . $this->context->backend->bytes($literal) . ')',
            // `$node->name->toString() === 'class'` on a member name: the part carries its own text, and PHP
            // compares member names case-insensitively, which `nameIs()` already does.
            'name-part' => 'Support::nameIs(Support::textOf(' . $this->operand($subject) . '), ' . $this->context->backend->bytes($literal) . ')',
            'expr' => "support::expression_selector_is({$subject['rust']}, b\"{$literal}\")",
            default => throw new Refusal("name comparison against a {$subject['kind']}", $line),
        };
    }

    /**
     * Marks the guard being translated as unreachable, and gives the reason the output will carry.
     *
     * Returning the constant is what makes the guard collapse; recording the reason is what keeps the
     * collapse honest. Each reason here was checked by running the case the guard filters out through a
     * rule's good example and confirming the port reports nothing.
     */
    private function unreachable(string $reason): string
    {
        $this->context->unreachableGuard = $reason;

        return 'false';
    }

    /**
     * `$classReflection->isClass()`, and `$node->getOriginalNode() instanceof Class_`, inside the class
     * declaration hook. The same question, asked two ways.
     *
     * The PHP target asks it. It used to fold to "always true" and record the rule as narrowed, which was
     * wrong twice over: the fold is only sound where the plugin visits classes alone, and the recording claimed
     * a narrowing the predicate alone does not prove. `isClass() && $somethingElse` inside an exiting guard
     * leaves the rule reporting on enums and interfaces, and `! isClass()` reporting *only* there — but both
     * spellings set the flag, so the plugin dropped those targets and went silent on the very declarations the
     * rule is about.
     *
     * Asking at runtime costs a node-kind comparison and makes the guard the rule's own again, whatever it is
     * compounded with or negated by. The Rust target keeps the fold: its class hook fires for classes alone, so
     * the predicate really is always true there, and a rule that does *not* narrow is refused outright rather
     * than emitted — see the `classOnly` check in {@see emit()}.
     */
    private function classHookIsClass(): string
    {
        if (Transpiler::$target !== 'php') {
            $this->context->narrowedToClass = true;

            return $this->alwaysHolds('the class declaration hook fires for classes, never for an interface');
        }

        return $this->context->backend->call('declaration_kind_is', ['$context', '$node', $this->context->backend->bytes('Class')]);
    }

    /**
     * Whether the declaration the hook fired for is of one kind.
     *
     * Always the real question, never folded away. Folding it needed to know the target breadth, and the breadth
     * is only settled once the body is translated — so the fold and the targets each wanted the other's answer
     * first. Asking at runtime costs a node-kind comparison and removes the circularity: where the plugin
     * targets classes alone the answer is simply always no.
     */
    private function declarationKindIs(string $kind, string $described): string
    {
        if (Transpiler::$target !== 'php') {
            throw new Refusal("a {$described} declaration test, which only the PHP target carries");
        }

        return $this->context->backend->call('declaration_kind_is', ['$context', '$node', $this->context->backend->bytes($kind)]);
    }

    /** The enclosing-class test, from whichever source this hook provides. */
    private function enclosingClassIs(string $bytes): string
    {
        if ($this->context->classFrom === 'metadata') {
            $this->context->usesMetadata = true;

            return $this->context->backend->call('metadata_is', Transpiler::$target === 'php'
                ? ['$context', '$node', $bytes]
                : ['context', 'metadata', $bytes]);
        }

        return $this->context->backend->call('enclosing_class_is', Transpiler::$target === 'php'
            ? ['$context', '$node', $bytes]
            : ['context', $bytes]);
    }

    /**
     * @param Descriptor $subject
     */
    private function requireType(array $subject, int $line): void
    {
        // A null-stripped type answers every question a type does; the kind only records that `removeNull` was
        // applied on the way.
        if (! in_array($subject['kind'], ['type', 'type-without-null'], true)) {
            throw new Refusal('type query on something that is not a resolved type', $line);
        }
    }

    private function variableNameExpression(Expr $expr, int $line): string
    {
        if ($expr instanceof PropertyFetch && $this->memberName($expr->name, $expr->getStartLine()) === 'name') {
            $subject = $this->resolve($expr->var, $line);

            return "support::direct_variable_name({$subject['rust']}).unwrap_or_default()";
        }

        if ($expr instanceof Variable && is_string($expr->name) && isset($this->context->locals[$expr->name])) {
            $local = $this->context->locals[$expr->name];
            if ($local['kind'] === 'variable-name') {
                return $local['rust'];
            }
        }

        throw new Refusal('variable name outside the vocabulary', $line);
    }

    /**
     * A question about reflection, an inferred type, or one of the handles those produce.
     *
     * Null means "not one of these", so {@see resolve()} carries on with its other paths. Split out because
     * these branches share one idea — Mago has no reflection object, so every handle a rule holds is reduced to
     * the names a codebase lookup takes — and because `resolve()` is already the largest thing in this class.
     *
     * @return Descriptor|null
     */
    private function resolveReflection(Expr $expr, int $line): ?array
    {
        // `self::SOME_LIST` where the constant holds a list of strings. Known at transpile time, so it is
        // emitted as the list itself and iterates exactly like a configured one.
        if ($expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && in_array($expr->class->toString(), ['self', 'static'], true)
            && isset($this->context->arrayConstants[$this->memberName($expr->name, $line)])
        ) {
            $rendered = $this->byteSliceList($this->context->arrayConstants[$this->memberName($expr->name, $line)]);

            return ['rust' => $rendered, 'kind' => 'config-list', 'php' => $rendered];
        }

        // `$scope->getType(<the call's receiver>)` — the inferred type of the thing the method is called on.
        // A node hook is handed types only at the positions it asked for, and the receiver is one of them, so
        // the descriptor *is* `$context->receiverType`. Any other position is refused rather than answered
        // about the wrong expression, which is the same constraint {@see typeQuery()} enforces.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getType'
            && $expr->var instanceof Variable
            && $expr->var->name === 'scope'
            && count($expr->getArgs()) === 1
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('getType() as a value, which only the PHP target carries', $line);
            }

            $of = $this->resolve($expr->getArgs()[0]->value, $line);

            // Compared against the vocabulary's own navigation to this node kind's receiver rather than a
            // hardcoded path, so a hook whose receiver is reached differently cannot pass by accident. The
            // receiver arrives ready-made under `ReceiverType`, so it is preferred where it applies.
            $receiver = Vocabulary::FIELDS[$this->context->nodeKind]['var'][2] ?? null;
            if ($receiver !== null && ($of['php'] ?? null) === $receiver) {
                $this->context->usesReceiverType = true;

                return ['rust' => self::PHP_ONLY, 'kind' => 'type', 'php' => '$context->receiverType'];
            }

            // Any other position is asked for by node. Probed rather than assumed, because this document and
            // its correction both had it wrong: a *node* hook that requests `ExpressionTypes` can ask
            // `$context->analysis->getExpressionType($node)` for any sub-expression, so no after-file hook is
            // needed. An array element of `[$this, 'handle']` answers `Fixture\Handler`.
            // A member name is a sub-expression like any other where it is computed — `$o->$n` names it with a
            // variable, and asking its type is how a rule tells a callable apart from a plain string.
            if (! in_array($of['kind'], ['expr', 'found-node', 'argument', 'const-item', 'name-part'], true)) {
                throw new Refusal("the inferred type of a {$of['kind']}", $line);
            }

            $this->context->usesExpressionTypes = true;

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'type',
                'php' => 'Support::expressionType($context, ' . $this->operand($of) . ')',
            ];
        }

        // `$this->helper(..)` in value position — a label a rule builds for its message, most often. Reached
        // through the same producer machinery as an assignment, since it is the same helper either way.
        if ($this->isOwnMethodCall($expr)) {
            $method = $expr->name->toString();
            $declaring = $this->declaringOf($method);
            if ($declaring !== null) {
                $produced = $this->inlineValueProducer(
                    $this->findMethod($declaring['class'], $method),
                    $declaring,
                    $method,
                    array_values($expr->getArgs()),
                    $line,
                );

                if ($produced !== null) {
                    return $produced;
                }
            }
        }

        // `Helper::method(..)` where the helper's source is in the package — inlined as a producer, the same
        // way a static helper in a *condition* already is. A rule package puts small resolvers on their own
        // classes, and hand-translating each one is how a vocabulary gap becomes a per-package special case.
        if ($expr instanceof StaticCall
            && $expr->class instanceof Name
            && ! in_array($expr->class->getLast(), ['self', 'static', 'parent'], true)
        ) {
            $produced = $this->inlineStaticProducer($expr, $line);
            if ($produced !== null) {
                return $produced;
            }
        }

        // `TypeCombinator::removeNull(<a type>)`. Not a no-op: a `?Widget` receiver carries a null atomic
        // beside the object one, so whether it was dropped decides whether the single-class question can be
        // answered at all — which is why the two are separate helpers rather than one tolerant one.
        if ($expr instanceof StaticCall
            && $expr->class instanceof Name
            && $expr->class->getLast() === 'TypeCombinator'
            && $this->memberName($expr->name, $expr->getStartLine()) === 'removeNull'
            && count($expr->getArgs()) === 1
        ) {
            $of = $this->resolve($expr->getArgs()[0]->value, $line);
            if ($of['kind'] !== 'type') {
                throw new Refusal("removeNull() of a {$of['kind']} rather than of an inferred type", $line);
            }

            return ['rust' => self::PHP_ONLY, 'kind' => 'type-without-null', 'php' => $this->operand($of)];
        }

        // `->getValue()` on a constant-string type — the literal behind it.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getValue'
            && $expr->args === []
        ) {
            $of = $this->resolve($expr->var, $line);
            if ($of['kind'] === 'type') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a constant type\u{2019}s value, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'php' => 'Support::constantStringOf(' . $this->operand($of) . ')',
                ];
            }

            // An element of `getConstantStrings()`. PHPStan hands back a `ConstantStringType` per element and
            // the rule asks each for its value; the list here already holds the values, so the reduction is
            // the identity and only the kind changes.
            if ($of['kind'] === 'constant-string') {
                return ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => $this->operand($of)];
            }
        }

        // `->getConstantStrings()` on a type — every literal string it names, which a rule walks.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getConstantStrings'
            && $expr->args === []
        ) {
            $of = $this->resolve($expr->var, $line);
            if ($of['kind'] !== 'type') {
                throw new Refusal("getConstantStrings() of a {$of['kind']} rather than of a type", $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('the constant strings of a type, which only the PHP target carries', $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'constant-strings',
                'php' => 'Support::constantStringsOf(' . $this->operand($of) . ')',
            ];
        }

        // `getObjectClassReflections()` on a type. Mago has no reflection object, and a rule only ever asks
        // this to find out whether the receiver is one concrete class and which — so the list stands for that
        // one name, and `count(..) === 1` becomes "there is a name".
        // `getObjectClassNames()` asks for the same list as `getObjectClassReflections()` — the names rather
        // than reflections of them, and the names are all this translation ever kept.
        if ($expr instanceof MethodCall
            && in_array($this->memberName($expr->name, $expr->getStartLine()), ['getObjectClassReflections', 'getObjectClassNames'], true)
        ) {
            $of = $this->resolve($expr->var, $line);
            if (! in_array($of['kind'], ['type', 'type-without-null'], true)) {
                throw new Refusal("getObjectClassReflections() of a {$of['kind']}", $line);
            }

            $stripped = $of['kind'] === 'type-without-null';
            $helper = $stripped ? 'soleObjectClassIgnoringNull' : 'soleObjectClass';
            // The list follows the same strip. It did not, and the single-class rendering was the only one
            // any rule reached, so a nullable receiver answered the empty list to every rule that iterated —
            // emitted, loaded, ran, reported nothing. That is the failure static checks cannot see.
            $list = $stripped ? 'objectClassesIgnoringNull' : 'objectClasses';

            // Two renderings of one question, because rules ask it two ways. Most ask `count(..) === 1` and
            // then use the name, which is what `sole-class` is for. One iterates the list instead, and giving
            // that the single-class reduction would go silent on a union receiver — narrower than the rule, in
            // the direction this project refuses. So the list travels with it.
            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'sole-class',
                'php' => 'Support::' . $helper . '(' . $this->operand($of) . ')',
                'listPhp' => 'Support::' . $list . '(' . $this->operand($of) . ')',
            ];
        }

        // `getParams()` on a declaration under analysis — the parameters as *written*, not the metadata a
        // reflection lookup returns. A Symfony config file is a closure taking a `ContainerConfigurator`, so the
        // whole config-closure family gates on this list and on the first parameter's written type.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getParams'
        ) {
            $of = $this->resolve($expr->var, $line);
            if ($of['kind'] !== 'hook-node') {
                throw new Refusal("getParams() of a {$of['kind']} rather than of the declaration under analysis", $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('getParams(), which only the PHP target carries', $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'param-decls',
                'php' => 'Support::declaredParams($context, $node)',
            ];
        }

        // `getVariants()` on a method handle. PHPStan models a function-like as one or more *variants* — a
        // stubbed overload set — and Mago does not: `FunctionLikeMetadata` carries exactly one `parameters`
        // list. So the variant list stands for the handle itself, and asking for its single member gives the
        // handle back.
        //
        // The divergence, documented rather than guessed at: a rule that reaches the multi-variant branch and
        // selects by argument types gets the single list here instead. Mago has no second list to choose from,
        // so there is nothing to refuse *to* — a genuinely overloaded stub is where the two disagree.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getVariants'
        ) {
            $subject = $this->resolve($expr->var, $line);
            if ($subject['kind'] === 'method-handle') {
                return ['kind' => 'variants'] + $subject;
            }
        }

        if ($expr instanceof Ternary) {
            $single = $this->singleVariant($expr, $line);
            if ($single !== null) {
                return $single;
            }
        }

        // `getParameters()` on a variant, and a position read out of the list it hands back. Neither exists as
        // a value in Mago, so both stay the method handle and the position rides along with it: every question
        // a rule asks of a parameter takes the class, the method and the index.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getParameters'
        ) {
            $subject = $this->resolve($expr->var, $line);
            if (in_array($subject['kind'], ['variants', 'method-handle'], true)) {
                return ['kind' => 'parameters'] + $subject;
            }
        }

        // `$parameters[$i]` and `$parameters[$i] ?? null` — a position in a parameter list. The `?? null` says
        // the position may not exist, which is what the helpers answer with null anyway, so it needs no
        // translation of its own.
        if ($expr instanceof Coalesce && $this->isNullConstant($expr->right)) {
            return $this->resolve($expr->left, $line);
        }

        // `$cache[$k]` where `$cache` is a cache this dropped: the read *is* the question the cache stood for.
        // Before the generic read below, because the cache is not an array in the emitted plugin — there is
        // nothing to index.
        if ($expr instanceof ArrayDimFetch
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && isset($this->context->caches[$expr->var->name])
        ) {
            $cached = $this->context->caches[$expr->var->name];
            if ($cached['kind'] === 'unfilled-cache') {
                throw new Refusal('a cache read before anything filled it', $line);
            }

            return $cached;
        }

        if ($expr instanceof ArrayDimFetch && $expr->dim instanceof Expr) {
            $list = $this->resolve($expr->var, $line);

            // A field of a produced record. Bound at transpile time by the producer, so this is a lookup in
            // that table rather than an array read in the emitted plugin.
            if ($list['kind'] === 'record') {
                $field = $this->rawStringLiteral($expr->dim, $line);
                $found = $list['record'][$field] ?? throw new Refusal("the record carries no {$field}", $line);
                if ($found['kind'] === 'unresolved') {
                    throw new Refusal("the record's {$field} is " . ($found['reason'] ?? 'outside the vocabulary'), $line);
                }

                return $found;
            }

            // `$classReflections[0]` — the sole class the list stands for, or null, which every helper that
            // takes it tolerates.
            if ($list['kind'] === 'sole-class') {
                return ['rust' => self::PHP_ONLY, 'kind' => 'named-class', 'php' => $this->operand($list)];
            }

            // `$closure->getParams()[0]` — a position in a declaration's parameter list.
            if ($list['kind'] === 'param-decls') {
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'param-decl',
                    'key' => $this->exprKey($expr),
                    'php' => 'Support::declaredParamAt($context, $node, ' . $this->intLiteral($expr->dim, $line) . ')',
                ];
            }

            // `$variants[0]` is the one variant Mago has, so the index says nothing and the handle carries
            // through unchanged. Answered before the index is resolved, because a literal `0` is not a value
            // this vocabulary resolves and does not need to be.
            if ($list['kind'] === 'variants') {
                return $list;
            }

            if ($list['kind'] === 'parameters') {
                $index = $this->resolve($expr->dim, $line);
                if ($index['kind'] !== 'int') {
                    throw new Refusal("a parameter read at a {$index['kind']} rather than at a position", $line);
                }

                return ['kind' => 'parameter', 'indexPhp' => $this->operand($index)] + $list;
            }
        }

        // `getFileName()` on a class the rule resolved — asking where *another* file is so its source can be
        // parsed. Refused by name rather than as an access path, because the accessor is not the obstacle and
        // the reason is not that the SDK cannot answer it. It can, and the route is measured
        // (`internal/probe-declaring-file-body.php`): `Codebase::getDeclaringMethod()` gives the declaring
        // file, `AfterAnalysisContext->analysis->files` finds that file's analysis, and `getSourceFile()` hands
        // over its tree. What cannot do it is a *node* hook — `FileAnalysis::getSourceFile()` and
        // `getNodeSourceFile()` both take no argument and answer about the one file the hook was given.
        //
        // So the obstacle is the hook kind, and the cost is the rule's other checks: a merged rule bundles
        // sub-rules that are node-shaped and translate today, and moving the whole rule to a whole-project hook
        // to serve one of them gives up the per-node dispatch and the inferred types the rest depend on. The
        // transpiler would also have to choose the hook from what a rule needs rather than from its
        // `getNodeType()` — the same change the collector-shaped rules want, and theirs to land with.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getFileName'
            && $expr->args === []
        ) {
            throw new Refusal(
                'the file another class is declared in, so its source can be parsed. Reachable, but only from a '
                . 'whole-project hook: a node hook is handed one file. Moving a merged rule there to serve one '
                . 'of its checks gives up the per-node dispatch its other checks need',
                $line,
            );
        }

        // `getDeclaringClass()` on a method handle — the class a method *comes from*, not the receiver. A rule
        // gates on it so a first-party class inheriting a vendor method is judged by where the method is
        // declared, and Mago answers exactly that question.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getDeclaringClass'
        ) {
            $subject = $this->resolve($expr->var, $line);
            if ($subject['kind'] === 'method-handle') {
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'named-class',
                    'php' => 'Support::declaringClassOfMethod($context, ' . $this->handlePart($subject, 'classPhp', $line)
                        . ', ' . $this->handlePart($subject, 'methodPhp', $line) . ')',
                ];
            }
        }

        // `$reflectionProvider->getFunction($name, $scope)->getName()` — the name the codebase knows the
        // function under, which is what a rule compares against when a namespaced call may fall back to a
        // global function.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getName'
            && $expr->args === []
            && $expr->var instanceof MethodCall
            && $this->memberName($expr->var->name, $expr->var->getStartLine()) === 'getFunction'
            && count($expr->var->getArgs()) >= 1
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a resolved function name, which only the PHP target carries', $line);
            }

            $named = $this->resolve($expr->var->getArgs()[0]->value, $line);

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => $this->context->backend->call('function_name', ['$context', $this->nameText($named, $line)]),
            ];
        }

        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getName' && $expr->args === []) {
            $base = $this->resolve($expr->var, $line);

            // A constant read answers its own name from the codebase, resolved the way PHP resolves it. The
            // rule interpolates this into the message, so it is the *found* name rather than the text as
            // written — `PHP_EOL` inside a namespace is looked up prefixed and found bare.
            if ($base['kind'] === 'constant-read') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a constant name, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'php' => $this->context->backend->call('constant_name', ['$context', $this->operand($base)]),
                ];
            }

            // A class this transpiler already reduced to its name answers `getName()` with itself:
            // `getDeclaringClass()->getName()` and a class a loop bound both arrive here.
            if (in_array($base['kind'], ['named-class', 'class-name'], true)) {
                return ['rust' => $base['rust'], 'kind' => 'class-name'] + $base;
            }

            // Falls through rather than refusing when the base is something else — a parameter, for one, whose
            // `getName()` a later branch answers. Refusing here made this the only answer to `getName()` there
            // is, which cost three emitting rules the moment a bound class started arriving.
            if ($base['kind'] === 'class-reflection') {
                // Rust reads the name off the declaration hook's metadata, which only that hook is handed. The
                // PHP helper walks up to the enclosing class-like from whatever node fired, so any hook can
                // ask — and a rule asking "which class am I in" from a call hook is ordinary.
                if ($this->context->classFrom !== 'metadata' && Transpiler::$target !== 'php') {
                    throw new Refusal('getName() outside a declaration hook', $line);
                }

                $this->context->usesMetadata = $this->context->classFrom === 'metadata';

                return [
                    'rust' => 'support::metadata_name(metadata)',
                    'kind' => 'class-name',
                    'php' => 'Support::enclosingClassName($context, $node)',
                ];
            }
        }

        $parents = $this->resolveParentClassNames($expr, $line);
        if ($parents !== null) {
            return $parents;
        }

        // `$classLike->getConstants()` — the constant declarations written in a class-like body. php-parser's own
        // lookup on the declaration, not a reflection call: it answers with what this class writes, which is
        // what a rule reading their values wants.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getConstants'
            && $expr->args === []
        ) {
            $of = $this->resolve($expr->var, $line);
            if (in_array($of['kind'], ['hook-node', 'subtree', 'expr'], true)) {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('the constants a declaration writes, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'const-decls',
                    'php' => 'Support::constantDeclarations($context, ' . $this->operand($of) . ')',
                ];
            }
        }

        // `$scope->getFunctionName()` — the function or method the node sits in, which a rule uses to exempt
        // magic accessors from a rule about dynamic names.
        if ($expr instanceof MethodCall
            && $expr->var instanceof Variable
            && $expr->var->name === 'scope'
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getFunctionName'
            && $expr->args === []
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('the enclosing function name, which only the PHP target carries', $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => 'Support::enclosingFunctionName($context, $node)',
            ];
        }

        $traits = $this->resolveUsedTraitNames($expr, $line);
        if ($traits !== null) {
            return $traits;
        }

        // `$classReflection->getDisplayName()` — how PHPStan prints the class in a message, which for a plain
        // declaration is its fully qualified name.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getDisplayName'
            && $expr->args === []
            && $this->resolve($expr->var, $line)['kind'] === 'class-reflection'
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a class display name, which only the PHP target carries', $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'class-name',
                'php' => 'Support::enclosingClassName($context, $node)',
            ];
        }

        // Reflection on the code under analysis, performed at the analyser's own runtime. PHPStan can answer it
        // because larastan boots the application in the same process, so anything that application registers at
        // runtime is loaded by the time a rule asks.
        //
        // Probed in a real worker (`internal/probe-facade-alias.php`), because the two halves of this are easy
        // to assume and were worth separating: a worker autoloading the same vendor tree resolves
        // `Illuminate\Support\Facades\Cache` but not the alias `Cache`, `AliasLoader` is never loaded, and
        // `getcwd()` is the config's directory rather than the project root — so the discovery larastan uses to
        // find `bootstrap/app.php` would not find it either. The same file booted through larastan's bootstrap
        // resolves all three aliases.
        //
        // So a port would load, ask, find nothing, and look complete doing it. Refused by name: booting an
        // application inside every analyser worker is a decision about what a plugin *is*, not a translation.
        if ($expr instanceof New_
            && $expr->class instanceof Name
            && str_starts_with($expr->class->getLast(), 'Reflection')
        ) {
            throw new Refusal(
                'runtime reflection on the analysed code: a plugin worker autoloads the project but does not '
                . 'boot it, so a name the application registers at runtime resolves to nothing. Where the '
                . 'question behind it is answerable from the codebase, translate that instead — this refusal is '
                . 'about the route the rule took, not about the question it asks',
                $line,
            );
        }

        // `new NodeFinder()` — php-parser's subtree search. Stateless, so the handle carries nothing and only the
        // calls on it translate. A `find()`/`findFirst()` with a closure filter is refused there by name.
        if ($expr instanceof New_ && $expr->class instanceof Name && $expr->class->getLast() === 'NodeFinder') {
            return ['rust' => self::PHP_ONLY, 'kind' => 'node-finder', 'php' => self::PHP_ONLY];
        }

        // `$node->stmts` — the statements a node holds, not the node. For a rule counting nested `foreach`
        // statements the distinction is the rule: searching the node itself finds the one it started from.
        if ($expr instanceof PropertyFetch
            && $this->memberName($expr->name, $expr->getStartLine()) === 'stmts'
        ) {
            $base = $this->resolve($expr->var, $line);
            if ($base['kind'] === 'hook-node') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('->stmts, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'subtree',
                    'php' => 'Support::bodyOf($context, ' . $this->operand($base) . ')',
                ];
            }
        }

        // `$nodeFinder->findInstanceOf(<subtree>, Some::class)` — every node of a kind below the subtree.
        if ($expr instanceof MethodCall
            && in_array($this->memberName($expr->name, $expr->getStartLine()), ['findInstanceOf', 'findFirstInstanceOf'], true)
            && count($expr->getArgs()) === 2
        ) {
            $finder = $this->resolve($expr->var, $line);
            if ($finder['kind'] !== 'node-finder') {
                throw new Refusal("findInstanceOf() on a {$finder['kind']} rather than on a node finder", $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('a subtree search, which only the PHP target carries', $line);
            }

            $searched = $this->resolveClassName($expr->getArgs()[1]->value instanceof ClassConstFetch
                && $expr->getArgs()[1]->value->class instanceof Name
                    ? $expr->getArgs()[1]->value->class
                    : throw new Refusal('a subtree search for something other than a node class', $line));

            $kinds = Vocabulary::SEARCHABLE[$searched]
                ?? throw new Refusal("no searchable node kind mapped for {$searched}", $line);

            $within = $this->subtreeArgument($expr->getArgs()[0]->value, $line);
            $found = 'Support::findKind($context, ' . $within . ", ['" . implode("', '", $kinds) . "'])";
            $first = $this->memberName($expr->name, $expr->getStartLine()) === 'findFirstInstanceOf';

            $descriptor = [
                'rust' => self::PHP_ONLY,
                'kind' => $first ? 'found-node' : 'found-nodes',
                'php' => $first ? '(' . $found . '[0] ?? null)' : $found,
            ];

            // What was searched for is what was found, so each node knows its own kind and can be navigated like
            // the hook's own. A search naming several kinds has no single set of fields, so it navigates through
            // a group instead — only the fields every kind in it answers the same way.
            if (count($kinds) === 1) {
                $descriptor['as'] = $kinds[0];
            } elseif (isset(Vocabulary::FIELD_GROUPS[$searched])) {
                $descriptor['as'] = Vocabulary::FIELD_GROUPS[$searched];
            }

            return $descriptor;
        }

        // `getDocComment()` on a declaration. Mago hands comments back as file-level trivia, so the helper both
        // finds the right one and reads its text — which means the descriptor is already the text, and
        // `->getText()` on it is the identity.
        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getDocComment') {
            $base = $this->resolve($expr->var, $line);
            if (Transpiler::$target !== 'php') {
                throw new Refusal('getDocComment(), which only the PHP target carries', $line);
            }

            if (! in_array($base['kind'], ['method-decl', 'maybe-method-decl', 'hook-node', 'property'], true)) {
                throw new Refusal("getDocComment() on a {$base['kind']}", $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'docblock',
                'php' => 'Support::docblockText($context, ' . $this->operand($base) . ')',
            ];
        }

        // `<a doc parser>->parseNode($x)` — the parsed docblock of `$x`. A parsed docblock and its text stand
        // for the same thing here: the only questions a rule asks of one are which tags it carries, and those
        // are answered from the text. The parser itself cannot be inlined — its own dependencies are PHPStan's
        // `PhpDocParser` and `Lexer` — so the *question* is mapped rather than the collaborator.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'parseNode'
            && count($expr->getArgs()) === 1
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a parsed docblock, which only the PHP target carries', $line);
            }

            $of = $this->resolve($expr->getArgs()[0]->value, $line);
            if (! in_array($of['kind'], ['hook-node', 'method-decl', 'maybe-method-decl', 'property'], true)) {
                throw new Refusal("a docblock parsed off a {$of['kind']}", $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'docblock',
                'php' => 'Support::docblockText($context, ' . $this->operand($this->asDeclarationPart($of)) . ')',
            ];
        }

        // `getTagsByName('@tag')` on a parsed docblock — the tags it carries under that name. A rule only ever
        // asks whether there are any, so the descriptor is that list and emptiness is the question.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getTagsByName'
            && count($expr->getArgs()) === 1
        ) {
            $base = $this->resolve($expr->var, $line);
            if ($base['kind'] === 'docblock') {
                $tag = $this->stringLiteral($expr->getArgs()[0]->value, $line);

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'docblock-tags',
                    'php' => 'Support::docblockTags(' . $this->operand($base) . ', ' . $this->context->backend->bytes($tag) . ')',
                ];
            }
        }

        // `getText()` on a docblock is the docblock, since the descriptor is already its text.
        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getText') {
            $base = $this->resolve($expr->var, $line);
            if ($base['kind'] === 'docblock') {
                return ['rust' => $base['rust'], 'kind' => 'bytes', 'php' => $this->operand($base)];
            }
        }

        // `getAttrGroups()` on a declaration — the `#[..]` groups written on it.
        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getAttrGroups') {
            $base = $this->resolve($expr->var, $line);
            if (Transpiler::$target !== 'php') {
                throw new Refusal('getAttrGroups(), which only the PHP target carries', $line);
            }

            if (! in_array($base['kind'], ['method-decl', 'maybe-method-decl', 'hook-node', 'property'], true)) {
                throw new Refusal("getAttrGroups() on a {$base['kind']}", $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'attr-groups',
                'php' => 'Support::attributeGroups(' . $this->operand($base) . ')',
            ];
        }

        // `getMethods()` on the class-like under analysis — the methods written in its body, which is what a
        // rule looping them and reporting per method asks for.
        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getMethods') {
            $base = $this->resolve($expr->var, $line);
            if ($base['kind'] !== 'hook-node') {
                throw new Refusal("getMethods() on a {$base['kind']} rather than on the class-like", $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('getMethods(), which only the PHP target carries', $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'method-members',
                'php' => 'Support::classMethods($context, $node)',
            ];
        }

        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getProperties') {
            $base = $this->resolve($expr->var, $line);
            if ($base['kind'] !== 'hook-node') {
                throw new Refusal('getProperties() on something other than the class-like', $line);
            }

            return [
                'rust' => 'support::class_properties(node)',
                'kind' => 'property-members',
                'php' => 'Support::classProperties($context, $node)',
            ];
        }

        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getArgs') {
            // Asked of the hook's own node, or of a node a rule found: the arguments are the same thing either way,
            // and only the second needs saying which node.
            $of = $this->resolve($expr->var, $line);
            $path = $of['kind'] === 'hook-node'
                ? $this->argListPath($line)
                : $this->argListPath($line, $of);

            $args = ['rust' => $path, 'kind' => 'args'];
            if (Transpiler::$target === 'php') {
                $args['php'] = $path;
            }

            return $args;
        }

        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'toString') {
            return $this->resolve($expr->var, $line);
        }

        // `toLowerString()` is `toString()` plus a fold, which rules use so a comparison ignores how a name was
        // written — `TRUE` and `true` are the same constant.
        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'toLowerString') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('toLowerString(), which only the PHP target carries', $line);
            }

            $subject = $this->resolve($expr->var, $line);

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => 'Support::lowerBytes(' . $this->operand($subject) . ')',
            ];
        }

        // A string built at analysis time rather than known at transpile time. A configured namespace is
        // normalised before it is compared — `rtrim($namespace, '\\') . '\\'` — and the value only exists once
        // the plugin is running, so the trim and the concatenation are emitted rather than folded.
        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && in_array($expr->name->toString(), ['rtrim', 'ltrim', 'trim'], true)
            && count($expr->getArgs()) === 2
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal($expr->name->toString() . '() of an analysis-time value, which only the PHP target carries', $line);
            }

            $subject = $this->resolve($expr->getArgs()[0]->value, $line);
            if (! in_array($subject['kind'], ['bytes', 'class-name', 'config-bytes', 'resolved-name'], true)) {
                throw new Refusal($expr->name->toString() . "() of a {$subject['kind']}", $line);
            }

            $characters = $this->stringOperand($expr->getArgs()[1]->value, $line);
            $built = $expr->name->toString() . '(' . $this->operand($subject) . ', ' . $characters . ')';

            return ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => $built];
        }

        if ($expr instanceof Concat) {
            // Two literals still fold, so emitted output stays what it was before this branch existed.
            try {
                $folded = $this->rawStringLiteral($expr, $line);

                return ['rust' => $this->context->backend->bytes($folded), 'kind' => 'bytes', 'php' => $this->context->backend->bytes($folded)];
            } catch (Refusal) {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a string built at analysis time, which only the PHP target carries', $line);
                }
            }

            $built = $this->stringOperand($expr->left, $line) . ' . ' . $this->stringOperand($expr->right, $line);

            return ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => '(' . $built . ')'];
        }

        // The attribute PHPStan marks a synthetic nullsafe dispatch with, which Mago has no equivalent of —
        // see the `NullsafeMethodCall` entry in {@see Vocabulary::HOOKS}. Never set, so never true.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getAttribute'
            && count($expr->getArgs()) === 1
            && $this->rawStringLiteral($expr->getArgs()[0]->value, $line) === 'virtualNullsafeMethodCall'
        ) {
            return ['rust' => 'false', 'kind' => 'never', 'php' => 'false'];
        }

        // `implode('", "', $values)` — a computed list joined into a message. The glue has to be written out:
        // a computed separator would put a value the plugin cannot see into the text a reader compares.
        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'implode'
            && count($expr->getArgs()) === 2
        ) {
            $glue = $expr->getArgs()[0]->value;
            $of = $this->resolve($expr->getArgs()[1]->value, $line);
            if ($glue instanceof String_ && (isset(Vocabulary::ITERABLES[$of['kind']]) || $of['kind'] === 'list')) {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a joined list, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'php' => 'implode(' . $this->context->backend->bytes($glue->value) . ', ' . $this->operand($of) . ')',
                ];
            }
        }

        // `array_values(array_unique($names))` closing a helper that collected names. Both are re-shapings of the
        // list, and both are kept rather than dropped: a rule that only tests membership would not notice
        // either, but reasoning about which consumer a producer has is exactly the step that gets a port wrong.
        // PHP has both functions, so the honest translation is to emit them.
        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && in_array($expr->name->toString(), ['array_values', 'array_unique'], true)
            && count($expr->getArgs()) === 1
        ) {
            $subject = $this->resolve($expr->getArgs()[0]->value, $line);
            if (! isset(Vocabulary::ITERABLES[$subject['kind']]) && $subject['kind'] !== 'list') {
                throw new Refusal("{$expr->name->toString()}() over a {$subject['kind']}", $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal("{$expr->name->toString()}(), which only the PHP target carries", $line);
            }

            // What the list holds is unchanged by re-shaping it, and it decides whether a later comparison
            // against one of its items folds case.
            return [
                'rust' => self::PHP_ONLY,
                'kind' => $subject['kind'],
                'php' => $expr->name->toString() . '(' . $this->operand($subject) . ')',
            ] + (isset($subject['as']) ? ['as' => $subject['as']] : []);
        }

        // count(<args>) as a value rather than as one side of a comparison, which {@see equality()} handles.
        // `count($args) - 1` is how a rule names the last argument's position.
        if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toString() === 'count') {
            $subject = $this->resolve($expr->getArgs()[0]->value, $line);
            if ($subject['kind'] !== 'args') {
                // Not an argument list, so it is one of the plain lists this vocabulary produces, whose length is
                // just its length.
                $counted = $this->countable($expr, $line);

                return ['rust' => self::PHP_ONLY, 'kind' => 'int', 'php' => $counted];
            }

            $counted = $this->context->backend->call('arg_count', [$this->operand($subject)]);

            return ['rust' => $counted, 'kind' => 'int', 'php' => $counted];
        }

        // `count($args) - 1` and `count($found) + 1`: a count offset by a fixed amount, which is how a rule names
        // a position or reports a total that includes the node it started from.
        if ($expr instanceof Minus || $expr instanceof Plus) {
            $counted = $this->resolve($expr->left, $line);
            if ($counted['kind'] !== 'int') {
                throw new Refusal("arithmetic on a {$counted['kind']} rather than on a count", $line);
            }

            $value = $this->operand($counted)
                . ($expr instanceof Minus ? ' - ' : ' + ')
                . $this->intLiteral($expr->right, $line);

            return ['rust' => $value, 'kind' => 'int', 'php' => $value];
        }

        if ($expr instanceof PropertyFetch) {
            $property = $this->memberName($expr->name, $expr->getStartLine());
            $key = $this->exprKey($expr);

            $own = $this->resolveOwnProperty($expr, $property, $key, $line);
            if ($own !== null) {
                return $own;
            }

            // A narrowing binding for this exact path takes precedence.
            $baseKey = $this->exprKey($expr->var);
            if (isset($this->context->refinements[$baseKey][$property])) {
                $refined = $this->context->refinements[$baseKey][$property];
                [$rust, $kind] = $refined;
                if (isset($refined[2])) {
                    return ['rust' => $rust, 'kind' => $kind, 'key' => $key, 'php' => $refined[2]];
                }

                return ['rust' => $rust, 'kind' => $kind, 'key' => $key];
            }

            $base = $this->resolve($expr->var, $line);
            if (isset($base['fields'][$property])) {
                [$rust, $kind] = $base['fields'][$property];

                return ['rust' => $rust, 'kind' => $kind, 'key' => $key];
            }

            // Keyed on what the node *is*, not on what the hook fired for. A descriptor carries `as` when its
            // node kind is known from where it came — every node a subtree search found is of the kind that was
            // searched for — and the hook's own node is the kind the hook targets.
            $navigating = $base['kind'] === 'hook-node' ? $this->context->nodeKind : ($base['as'] ?? null);
            if ($navigating !== null && isset(Vocabulary::FIELDS[$navigating][$property])) {
                [$rust, $kind] = Vocabulary::FIELDS[$navigating][$property];
                $descriptor = ['rust' => $rust, 'kind' => $kind, 'key' => $key];
                $php = Vocabulary::FIELDS[$navigating][$property][2] ?? null;
                if ($php !== null) {
                    $descriptor['php'] = str_replace('{base}', $this->operand($base), $php);
                }

                return $descriptor;
            }

            // `->value` on a plain expression is php-parser's `String_->value`, the quoted string's contents.
            // Null-tolerant for the same reason as `->name` below, and guarded the same way: the rule's own
            // `instanceof String_` is what makes sure this is only asked of a string.
            if ($base['kind'] === 'expr' && $property === 'value' && Transpiler::$target === 'php') {
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'key' => $key,
                    'php' => 'Support::literalStringValue($context, ' . $this->operand($base) . ')',
                ];
            }

            // `->name` on a plain expression is php-parser's `ConstFetch->name`. Null-tolerant on purpose:
            // reading the name of something that is not a constant name has no answer, and the rule's own
            // `instanceof ConstFetch` guard is what makes sure it never asks.
            if ($base['kind'] === 'expr' && $property === 'name' && Transpiler::$target === 'php') {
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'key' => $key,
                    'php' => 'Support::constantNameText(' . $this->operand($base) . ')',
                ];
            }

            // `->value` on an array element is the element itself. Probed: the `ArrayElement` category node
            // and the `ValueArrayElement` beneath it carry the same text and the same inferred type, so there
            // is nothing to navigate to.
            if ($base['kind'] === 'array-element' && $property === 'value') {
                return ['rust' => $base['rust'], 'kind' => 'expr', 'key' => $key, 'php' => $this->operand($base)];
            }

            if ($base['kind'] === 'arg' && $property === 'value') {
                // `->value` on an argument is the argument itself once bound, since the binding already
                // unwrapped it, so the rendering carries over unchanged.
                $value = ['rust' => $base['rust'], 'kind' => 'expr', 'key' => $key];
                if (isset($base['php'])) {
                    $value['php'] = $base['php'];
                }

                return $value;
            }

            if (isset(Vocabulary::KIND_FIELDS[$base['kind']][$property])) {
                [$template, $kind] = Vocabulary::KIND_FIELDS[$base['kind']][$property];
                $descriptor = ['rust' => str_replace('{base}', $base['rust'], $template), 'kind' => $kind, 'key' => $key];
                if (isset($base['php'])) {
                    // Read directly rather than through a fallback: every entry carries a PHP navigation, and
                    // reading it as if it might not means a future entry that omits one fails silently on the
                    // PHP target instead of being caught where it is written.
                    $descriptor['php'] = str_replace('{base}', $base['php'], Vocabulary::KIND_FIELDS[$base['kind']][$property][2]);
                }

                return $descriptor;
            }

            if ($base['kind'] === 'hint-option' && $property === 'types') {
                $parts = ['rust' => "support::hint_parts({$base['rust']})", 'kind' => 'hint-parts', 'key' => $key];
                if (isset($base['php'])) {
                    $parts['php'] = $this->context->backend->call('hint_parts', [$base['php']]);
                }

                return $parts;
            }

            if ($base['kind'] === 'const-item' && $property === 'name') {
                return [
                    'rust' => "support::constant_item_name({$base['rust']})",
                    'kind' => 'bytes',
                    'key' => $key,
                    'php' => $this->context->backend->call('constant_item_name', [$this->operand($base)]),
                ];
            }

            if ($base['kind'] === 'expr' && $property === 'name') {
                // The name of an as-yet-unnarrowed expression; only comparisons can use this.
                return ['rust' => $base['rust'], 'kind' => 'expr', 'key' => $key];
            }

            // `$node->name->name` on a Name node is its text, the same thing `->toString()` yields. Both
            // spellings appear in real rules, so both resolve to the name itself rather than a new kind.
            $declared = $this->resolvePropertyDeclaration($base, $property, $key, $line);
            if ($declared !== null) {
                return $declared;
            }

            if (in_array($base['kind'], ['name-expr', 'name-selector', 'local-name'], true) && $property === 'name') {
                return $base + ['key' => $key];
            }

            if (Transpiler::$survey) {
                $this->assume("a mapping for ->{$property} on a {$base['kind']}");

                return ['rust' => "node.{$property}", 'kind' => 'expr', 'key' => $key];
            }

            throw new Refusal("no mapping for ->{$property} on a {$base['kind']}", $line);
        }

        // `getName()` on a class handle is the name the handle already is.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getName'
            && $expr->args === []
        ) {
            $subject = $this->resolve($expr->var, $line);
            if ($subject['kind'] === 'named-class') {
                return ['rust' => self::PHP_ONLY, 'kind' => 'class-name', 'php' => $this->operand($subject)];
            }

            if ($subject['kind'] === 'parameter') {
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'php' => $this->parameterQuestion('parameter_name', $subject, $line),
                ];
            }
        }

        $looked = $this->resolveMethodLookup($expr, $line);
        if ($looked !== null) {
            return $looked;
        }

        // `getConstructor()` and `getMethod($name, $scope)` on a class handle. Mago has no reflection object,
        // and every question a rule asks of one — a parameter's name, whether it is variadic, which class
        // declares it — takes the class and the method name, so the handle is that pair.
        if ($expr instanceof MethodCall
            && in_array($this->memberName($expr->name, $expr->getStartLine()), ['getConstructor', 'getMethod'], true)
        ) {
            $subject = $this->resolve($expr->var, $line);
            // A class name a loop bound stands for the class, exactly as a reflection handle does.
            if ($subject['kind'] === 'class-name') {
                $subject['kind'] = 'named-class';
            }

            // The declaration the hook fired for, asked about its own methods. The handle is a class name and a
            // method name, so the enclosing class supplies the first exactly as a written name would.
            if ($subject['kind'] === 'class-reflection' && Transpiler::$target === 'php') {
                $subject = [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'named-class',
                    'php' => 'Support::enclosingClassName($context, $node)',
                ];
            }

            if ($subject['kind'] === 'named-class') {
                $asked = $this->memberName($expr->name, $expr->getStartLine());
                $named = $asked === 'getConstructor'
                    ? $this->context->backend->bytes('__construct')
                    : $this->operand($this->methodNameArgument($expr->getArgs(), $asked, $line));

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'method-handle',
                    'php' => self::PHP_ONLY,
                    'classPhp' => $this->operand($subject),
                    'methodPhp' => $named,
                ];
            }
        }

        // $reflectionProvider->getClass($className) — a handle on a class named at analysis time. Mago has no
        // reflection object to stand in for one, and needs none: every question a rule asks of it takes the
        // class name, so the handle *is* the name.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getClass'
            && count($expr->getArgs()) === 1
            && $this->serviceArgument($expr->var, $line) === 'reflectionProvider'
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('getClass(), which only the PHP target carries', $line);
            }

            $named = $this->resolve($expr->getArgs()[0]->value, $line);
            if (! in_array($named['kind'], ['resolved-name', 'class-name', 'bytes'], true)) {
                throw new Refusal("getClass() of a {$named['kind']} rather than of a class name", $line);
            }

            return ['rust' => self::PHP_ONLY, 'kind' => 'named-class', 'php' => $this->operand($named)];
        }

        // $scope->resolveName($node->class) — the written name with the file's imports and namespace applied
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'resolveName'
            && $expr->var instanceof Variable
            && $expr->var->name === 'scope'
            && count($expr->getArgs()) === 1
        ) {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('resolveName(), which only the PHP target carries', $line);
            }

            $written = $this->resolve($expr->getArgs()[0]->value, $line);
            if ($written['kind'] !== 'name-expr') {
                throw new Refusal('resolveName() over something other than a written name', $line);
            }

            return [
                'rust' => $this->unreachable('resolveName() is refused before this on the Rust targets'),
                'kind' => 'resolved-name',
                'php' => 'Support::resolvedName($context, ' . $this->operand($written) . ')',
            ];
        }

        return null;
    }

    /**
     * The descriptor for a PHP expression: how to say it in the target, and what kind of thing it is.
     *
     * `rust` and `php` are the same expression rendered for each target. A descriptor with no `php` key
     * has no navigation recipe yet, and {@see operand} refuses rather than guessing.
     *
     * @return Descriptor
     */
    private function resolve(Expr $expr, int $line): array
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            // Locals first, then the hook's node. A helper is free to call its parameter `$node` —
            // `hasRouteAnnotationOrAttribute(ClassLike|ClassMethod $node)` does — and answering the hook's node
            // for it read the wrong subtree while looking perfectly reasonable in the emitted file.
            if (isset($this->context->listAccumulators[$expr->name])) {
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'list',
                    'php' => '$' . $expr->name,
                    'as' => $this->context->listItemKinds[$expr->name] ?? '',
                ];
            }

            if (isset($this->context->locals[$expr->name])) {
                return $this->context->locals[$expr->name];
            }

            if ($expr->name === 'node') {
                return ['rust' => 'node', 'kind' => 'hook-node', 'key' => '$node', 'php' => '$node'];
            }

            throw new Refusal("unknown local \${$expr->name}", $line);
        }

        $namespace = $this->resolveScopeNamespace($expr, $line);
        if ($namespace !== null) {
            return $namespace;
        }

        if ($expr instanceof MethodCall
            // `getNodes()` on the file node is the same handle: it hands back the file's statements, and a
            // search from the `Program` node covers exactly those, since the root itself is never a match for
            // anything a rule looks for in them.
            && in_array($this->memberName($expr->name, $expr->getStartLine()), ['getOriginalNode', 'getNodes'], true)
            && $expr->var instanceof Variable
            && $expr->var->name === 'node'
        ) {
            return ['rust' => 'node', 'kind' => 'hook-node', 'key' => '$node', 'php' => '$node'];
        }

        if ($expr instanceof MethodCall
            // `getFileDescription()` is the same path for our purposes: PHPStan uses it for messages, and a
            // rule that tests it against a suffix is asking about the file either way.
            && in_array($this->memberName($expr->name, $expr->getStartLine()), ['getFile', 'getFileDescription'], true)
            && $expr->var instanceof Variable
            && $expr->var->name === 'scope'
        ) {
            return ['rust' => 'context', 'kind' => 'file', 'php' => '$context'];
        }

        // Reflection, inferred types and the handles they produce — a block of its own so `resolve()` stays a
        // dispatch rather than growing a second one inside it.
        $reflected = $this->resolveReflection($expr, $line);
        if ($reflected !== null) {
            return $reflected;
        }

        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getClassReflection'
            && $expr->var instanceof Variable
            && in_array($expr->var->name, ['scope', 'node'], true)
        ) {
            return ['rust' => 'context', 'kind' => 'class-reflection'];
        }

        // $node->get(SomeCollector::class)
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'get'
            && $expr->var instanceof Variable
            && $expr->var->name === 'node'
            && count($expr->getArgs()) === 1
        ) {
            $collector = $this->rawStringLiteral($expr->getArgs()[0]->value, $line);
            $short = substr($collector, (int) strrpos('\\' . $collector, '\\'));

            return ['rust' => "support::collected(\"{$short}\")", 'kind' => 'collected', 'collector' => $short];
        }

        // `$name->getLast()` — the last segment of a written name, which is what a rule comparing a function
        // name case-insensitively against `request` asks for: `\Acme\request` and `request` both answer
        // `request`.
        if ($expr instanceof MethodCall
            && $this->memberName($expr->name, $expr->getStartLine()) === 'getLast'
            && $expr->args === []
        ) {
            $of = $this->resolve($expr->var, $line);
            if (in_array($of['kind'], ['name-expr', 'name-selector', 'local-name', 'bytes', 'class-name'], true)) {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('a name\u{2019}s last segment, which only the PHP target carries', $line);
                }

                $text = in_array($of['kind'], ['bytes', 'class-name'], true)
                    ? $this->operand($of)
                    : $this->context->backend->call('text_of', [$this->operand($of)]);

                return ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => $this->context->backend->call('last_name_segment', [$text])];
            }
        }

        // `strtolower($x)` / `strtoupper($x)` as a *value*. Already in the pure set for a constructor
        // derivation; this is the same function reached at analysis time, where a rule folds a name's case
        // before looking it up in a table.
        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && in_array($expr->name->toString(), ['strtolower', 'strtoupper'], true)
            && count($expr->getArgs()) === 1
        ) {
            $of = $this->resolve($expr->getArgs()[0]->value, $line);
            if (! in_array($of['kind'], ['bytes', 'class-name', 'name-selector', 'local-name', 'name-expr'], true)) {
                throw new Refusal("{$expr->name->toString()}() of a {$of['kind']}", $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('a case fold as a value, which only the PHP target carries', $line);
            }

            $text = in_array($of['kind'], ['name-selector', 'local-name', 'name-expr'], true)
                ? $this->context->backend->call('text_of', [$this->operand($of)])
                : $this->operand($of);

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => $this->context->backend->call($expr->name->toString() === 'strtolower' ? 'lower_bytes' : 'upper_bytes', [$text]),
            ];
        }

        // `dirname($scope->getFile())` — the directory the analysed file sits in, which a rule building an
        // absolute path from a relative one needs. Only of the file: `dirname()` of anything else is a value
        // this has no rendering for.
        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'dirname'
            && count($expr->getArgs()) === 1
        ) {
            $of = $this->resolve($expr->getArgs()[0]->value, $line);
            if ($of['kind'] !== 'file') {
                throw new Refusal("dirname() of a {$of['kind']} rather than of the analysed file", $line);
            }

            if (Transpiler::$target !== 'php') {
                throw new Refusal('the analysed file’s directory, which only the PHP target carries', $line);
            }

            return ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => 'Support::fileDirectory($context)'];
        }

        // `$array->items[0]` — an element by position. Null when the literal has fewer, which is what the
        // rule's own `instanceof ArrayItem` guard then tests.
        if ($expr instanceof ArrayDimFetch && $expr->dim instanceof Int_) {
            $list = $this->resolve($expr->var, $line);
            if ($list['kind'] === 'array-items') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('an array element by position, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'array-element',
                    'php' => '(' . $this->operand($list) . '[' . $expr->dim->value . '] ?? null)',
                ];
            }
        }

        // `$matches['name']` — the group's value, or null when the match did not catch it.
        if ($expr instanceof ArrayDimFetch
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && ($this->context->locals[$expr->var->name]['kind'] ?? null) === 'captures'
            && $expr->dim instanceof Expr
        ) {
            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => $this->capturedGroup(
                    $this->context->locals[$expr->var->name],
                    $this->stringLiteral($expr->dim, $line),
                    $line,
                ),
            ];
        }

        // <args>[<a computed index>] — a literal index is folded into a binding earlier; this is the case
        // where the position itself is computed, which `$args[$lastIndex]` is.
        if ($expr instanceof ArrayDimFetch && $expr->dim instanceof Expr) {
            $list = $this->resolve($expr->var, $line);
            $index = $this->resolve($expr->dim, $line);
            if ($list['kind'] === 'args' && $index['kind'] === 'int') {
                if (Transpiler::$target !== 'php') {
                    throw new Refusal('an argument read at a computed index, which only the PHP target carries', $line);
                }

                // Wrapped rather than unwrapped, because a rule asks how the argument was *written* — named,
                // spread — before it asks what it holds. `->value` unwraps it from there.
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'argument',
                    'php' => 'Support::argumentAt(' . $this->operand($list) . ', ' . $this->operand($index) . ')',
                ];
            }
        }

        // A configuration getter with a literal default: `return $this->parameters['k'] ?? [];`. Answering
        // with the left alone is exact rather than lenient, and only because of where the default comes from:
        // the emitted plugin carries the package's own declared value for that parameter as a constructor
        // default, so the fallback has nothing left to fire on. {@see ConfigurationObject} states the same
        // reasoning for the aggregate path, which reached this shape first.
        //
        // Restricted to a configured read, because for anything else the fallback is load-bearing and
        // dropping it would answer a different question. The refusal names what the left turned out to be, so
        // the next shape to reach here says so instead of reading as a missing table row.
        if ($expr instanceof MethodCall) {
            $computed = $this->resolveCollaboratorCall($expr, $line);
            if ($computed !== null) {
                return $computed;
            }

            // Reached from here as well as from the predicate path: a threshold rule reads its limit inside a
            // comparison, which resolves operands rather than building a condition.
            $configured = $this->resolveValueObjectGetter($expr, $this->memberName($expr->name, $line), $line);
            if ($configured !== null) {
                return $configured;
            }
        }

        if ($expr instanceof Coalesce && $this->isLiteralDefault($expr->right)) {
            return $this->resolveConfiguredDefault($expr, $line);
        }

        // `$type->describe(VerbosityLevel::typeOnly())` — the rendering 27 rule classes interpolate into a
        // message. Rendered from the atomics rather than from `Type::__toString()`, and the difference is
        // measured rather than argued: over 243822 types at the positions those rules read from, 9.38 % come
        // out differently — a generic without its parameters, an intersection collapsed to its first member,
        // a nullable scalar reversed. {@see Describe} carries the counts and the fallback.
        //
        // Only `typeOnly()`. `value()` prints literals the shorter form collapses, and one rule asks for it;
        // refused by the verbosity it named rather than translated into the wrong one.
        if ($expr instanceof MethodCall && $this->memberName($expr->name, $line) === 'describe') {
            if (Transpiler::$target !== 'php') {
                throw new Refusal('a rendered type, which only the PHP target carries', $line);
            }

            $verbosity = $this->verbosityLevel($expr);
            if ($verbosity !== 'typeOnly') {
                throw new Refusal("describe(VerbosityLevel::{$verbosity}()), where only typeOnly() is rendered", $line);
            }

            $of = $this->resolve($expr->var, $line);
            if (! in_array($of['kind'], ['type', 'type-without-null'], true)) {
                throw new Refusal("describe() of a {$of['kind']} rather than of a type", $line);
            }

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => 'Support::describeType(' . $this->operand($of) . ')',
            ];
        }

        // An integer literal. Not a path to anything, which is why it had none — but the shapes that reach
        // here hand one to something that indexes: `$node->getArgs()[0]->value` inside `$scope->getType(..)`
        // is the whole of what ten census entries name, and the branch that reads an argument at a computed
        // index was already written and waiting for a subject of kind `int`.
        //
        // A literal index elsewhere is folded into a binding by the statement path, so this only reaches
        // positions where the index is read as a value rather than bound.
        if ($expr instanceof Int_) {
            return ['rust' => (string) $expr->value, 'kind' => 'int', 'php' => (string) $expr->value];
        }

        throw new Refusal('access path outside the vocabulary: ' . $this->describe($expr), $line);
    }

    /**
     * A configured read behind a `??` default, or a refusal naming what the left turned out to be.
     *
     * @return Descriptor
     */
    private function resolveConfiguredDefault(Coalesce $expr, int $line): array
    {
        $left = $this->resolve($expr->left, $line);
        if (str_starts_with($left['kind'], 'config-')) {
            return $left;
        }

        throw new Refusal(
            "a `??` default over a {$left['kind']}, where only a configured value carries its own default",
            $line,
        );
    }

    /**
     * Whether an expression is the empty literal standing in for "nothing configured".
     *
     * Only `[]`, because that is the only literal default the two packages carrying this shape actually
     * write — the other four getters in them fall back to a *second parameter*, which
     * {@see ConfigurationObject} handles as an alias. A wider set would be generality nothing asked for, and
     * a fallback that computes something is a second answer the getter can give: dropping it would make the
     * port always give the first.
     */
    private function isLiteralDefault(Expr $expr): bool
    {
        return $expr instanceof Array_ && $expr->items === [];
    }

    private function stringLiteral(Node $expr, int $line): string
    {
        if ($expr instanceof String_) {
            return addcslashes($expr->value, '"\\');
        }

        if ($expr instanceof ClassConstFetch) {
            return addcslashes($this->resolveClassConstant($expr, $line), '"\\');
        }

        throw new Refusal('expected a string literal', $line);
    }

    /** Like a string literal, but escaped for a Rust byte string holding a class name. */
    private function classLiteral(Node $expr, int $line): string
    {
        return str_replace('\\', '\\\\', $this->rawStringLiteral($expr, $line));
    }

    private function rawStringLiteral(Node $expr, int $line): string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        if ($expr instanceof ClassConstFetch) {
            return $this->resolveClassConstant($expr, $line);
        }

        // A literal built at transpile time from other literals. `ChecksNamespace` writes its prefix as
        // `rtrim($namespace, '\\') . '\\'`, where `$namespace` is a literal the caller bound, and folding
        // that is what lets the prefix be compared at all. Only these three trims and concatenation, and only
        // over things that are already literals — anything else stays a refusal.
        if ($expr instanceof Concat) {
            return $this->rawStringLiteral($expr->left, $line) . $this->rawStringLiteral($expr->right, $line);
        }

        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && in_array($expr->name->toString(), ['trim', 'rtrim', 'ltrim'], true)
            && count($expr->getArgs()) === 2
        ) {
            $subject = $this->rawStringLiteral($expr->getArgs()[0]->value, $line);
            $characters = $this->rawStringLiteral($expr->getArgs()[1]->value, $line);

            return match ($expr->name->toString()) {
                'trim' => trim($subject, $characters),
                'ltrim' => ltrim($subject, $characters),
                default => rtrim($subject, $characters),
            };
        }

        if ($expr instanceof Variable && is_string($expr->name) && isset($this->context->literals[$expr->name])) {
            return $this->context->literals[$expr->name];
        }

        throw new Refusal('expected a string literal', $line);
    }

    private function intLiteral(Node $expr, int $line): int
    {
        if ($expr instanceof Int_) {
            return $expr->value;
        }

        // A threshold a rule names rather than spells: `self::MAX_NESTED_FOREACHES`. Known at transpile time, so
        // it folds to the number, which is what the rule compares against.
        if ($expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && in_array($expr->class->toString(), ['self', 'static'], true)
        ) {
            $name = $this->memberName($expr->name, $line);
            if (isset($this->context->intConstants[$name])) {
                return $this->context->intConstants[$name];
            }
        }

        throw new Refusal('expected an integer literal', $line);
    }

    /** `Foo::class` -> the FQCN; `Foo::BAR` -> the constant's literal value, read from source. */
    private function resolveClassConstant(ClassConstFetch $expr, int $line): string
    {
        if (! $expr->class instanceof Name) {
            throw new Refusal('dynamic class constant', $line);
        }

        $alias = $expr->class->getFirst();
        $written = $expr->class->toString();
        $fqcn = $this->context->useMap[$alias] ?? $written;

        // An unimported `Foo::class` in a namespaced file is `<namespace>\Foo`, and PHP resolves it that way
        // whether or not the rule wrote an import. Taking the short name instead emits a comparison against a
        // name no ancestor has, so the rule loads, runs and matches nothing — the failure mode that looks like
        // coverage. Only for a name that is neither imported nor already qualified.
        // Asked of the import map rather than by comparing the two strings. A root-namespace import maps an
        // alias to itself -- `use Attribute;` gives `Attribute => Attribute` -- so the value comparison read
        // it as unimported and prefixed the rule's own namespace, emitting
        // `Symplify\PHPStanRules\Rules\Attribute` for PHP's own `Attribute`. The rule then loaded, ran and
        // matched nothing, which is the failure this branch exists to prevent, produced by the branch itself.
        if (! isset($this->context->useMap[$alias])
            && $this->context->ruleNamespace !== null
            && ! $expr->class instanceof FullyQualified
            && ! str_contains($written, '\\')
            && ! in_array($written, ['self', 'static', 'parent'], true)
        ) {
            $fqcn = $this->context->ruleNamespace . '\\' . $written;
        }

        $constant = $this->memberName($expr->name, $expr->getStartLine());

        if ($constant === 'class') {
            return $fqcn;
        }

        // The rule's own constants first: they are already parsed, and `self::` cannot be found by
        // searching the vendor tree for a file named after the class.
        if (in_array($expr->class->toString(), ['self', 'static'], true)) {
            if (isset($this->context->constants[$constant])) {
                return $this->context->constants[$constant];
            }

            throw new Refusal("self::{$constant} is not a string constant of this rule", $line);
        }

        $short = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
        foreach ($this->context->index->paths($short, $this->file) as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match('/const\s+(?:string\s+)?' . preg_quote($constant, '/') . '\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $source, $match) === 1) {
                // The capture is source text, so it still carries whatever the author escaped. PHP's single
                // quotes undo exactly two sequences, and a class name is where it shows: `'A\\B'` and `'A\B'`
                // are the same value, and returning the first as written emitted a name with a doubled
                // separator — which resolves to nothing and makes the rule silent rather than wrong.
                return str_replace(['\\\\', "\\'"], ['\\', "'"], $match[1]);
            }
        }

        throw new Refusal("could not resolve {$alias}::{$constant}", $line);
    }

    /** Node kinds that carry an argument list. */
    private const array ARGUMENT_LIST_KINDS = ['MethodCall', 'FunctionCall', 'StaticMethodCall', 'NullSafeMethodCall', 'Instantiation'];

    /** Hook kinds that are a class-like declaration, where the node under analysis is always named. */
    private const array CLASS_LIKE_HOOK_KINDS = ['Class', 'Interface', 'Trait', 'Enum'];

    /**
     * The Mago node kind each php-parser class-like declaration stands for.
     *
     * The values are `NodeKind`'s own, read from the enum: `Class_` is spelled `Class` there because `::class`
     * would otherwise yield the class-name string, and the other three are declared bare.
     *
     * @var array<class-string, string>
     */
    private const array DECLARATION_KINDS = [
        \PhpParser\Node\Stmt\Class_::class => 'Class',
        \PhpParser\Node\Stmt\Interface_::class => 'Interface',
        \PhpParser\Node\Stmt\Trait_::class => 'Trait',
        \PhpParser\Node\Stmt\Enum_::class => 'Enum',
    ];

    /**
     * Hook kinds whose scope always carries a class reflection, so `=== null` on one cannot hold.
     *
     * A class-like hook fires on the class itself; a `Method` hook fires on a class member, which by
     * definition has one. `Function`, `Closure` and `ArrowFunction` are absent because those genuinely may sit
     * outside a class, and folding the check there would drop a guard the rule needs.
     */
    private const array HOOK_KINDS_ALWAYS_IN_A_CLASS = ['Class', 'Interface', 'Trait', 'Enum', 'Method'];

    /** Node predicates only the PHP runtime carries; the Rust backends have no counterpart. */
    private const array PHP_ONLY_PREDICATES = ['is_dir_constant', 'is_literal_string'];

    /** Node predicates that answer from the node's kind, and so have to look it up. */
    private const array CONTEXT_PREDICATES = ['is_literal_string'];
}
