<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\ParserFactory;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Transpiler
{
    /** Survey mode: keep walking past gaps that are not about the body, to find the body gaps. */
    public static bool $survey = false;

    /** Which tier to emit for: 'analyzer' (a plugin) or 'linter' (a lint rule). */
    public static string $target = 'analyzer';

    /** @var list<Stm> emitted statements, in source order: guards and bindings interleave */
    private array $lines = [];

    /** Renders {@see $lines} into the target language. */
    private readonly Backend $backend;

    /** Whether the body asks for the receiver's inferred type, which the PHP target must request. */
    private bool $usesReceiverType = false;

    /** The Rust expression producing the reported message, from the report site. */
    private ?string $message = null;

    /** PHPStan's `->identifier(..)`, which becomes the issue's code so the two tools agree on it. */
    private ?string $identifier = null;

    /** @var array<string, string> the rule's own string constants, by name */
    private array $constants = [];

    /** @var array<string, list<string>> the rule's own list-of-string constants, by name */
    private array $arrayConstants = [];

    /** The Mago node kind the hook's `node` currently refers to, for FIELDS lookup. */
    private string $nodeKind = '';

    /** @var array<string, array{rust: string, kind: string, fields?: array}> PHP local -> descriptor */
    private array $locals = [];

    /** @var array<string, string> alias -> fully qualified class name, from the rule's `use` list */
    private array $useMap = [];

    private int $bindCounter = 0;

    /**
     * Set when the rule asks what the scope knew *before* this node — `hasVariableType()` and
     * friends. Such a rule has to run on the pre hook, or it sees the state the node just created.
     */
    private bool $readsPriorScope = false;

    /** True while translating a loop body, so `continue` and inline reports are legal. */
    private bool $inLoop = false;

    /** Set once a report has been emitted inside the body; suppresses the trailing one. */
    private bool $reportedInline = false;

    /** Current emission indentation, which a loop body increases. */
    private int $indent = 8;

    /** What the report's annotation points at; a loop reports per item, not per node. */
    private string $reportSpan = 'node.span()';

    /** Set when the rule narrows `getOriginalNode()` to `Class_`, which the class hook guarantees. */
    private bool $narrowedToClass = false;

    /**
     * Where the enclosing class comes from in the current hook.
     *
     * A declaration hook fires *before* the analyser enters the class, so the block context has no
     * class yet — but the hook is handed the class's metadata. A call hook is the other way round.
     */
    private string $classFrom = 'scope';

    /** Set when the emitted body reads the metadata parameter, so it can be named rather than `_`. */
    private bool $usesMetadata = false;

    /** Set when the class being translated is a Collector rather than a Rule. */
    private bool $isCollector = false;

    /** The collector's own name, used as the key in the cross-file store. */
    private string $collectorName = '';

    /** Set once a collector has emitted its push, so no report is appended. */
    private bool $collected = false;

    /** @var array<string, list<string>> basename -> paths, for resolving `Foo::BAR` literals */
    private static array $classFiles = [];

    /** @var array<string, true> vendor roots already indexed into {@see} */
    private static array $indexedRoots = [];

    /** @var array<string, array{class: Class_, uses: array<string, string>}> parsed helper classes */
    private static array $parsedClasses = [];

    /** The class whose `self::` constants are currently in scope, i.e. the one being inlined. */
    private ?Class_ $currentClass = null;

    /** Guards against a helper that calls itself. */
    private int $inlineDepth = 0;

    private const int INLINE_DEPTH_LIMIT = 4;

    public function __construct(private readonly string $file)
    {
        $this->backend = self::$target === 'php' ? new PhpBackend() : new RustBackend();
    }

    public function transpile(): array
    {
        $code = (string) file_get_contents($this->file);
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        if ($ast === null) {
            throw new Refusal('could not parse');
        }

        $this->collectUses($ast);
        $class = $this->findClass($ast);
        $className = (string) $class->name;

        $this->currentClass = $class;
        $this->collectConstants($class);
        $this->isCollector = $this->implementsCollector($class);
        $this->collectorName = $className;
        $nodeType = $this->findNodeType($class);
        if (! isset(Vocabulary::HOOKS[$nodeType])) {
            if (! self::$survey) {
                throw new Refusal("no hook mapping for node type $nodeType");
            }

            // Survey: assume the hook exists, to see what the body would need.
            $short = substr($nodeType, (int) strrpos('\\' . $nodeType, '\\'));

            $hook = ['trait' => 'SurveyHook', 'method' => 'survey', 'node' => $short, 'kind' => $short];
            $this->nodeKind = $short;
            $processNode = $this->findMethod($class, 'processNode');
            foreach ($processNode->stmts ?? [] as $stmt) {
                $this->translateStatement($stmt);
            }

            return ['name' => $className, 'trait' => $hook['trait'], 'node' => $short, 'rust' => ''];
        }

        $hook = Vocabulary::HOOKS[$nodeType];
        $this->nodeKind = $hook['kind'];
        $this->classFrom = $hook['classFrom'] ?? 'scope';

        $processNode = $this->findMethod($class, 'processNode');
        foreach ($processNode->stmts ?? [] as $stmt) {
            $this->translateStatement($stmt);
        }

        $rust = match (self::$target) {
            'php' => $this->emitPhp($className, $hook),
            'linter' => $this->emitLint($className, $hook),
            default => $this->emit($className, $hook),
        };

        return [
            'name' => $className,
            'trait' => $hook['trait'],
            'node' => $hook['node'],
            'kind' => $hook['kind'],
            'module' => $this->snake($className),
            'rust' => $rust,
            'identifier' => $this->identifier,
            'messages' => array_values($this->constants === [] ? [] : array_filter(
                $this->constants,
                static fn (string $name): bool => str_contains($name, 'MESSAGE'),
                ARRAY_FILTER_USE_KEY,
            )),
        ];
    }

    /** Whether this class is a PHPStan Collector, i.e. the per-file half of a cross-file rule. */
    private function implementsCollector(Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            if ($this->resolveClassName($interface) === Collector::class) {
                return true;
            }
        }

        return false;
    }

    /** A written class name, as the FQCN the rule file's `use` list makes it mean. */
    private function resolveClassName(Name $name): string
    {
        $first = $name->getFirst();
        if (isset($this->useMap[$first])) {
            $rest = array_slice($name->getParts(), 1);

            return $this->useMap[$first] . ($rest === [] ? '' : '\\' . implode('\\', $rest));
        }

        return $name->toString();
    }

    /**
     * @param Stmt[] $ast
     */
    private function collectUses(array $ast): void
    {
        $walk = function (array $stmts) use (&$walk): void {
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $walk($stmt->stmts);

                    continue;
                }

                if ($stmt instanceof Use_) {
                    foreach ($stmt->uses as $use) {
                        $alias = $use->alias !== null ? (string) $use->alias : $use->name->getLast();
                        $this->useMap[$alias] = $use->name->toString();
                    }
                }
            }
        };
        $walk($ast);
    }

    /**
     * @param Stmt[] $ast
     */
    private function findClass(array $ast): Class_
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Class_) {
                        return $inner;
                    }
                }
            }

            if ($stmt instanceof Class_) {
                return $stmt;
            }
        }

        throw new Refusal('no class found');
    }

    private function findMethod(Class_ $class, string $name): ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ((string) $method->name === $name) {
                return $method;
            }
        }

        throw new Refusal("no $name() method");
    }

    /**
     * Every string constant the rule declares, by name.
     *
     * Not just `ERROR_MESSAGE`: a rule may declare several and pick between them at the report site
     * (`NoMissnamedDocTagRule` has one each for methods, properties and constants), which is why the
     * message cannot be hoisted to the class level.
     */
    private function collectConstants(Class_ $class): void
    {
        foreach ($class->getConstants() as $const) {
            foreach ($const->consts as $c) {
                if ($c->value instanceof String_) {
                    $this->constants[(string) $c->name] = $c->value->value;

                    continue;
                }

                if ($c->value instanceof Array_) {
                    $values = [];
                    foreach ($c->value->items as $item) {
                        if ($item === null) {
                            continue 2;
                        }

                        try {
                            $values[] = $this->rawStringLiteral($item->value, $c->getLine());
                        } catch (Refusal) {
                            continue 2; // not resolvable to strings; leave the constant unresolved
                        }
                    }

                    $this->arrayConstants[(string) $c->name] = $values;
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
            $local = $this->locals[$expr->name] ?? null;
            if (($local['kind'] ?? null) === 'message') {
                return $local['rust'];
            }

            throw new Refusal("\${$expr->name} is not a message built in this rule", $expr->getLine());
        }

        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'sprintf'
        ) {
            return $this->translateSprintf($expr);
        }

        throw new Refusal('message expression outside the vocabulary: ' . $expr->getType(), $expr->getLine());
    }

    /** `sprintf(<format>, <args>)` -> `format!("...", ...)`, with PHP's specifiers rewritten. */
    private function translateSprintf(FuncCall $expr): string
    {
        $args = $expr->getArgs();
        if ($args === []) {
            throw new Refusal('sprintf() without a format', $expr->getLine());
        }

        $formatArg = $args[0]->value;
        $format = match (true) {
            $formatArg instanceof String_ => $formatArg->value,
            $formatArg instanceof ClassConstFetch => $this->selfConstant($formatArg),
            default => throw new Refusal('sprintf() format is not a literal or a class constant', $expr->getLine()),
        };

        [$rustFormat, $expected] = $this->rustFormat($format, $expr->getLine());
        $values = array_slice($args, 1);
        if (count($values) !== $expected) {
            throw new Refusal(
                sprintf('sprintf() has %d placeholders but %d arguments', $expected, count($values)),
                $expr->getLine(),
            );
        }

        $translated = [];
        foreach ($values as $value) {
            $translated[] = $this->stringValue($value->value, $expr->getLine());
        }

        if (self::$target === 'php') {
            // PHP keeps its own format string, so the placeholders do not need translating; only the
            // values do, and those have already been rendered for this target.
            return 'sprintf(' . $this->backend->bytes($format)
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
     * A list of string literals, written inline or as one of the rule's own constants.
     *
     * @return list<string>
     */
    private function stringList(Expr $expr, int $line): array
    {
        if ($expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && in_array($expr->class->toString(), ['self', 'static'], true)
            && isset($this->arrayConstants[(string) $expr->name])
        ) {
            return $this->arrayConstants[(string) $expr->name];
        }

        if ($expr instanceof Array_) {
            $values = [];
            foreach ($expr->items as $item) {
                if ($item === null || ! $item->value instanceof String_) {
                    throw new Refusal('list contains something other than a string literal', $line);
                }

                $values[] = $item->value->value;
            }

            return $values;
        }

        throw new Refusal('not a resolvable list of strings', $line);
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
     * @param list<Node\Arg> $args
     */
    private function inlineMethod(Class_ $class, string $methodName, array $args, int $line, ?array $uses = null): string
    {
        if ($this->inlineDepth >= self::INLINE_DEPTH_LIMIT) {
            throw new Refusal("inlining {$methodName}() nests deeper than " . self::INLINE_DEPTH_LIMIT, $line);
        }

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
        $savedLocals = $this->locals;
        $savedConstants = $this->constants;
        $savedArrayConstants = $this->arrayConstants;
        $savedClass = $this->currentClass;
        $savedUses = $this->useMap;

        $bound = [];
        foreach ($method->params as $index => $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                throw new Refusal("{$methodName}() has a parameter that is not a simple variable", $line);
            }

            if (! isset($args[$index])) {
                throw new Refusal("{$methodName}() is called with fewer arguments than it declares", $line);
            }

            $argument = $args[$index]->value;
            // `$scope` is the analysis context on both sides, so it needs no descriptor.
            $bound[$param->var->name] = $argument instanceof Variable && $argument->name === 'scope'
                ? ['rust' => 'context', 'kind' => 'scope']
                : $this->resolve($argument, $line);
        }

        $this->locals = $bound;
        $this->constants = [];
        $this->arrayConstants = [];
        $this->currentClass = $class;
        if ($uses !== null) {
            $this->useMap = $uses;
        }

        $this->collectConstants($class);
        ++$this->inlineDepth;

        try {
            return $this->translateMethodAsPredicate($method, $line);
        } finally {
            --$this->inlineDepth;
            $this->locals = $savedLocals;
            $this->constants = $savedConstants;
            $this->arrayConstants = $savedArrayConstants;
            $this->currentClass = $savedClass;
            $this->useMap = $savedUses;
        }
    }

    /** The accepted helper shapes, as one Rust expression. */
    private function translateMethodAsPredicate(ClassMethod $method, int $line): string
    {
        /** @var list<array{string, string}> condition and the value returned when it holds */
        $guards = [];
        $final = null;

        foreach ($method->stmts ?? [] as $statement) {
            if ($final !== null) {
                throw new Refusal('statements after the return of an inlined helper', $statement->getLine());
            }

            if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                $this->bindLocal($statement->expr, $statement->getLine());

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
                    throw new Refusal('early return from a helper that is not a boolean literal', $statement->getLine());
                }

                $guards[] = [
                    $this->translateCondition($statement->cond),
                    strtolower($returned->name->toString()) === 'true' ? 'true' : 'false',
                ];

                continue;
            }

            if ($statement instanceof Return_ && $statement->expr instanceof Expr) {
                $final = $this->translateCondition($statement->expr);

                continue;
            }

            throw new Refusal('statement in an inlined helper outside the vocabulary: ' . $statement->getType(), $statement->getLine());
        }

        if ($final === null) {
            throw new Refusal('inlined helper does not return a value', $line);
        }

        $expression = $final;
        foreach (array_reverse($guards) as [$condition, $value]) {
            $expression = $this->backend->conditional($condition, $value, $expression);
        }

        return '(' . $expression . ')';
    }

    /**
     * The parsed class behind a name, with its own import list.
     *
     * The import list matters: a helper resolves `ClassReflection` or `SymfonyClass::COMMAND` through
     * the `use` statements of *its* file, not the calling rule's. Sharing the caller's map silently
     * resolved names to the wrong thing, or failed to resolve them at all.
     *
     * @return array{class: Class_, uses: array<string, string>}|null
     */
    private function findClassByName(string $shortName): ?array
    {
        if (isset(self::$parsedClasses[$shortName])) {
            return self::$parsedClasses[$shortName];
        }

        foreach ($this->classFiles($this->file)[$shortName] ?? [] as $path) {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($path));
            if ($ast === null) {
                continue;
            }

            try {
                $class = $this->findClass($ast);
            } catch (Refusal) {
                continue;
            }

            if ((string) $class->name !== $shortName) {
                continue;
            }

            $savedUses = $this->useMap;
            $this->useMap = [];
            $this->collectUses($ast);
            $uses = $this->useMap;
            $this->useMap = $savedUses;

            self::$parsedClasses[$shortName] = ['class' => $class, 'uses' => $uses];

            return self::$parsedClasses[$shortName];
        }

        return null;
    }

    /** A `self::CONST` / `static::CONST` string constant declared by this rule. */
    private function selfConstant(ClassConstFetch $expr): string
    {
        $class = $expr->class instanceof Name ? $expr->class->toString() : '';
        $name = (string) $expr->name;
        if (in_array($class, ['self', 'static'], true) && isset($this->constants[$name])) {
            return $this->constants[$name];
        }

        // A constant from elsewhere: resolvable the same way argument literals are.
        return $this->rawStringLiteral($expr, $expr->getLine());
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
        if (self::$target === 'php') {
            return '[' . implode(', ', array_map(
                fn (string $option): string => $this->backend->bytes(str_replace('\\', '\\\\', $option)),
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
            && ($this->locals[$expr->name]['kind'] ?? null) === 'bytes'
        ) {
            return $this->operand($this->locals[$expr->name]);
        }

        return $this->backend->bytes(str_replace('\\', '\\\\', $this->rawStringLiteral($expr, $line)));
    }

    /** A Rust expression producing the string PHP would interpolate for this argument. */
    private function stringValue(Expr $expr, int $line): string
    {
        if ($expr instanceof Expr\Cast\String_) {
            return $this->stringValue($expr->expr, $line);
        }

        if ($expr instanceof String_) {
            return self::$target === 'php'
                ? $this->backend->bytes($expr->value)
                : '"' . addcslashes($expr->value, '"\\') . '"';
        }

        if ($expr instanceof ClassConstFetch) {
            $raw = $this->rawStringLiteral($expr, $line);

            return self::$target === 'php' ? $this->backend->bytes($raw) : '"' . addcslashes($raw, '"\\') . '"';
        }

        if ($expr instanceof MethodCall
            && in_array((string) $expr->name, ['getLine', 'getStartLine'], true)
            && $expr->var instanceof Variable
            && $expr->var->name === 'node'
        ) {
            return 'support::line_text(context, node.span())';
        }

        $subject = $this->resolve($expr, $line);

        if (self::$target === 'php') {
            // PHP has no byte-slice-to-string step, so a value that Rust must convert is already a
            // string here; what differs is which helper produces it.
            return match ($subject['kind']) {
                'hint-option', 'hint' => $this->backend->call('hint_name', ['$context', $this->operand($subject)]),
                'collected-value', 'bytes', 'class-name' => $this->operand($subject),
                'local-name', 'name-selector', 'name-expr' => $this->backend->call('text_of', [$this->operand($subject)]),
                'extends' => $this->backend->call('extends_text', ['$context', '$node']),
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

    private function findNodeType(Class_ $class): string
    {
        $method = $this->findMethod($class, 'getNodeType');
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Return_
                && $stmt->expr instanceof ClassConstFetch
                && $stmt->expr->class instanceof Name
            ) {
                return $this->resolveClassName($stmt->expr->class);
            }
        }

        throw new Refusal('getNodeType() is not a simple `return X::class`');
    }

    // -----------------------------------------------------------------------
    // Statements
    // -----------------------------------------------------------------------

    private function translateStatement(Stmt $stmt): void
    {
        // Guard: if (COND) { return []; }  or, inside a loop, if (COND) { continue; }
        if ($stmt instanceof If_) {
            if ($stmt->elseifs !== [] || count($stmt->stmts) !== 1
                || ($stmt->else instanceof Else_ && ! $this->isFlagAssignment($stmt->stmts[0]))
            ) {
                throw new Refusal('if statement that is not a single-statement guard', $stmt->getLine());
            }

            $only = $stmt->stmts[0];

            // if (COND) { $flag = ..; } [else { $flag = ..; }]  — not a guard, a branch.
            if ($this->isFlagAssignment($only)) {
                $this->translateFlagBranch($stmt);

                return;
            }

            // `return []` leaves the whole rule; `continue` only ends this iteration. Which one it
            // is comes from the guard's own body, not from whether we happen to be in a loop.
            if ($this->isReturnEmptyArray($stmt->stmts) || ($this->isCollector && $this->isReturnNull($stmt->stmts))) {
                $exit = $this->backend->bail();
            } elseif ($only instanceof Continue_ && ! $only->num instanceof Expr) {
                if (! $this->inLoop) {
                    throw new Refusal('continue outside a loop', $stmt->getLine());
                }

                $exit = 'continue;';
            } else {
                throw new Refusal('guard body is neither `return []` nor `continue`', $stmt->getLine());
            }

            $this->translateGuard($stmt->cond, $exit);

            return;
        }

        // foreach (<iterable> as $item) { ... }
        if ($stmt instanceof Foreach_) {
            $this->translateForeach($stmt);

            return;
        }

        // `continue;` inside a loop
        if ($stmt instanceof Continue_) {
            if (! $this->inLoop) {
                throw new Refusal('continue outside a loop', $stmt->getLine());
            }

            $this->lines[] = new Stm('continue', [], $this->indent);

            return;
        }

        // A collector's terminal statement hands back the datum to record.
        if ($this->isCollector && $stmt instanceof Return_) {
            $this->translateCollect($stmt);

            return;
        }

        // Terminal: return [ ...error... ];  or  $x = RuleErrorBuilder...; return [$x];
        if ($stmt instanceof Return_) {
            if ($stmt->expr instanceof Array_) {
                foreach ($stmt->expr->items as $item) {
                    if ($item !== null && $this->isRuleErrorBuilder($item->value)) {
                        $this->takeMessage($item->value);
                        if ($this->inLoop) {
                            // Reporting from inside the loop and returning: emit it here, because
                            // the trailing report would run after the loop has finished.
                            $this->lines[] = $this->reportNode();
                            $this->lines[] = new Stm('bail', [], $this->indent);
                            $this->reportedInline = true;
                        }
                    }
                }
            }

            return; // the emitted rule reports once all guards pass
        }

        if ($stmt instanceof Expression && $stmt->expr instanceof Assign) {
            $value = $stmt->expr->expr;

            // $ruleErrors[] = RuleErrorBuilder::...  — report here and keep looping
            if ($stmt->expr->var instanceof ArrayDimFetch
                && ! $stmt->expr->var->dim instanceof Expr
                && $this->isRuleErrorBuilder($value)
            ) {
                $this->takeMessage($value);
                $this->lines[] = $this->reportNode();
                $this->reportedInline = true;

                return;
            }

            if ($this->isRuleErrorBuilder($value)) {
                $this->takeMessage($value);

                return;
            }

            $this->bindLocal($stmt->expr, $stmt->getLine());

            return;
        }

        throw new Refusal('statement outside the vocabulary: ' . $stmt->getType(), $stmt->getLine());
    }

    /**
     * A guard either refines a local (emitting a binding) or tests it (emitting an `if ... return`).
     */
    private function translateGuard(Expr $cond, ?string $exit = null): void
    {
        // Refining an instanceof guard into a narrowing binding is only sound when the guard's exit
        // is the plain bail; a `continue` inside a loop must stay a guard. Compare against the
        // backend's bail rather than null, because callers pass it explicitly.
        $exit ??= $this->backend->bail();
        if ($exit === $this->backend->bail() && $this->tryRefine($cond)) {
            return;
        }

        $bail = $this->stripOuterParentheses($this->translateCondition($cond));
        if ($bail === 'false') {
            // The PHP guard cannot hold in Mago's model; the comment records why.
            $this->lines[] = new Stm('comment', ['text' => "guard dropped: cannot hold in Mago's model (see support.rs)"], $this->indent);

            return;
        }

        $this->lines[] = new Stm('guard', ['condition' => $bail, 'exit' => $exit], $this->indent);
    }

    /** `! $x instanceof K { return []; }` -> a narrowing let-binding, when K has a refinement. */
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

        $subject = $this->resolve($instanceof->expr, $instanceof->getLine());
        if ($subject['kind'] !== 'expr') {
            return false;
        }

        $bind = $this->freshName($instanceof->expr, $wanted);
        $adapter = $refinement['adapter'];
        $this->lines[] = new Stm('bind-adapter', ['bind' => $bind, 'adapter' => $adapter, 'subject' => $this->operand($subject)], $this->indent);

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
     * @param array<string, string[]> $fields
     */
    private function rememberRefined(Expr $subject, array $fields): void
    {
        $key = $this->exprKey($subject);
        $this->refinements[$key] = $fields;

        // A local that aliases this expression inherits the refinement.
        foreach ($this->locals as $name => $descriptor) {
            if (($descriptor['key'] ?? null) === $key) {
                $this->locals[$name]['fields'] = $fields;
            }
        }
    }

    /** @var array<string, array> PHP expression key -> refined field map */
    private array $refinements = [];

    private function exprKey(Expr $expr): string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            $local = $this->locals[$expr->name] ?? null;

            return $local['key'] ?? ('$' . $expr->name);
        }

        if ($expr instanceof PropertyFetch && $expr->var instanceof Variable) {
            return $this->exprKey($expr->var) . '->' . $expr->name;
        }

        return spl_object_hash($expr);
    }

    private function freshName(Expr $subject, string $kind): string
    {
        $base = $subject instanceof PropertyFetch ? (string) $subject->name
            : ($subject instanceof Variable && is_string($subject->name) ? $subject->name : 'value');

        $short = substr($kind, (int) strrpos('\\' . $kind, '\\'));
        ++$this->bindCounter;

        return $this->snake($base) . '_' . $this->snake($short) . ($this->bindCounter > 1 ? (string) $this->bindCounter : '');
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

    private function isFlagAssignment(Stmt $stmt): bool
    {
        return $stmt instanceof Expression
            && $stmt->expr instanceof Assign
            && $stmt->expr->var instanceof Variable
            && is_string($stmt->expr->var->name)
            && ($this->locals[$stmt->expr->var->name]['kind'] ?? null) === 'bool'
            && $this->isBooleanLiteral($stmt->expr->expr) !== null;
    }

    /** `if (COND) { $flag = ..; } else { $other = ..; }` — a branch, not a guard. */
    private function translateFlagBranch(If_ $stmt): void
    {
        if ($stmt->else instanceof Else_
            && (count($stmt->else->stmts) !== 1 || ! $this->isFlagAssignment($stmt->else->stmts[0]))
        ) {
            throw new Refusal('else branch that does more than set a flag', $stmt->getLine());
        }

        $condition = $this->translateCondition($stmt->cond);

        $this->lines[] = new Stm('if-open', ['condition' => $condition], $this->indent);
        $this->indent += 4;
        $this->translateStatement($stmt->stmts[0]);
        $this->indent -= 4;

        if ($stmt->else instanceof Else_) {
            $this->lines[] = new Stm('else', [], $this->indent);
            $this->indent += 4;
            $this->translateStatement($stmt->else->stmts[0]);
            $this->indent -= 4;
        }

        $this->lines[] = new Stm('block-close', [], $this->indent);
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
            throw new Refusal('foreach by reference', $stmt->getLine());
        }

        if ($stmt->keyVar instanceof Expr && ! $this->isCollectedSubject($stmt->expr)) {
            // The only keyed iteration modelled is over collected data, whose key is the file path.
            throw new Refusal('foreach with a key', $stmt->getLine());
        }

        if ($stmt->valueVar instanceof Array_ || $stmt->valueVar instanceof List_) {
            $this->translateDestructuringForeach($stmt);

            return;
        }

        if (! $stmt->valueVar instanceof Variable || ! is_string($stmt->valueVar->name)) {
            throw new Refusal('foreach into something other than a simple variable', $stmt->getLine());
        }

        $subject = $this->resolve($stmt->expr, $stmt->getLine());

        // PHPStan hands collected data back as file => list-of-data, so rules walk it with two nested
        // loops. Mago's store is flat and each datum carries its own position, so the outer loop has
        // nothing to iterate: it collapses, and only the inner one is emitted.
        if ($subject['kind'] === 'collected' && $stmt->keyVar instanceof Expr) {
            $savedLocals = $this->locals;
            $this->locals[$stmt->valueVar->name] = ['rust' => $subject['rust'], 'kind' => 'collected'];
            try {
                foreach ($stmt->stmts as $inner) {
                    $this->translateStatement($inner);
                }
            } finally {
                $this->locals = $savedLocals;
            }

            return;
        }

        if (! isset(Vocabulary::ITERABLES[$subject['kind']])) {
            throw new Refusal("no iteration mapped for a {$subject['kind']}", $stmt->getLine());
        }

        $iterable = Vocabulary::ITERABLES[$subject['kind']];
        $variable = $this->snake($stmt->valueVar->name);

        $savedLocals = $this->locals;
        $savedLoop = $this->inLoop;
        $this->locals[$stmt->valueVar->name] = ['rust' => $variable, 'kind' => $iterable['item']];
        if (self::$target === 'php') {
            $this->locals[$stmt->valueVar->name]['php'] = '$' . $variable;
        }

        $this->inLoop = true;

        $this->lines[] = new Stm('foreach-open', [
            'variable' => $variable,
            // Rust iterates with `.iter()`; PHP's `foreach` takes the list directly, so a descriptor
            // kind may carry a second template for this target.
            'iterable' => self::$target === 'php'
                ? str_replace('{rust}', $this->operand($subject), $iterable['phpIter'] ?? '{rust}')
                : str_replace('{rust}', $subject['rust'], $iterable['iter']),
        ], $this->indent);
        $this->indent += 4;

        try {
            foreach ($stmt->stmts as $inner) {
                $this->translateStatement($inner);
            }
        } finally {
            $this->indent -= 4;
            $this->inLoop = $savedLoop;
            $this->locals = $savedLocals;
        }

        $this->lines[] = new Stm('block-close', [], $this->indent);
    }

    private function isCollectedSubject(Expr $expr): bool
    {
        try {
            return $this->resolve($expr, $expr->getLine())['kind'] === 'collected';
        } catch (Refusal) {
            return false;
        }
    }

    /** `foreach ($collected as [$a, $b, $c])` — the datum's values, by position. */
    private function translateDestructuringForeach(Foreach_ $stmt): void
    {
        $subject = $this->resolve($stmt->expr, $stmt->getLine());
        if ($subject['kind'] !== 'collected') {
            throw new Refusal('destructuring foreach over something other than collected data', $stmt->getLine());
        }

        $savedLocals = $this->locals;
        $savedLoop = $this->inLoop;
        $this->inLoop = true;

        $pad = str_repeat(' ', $this->indent);
        $this->lines[] = new Stm('for-open', ['subject' => $subject['rust']], $this->indent);
        $this->indent += 4;

        $bindings = [];
        foreach ($stmt->valueVar->items as $index => $item) {
            if ($item === null || ! $item->value instanceof Variable || ! is_string($item->value->name)) {
                throw new Refusal('destructuring into something other than simple variables', $stmt->getLine());
            }

            $name = $this->snake($item->value->name);
            $bindings[count($this->lines)] = $name;
            $this->lines[] = new Stm('collected-value', ['name' => $name, 'index' => $index], $this->indent);
            $this->locals[$item->value->name] = ['rust' => $name, 'kind' => 'collected-value'];
        }

        $this->lines[] = new Stm('blank');
        $bodyStart = count($this->lines);

        $savedSpan = $this->reportSpan;
        $this->reportSpan = 'item.span';

        try {
            foreach ($stmt->stmts as $inner) {
                $this->translateStatement($inner);
            }
        } finally {
            $this->indent -= 4;
            $this->inLoop = $savedLoop;
            $this->locals = $savedLocals;
            $this->reportSpan = $savedSpan;
        }

        // A datum the body never reads still has to be destructured to keep the positions lined up
        // with the collector's tuple, but Rust warns about it unless the name says so.
        $body = $this->renderRange($bodyStart);
        foreach ($bindings as $index => $name) {
            if (! str_contains($body, $name)) {
                $this->lines[$index]->unused = true;
            }
        }

        $this->lines[] = "{$pad}}\n\n";
    }

    /** `return [$a, $b];` in a collector becomes a push into the cross-file store. */
    private function translateCollect(Return_ $stmt): void
    {
        if (! $stmt->expr instanceof Array_) {
            throw new Refusal('collector returns something other than a list of values', $stmt->getLine());
        }

        $values = [];
        foreach ($stmt->expr->items as $item) {
            if ($item === null) {
                throw new Refusal('collector returns a list with a hole', $stmt->getLine());
            }

            $values[] = $this->stringValue($item->value, $stmt->getLine()) . '.to_string()';
        }

        $pad = str_repeat(' ', $this->indent);
        $this->lines[] = new Stm('raw', ['text' => $pad . "support::collect(\"{$this->collectorName}\", context, node.span(), vec![\n"
            . $pad . '    ' . implode(",\n{$pad}    ", $values) . ",\n"
            . $pad . "]);\n"]);
        $this->collected = true;
    }

    /** The report, as a statement at the current indentation. */
    /**
     * A resolved expression descriptor, rendered for the current target.
     *
     * Descriptors carry Rust in `rust` and, where the mapping is known, PHP in `php`. The PHP SDK's
     * `Node` exposes only id, kind, span and parent, so a Rust field access like `node.class` has no
     * PHP counterpart and must become a navigation helper; a descriptor without a `php` key means
     * that recipe has not been written, and refusing is the only honest answer.
     *
     * @param array{rust: string, kind: string, php?: string} $descriptor
     */
    private function operand(array $descriptor): string
    {
        if (self::$target !== 'php') {
            return $descriptor['rust'];
        }

        if (! isset($descriptor['php'])) {
            throw new Refusal(sprintf(
                'no PHP navigation for %s (kind %s) on a %s node',
                $descriptor['rust'],
                $descriptor['kind'],
                $this->nodeKind === '' ? 'unknown' : $this->nodeKind,
            ));
        }

        return $descriptor['php'];
    }

    /** @return string the emitted statements, rendered */
    private function renderAll(): string
    {
        return $this->renderRange(0);
    }

    private function renderRange(int $from): string
    {
        $out = '';
        foreach (array_slice($this->lines, $from) as $statement) {
            $out .= $this->backend->render($statement);
        }

        return $out;
    }

    /**
     * The report as an emitted statement.
     *
     * Rust keeps the finished text, so its output stays byte-identical; PHP carries the pieces and lets
     * the backend format them, since the two languages do not agree on how a report is written.
     */
    private function reportNode(): Stm
    {
        if (self::$target !== 'php') {
            return new Stm('raw', ['text' => $this->reportStatement()]);
        }

        if ($this->message === null) {
            throw new Refusal('reporting before the message is known');
        }

        $literal = str_starts_with($this->message, '"') && str_ends_with($this->message, '"');

        return new Stm('report', [
            'message' => $literal ? $this->backend->bytes(substr($this->message, 1, -1)) : $this->message,
            // PHPStan's own identifier is the code, so a finding is labelled the same by both tools.
            'code' => $this->identifier ?? throw new Refusal('no identifier to use as the reported code'),
        ], $this->indent);
    }

    private function reportStatement(): string
    {
        if ($this->message === null) {
            throw new Refusal('reporting before the message is known');
        }

        $pad = str_repeat(' ', $this->indent);

        // The code is PHPStan's own identifier for the rule, so the two tools label the finding the
        // same way. `IssueCode::InvalidArgument` stays as the fallback the analyzer requires.
        $code = $this->identifier === null
            ? ''
            : "\n" . $pad . '        .with_code("' . addcslashes($this->identifier, '"\\') . '")';

        return $pad . "context.report(\n"
            . $pad . "    IssueCode::InvalidArgument,\n"
            . $pad . "    Issue::error({$this->message}){$code}\n"
            . $pad . "        .with_annotation(Annotation::primary({$this->reportSpan}).with_message(\"here\")),\n"
            . $pad . ");\n";
    }

    /** Pulls the message and the identifier out of a `RuleErrorBuilder::message(..)->..->build()` chain. */
    private function takeMessage(Expr $chain): void
    {
        while ($chain instanceof MethodCall) {
            if ((string) $chain->name === 'identifier' && count($chain->getArgs()) === 1) {
                $identifier = $this->rawStringLiteral($chain->getArgs()[0]->value, $chain->getLine());
                if ($this->identifier !== null && $this->identifier !== $identifier) {
                    throw new Refusal('more than one distinct identifier in one rule', $chain->getLine());
                }

                $this->identifier = $identifier;
            }

            $chain = $chain->var;
        }

        if (! $chain instanceof StaticCall || (string) $chain->name !== 'message') {
            throw new Refusal('error builder chain does not start with message()', $chain->getLine());
        }

        $args = $chain->getArgs();
        if (count($args) !== 1) {
            throw new Refusal('message() with more than one argument', $chain->getLine());
        }

        $message = $this->translateMessageExpression($args[0]->value);
        if ($this->message !== null && $this->message !== $message) {
            throw new Refusal('more than one distinct message in one rule', $chain->getLine());
        }

        $this->message = $message;
    }

    private function isRuleErrorBuilder(Node $expr): bool
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof StaticCall && $expr->class->getLast() === 'RuleErrorBuilder';
    }

    // -----------------------------------------------------------------------
    // Local bindings
    // -----------------------------------------------------------------------

    private function bindLocal(Assign $assign, int $line): void
    {
        if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
            throw new Refusal('assignment to something other than a simple local', $line);
        }

        $name = $assign->var->name;
        $value = $assign->expr;

        // $ruleErrors = [];  — the accumulator a rule fills in a loop. Reports are emitted where they
        // are appended, so the binding itself produces no code.
        if ($value instanceof Array_ && $value->items === []) {
            $this->locals[$name] = ['rust' => '', 'kind' => 'accumulator'];

            return;
        }

        // $flag = true;  /  $flag = false;  — state carried across loop iterations, which needs a
        // real mutable binding rather than a compile-time alias like every other local here.
        if ($this->isBooleanLiteral($value)) {
            $this->assignBoolean($name, $this->isBooleanLiteral($value) === 'true', $line);

            return;
        }

        // $x = sprintf(..)  /  $x = self::SOME_MESSAGE
        if ($value instanceof FuncCall
            && $value->name instanceof Name
            && $value->name->toString() === 'sprintf'
        ) {
            $this->locals[$name] = ['rust' => $this->translateSprintf($value), 'kind' => 'message'];

            return;
        }

        // $x = $scope->getType(<expr>)
        if ($value instanceof MethodCall
            && (string) $value->name === 'getType'
            && $value->var instanceof Variable
            && $value->var->name === 'scope'
            && count($value->getArgs()) === 1
        ) {
            $subject = $this->resolve($value->getArgs()[0]->value, $line);
            $this->locals[$name] = ['rust' => $subject['rust'], 'kind' => 'type', 'key' => $subject['key'] ?? ''];
            if (isset($subject['php'])) {
                $this->locals[$name]['php'] = $subject['php'];
            }

            return;
        }

        // $x = $scope->getClassReflection()
        if ($value instanceof MethodCall
            && (string) $value->name === 'getClassReflection'
            && $value->var instanceof Variable
            && $value->var->name === 'scope'
        ) {
            $this->locals[$name] = ['rust' => 'context', 'kind' => 'class-reflection'];

            return;
        }

        // $x = $node->getArgs()
        if ($value instanceof MethodCall && (string) $value->name === 'getArgs') {
            $this->locals[$name] = self::$target === 'php'
                ? ['rust' => $this->argListPath($line), 'kind' => 'args', 'php' => $this->argListPath($line)]
                : ['rust' => $this->argListPath($line), 'kind' => 'args'];

            return;
        }

        // $x = <args>[N]  or  $x = <args>[N]->value  or  $x = $node->getArgs()[N]
        $argIndex = $this->argIndexOf($value);
        if ($argIndex !== null) {
            [$index, $unwrapped] = $argIndex;
            $bind = 'arg' . ($index === 0 ? '' : (string) $index) . '_value';
            $pad = str_repeat(' ', $this->indent);
            $this->lines[] = new Stm('bind-arg', ['bind' => $bind, 'args' => $this->argListPath($line), 'index' => $index], $this->indent);
            $this->locals[$name] = ['rust' => $bind, 'kind' => $unwrapped ? 'expr' : 'arg', 'key' => 'arg' . $index];
            if (self::$target === 'php') {
                // The binding is a PHP variable, so later reads of the local render as one.
                $this->locals[$name]['php'] = '$' . $bind;
            }

            return;
        }

        // $x = <expr>->value  (unwrap an Arg node)
        if ($value instanceof PropertyFetch
            && (string) $value->name === 'value'
            && $value->var instanceof Variable
            && ($this->locals[$value->var->name]['kind'] ?? null) === 'arg'
        ) {
            $this->locals[$name] = ['rust' => $this->locals[$value->var->name]['rust'], 'kind' => 'expr', 'key' => $this->exprKey($value)];
            if (isset($this->locals[$value->var->name]['php'])) {
                $this->locals[$name]['php'] = $this->locals[$value->var->name]['php'];
            }

            return;
        }

        // $x = (string) <expr>  — the cast is not a translation step
        if ($value instanceof Expr\Cast\String_) {
            $subject = $this->resolve($value->expr, $line);
            $this->locals[$name] = $subject + ['key' => $this->exprKey($value->expr)];

            return;
        }

        // $x = $node->name->toString()  (a string local, compared against literals later)
        if ($value instanceof MethodCall && (string) $value->name === 'toString') {
            $subject = $this->resolve($value->var, $line);
            $this->locals[$name] = ['rust' => $subject['rust'], 'kind' => $subject['kind'], 'key' => $subject['key'] ?? ''];
            if (isset($subject['php'])) {
                // An alias is the same expression under another name, so it renders the same way.
                $this->locals[$name]['php'] = $subject['php'];
            }

            return;
        }

        // $x = <resolvable path>  (plain alias, inheriting any refinement)
        try {
            $subject = $this->resolve($value, $line);
        } catch (Refusal) {
            throw new Refusal('assignment value outside the vocabulary', $line);
        }

        $this->locals[$name] = $subject + ['key' => $this->exprKey($value)];
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
        $rust = $this->snake($name);
        $literal = $value ? 'true' : 'false';

        if (($this->locals[$name]['kind'] ?? null) === 'bool') {
            $this->lines[] = new Stm('assign', ['target' => $this->locals[$name]['rust'], 'value' => $literal], $this->indent);

            return;
        }

        if (isset($this->locals[$name])) {
            throw new Refusal("\${$name} is already bound to something that is not a flag", $line);
        }

        $this->lines[] = new Stm('declare', ['target' => $rust, 'value' => $literal], $this->indent);
        $this->locals[$name] = ['rust' => $rust, 'kind' => 'bool'];
        if (self::$target === 'php') {
            $this->locals[$name]['php'] = '$' . $rust;
        }
    }

    /** `$node->getArgs()[N]`, `$args[N]`, and either of those with `->value`. */
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

        $container = $value->var;
        $isArgs = ($container instanceof MethodCall && (string) $container->name === 'getArgs')
            || ($container instanceof Variable && is_string($container->name)
                && ($this->locals[$container->name]['kind'] ?? null) === 'args');

        return $isArgs ? [$value->dim->value, $unwrapped] : null;
    }

    private function argListPath(int $line): string
    {
        if (! in_array($this->nodeKind, ['MethodCall', 'FunctionCall', 'StaticMethodCall'], true)) {
            throw new Refusal("no argument list on a {$this->nodeKind} node", $line);
        }

        return self::$target === 'php'
            ? 'Support::argumentList($context, $node)'
            : '&node.argument_list';
    }

    // -----------------------------------------------------------------------
    // Conditions
    // -----------------------------------------------------------------------

    /** Returns a Rust expression that is true exactly when the PHP condition is true. */
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
            return $this->parenthesiseDisjunction($cond->left) . ' && ' . $this->parenthesiseDisjunction($cond->right);
        }

        if ($cond instanceof BooleanOr) {
            return $this->translateCondition($cond->left) . ' || ' . $this->translateCondition($cond->right);
        }

        return $this->translatePredicate($cond, negated: false);
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
        if ($check === 'false') {
            return $negated ? 'true' : 'false';
        }

        return $negated ? '!(' . $this->stripOuterParentheses($check) . ')' : $check;
    }

    /** Returns a Rust expression that is true exactly when the PHP predicate is true. */
    private function predicate(Expr $expr): string
    {
        if ($expr instanceof Variable && is_string($expr->name)
            && ($this->locals[$expr->name]['kind'] ?? null) === 'bool'
        ) {
            return $this->operand($this->locals[$expr->name]);
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
            $equal = $this->equality($expr->left, $expr->right, $expr->getLine());

            return $expr instanceof NotIdentical ? "!({$equal})" : $equal;
        }

        if ($expr instanceof GreaterOrEqual || $expr instanceof Greater
            || $expr instanceof SmallerOrEqual || $expr instanceof Smaller
        ) {
            return $this->intComparison($expr);
        }

        throw new Refusal('condition outside the vocabulary: ' . $expr->getType(), $expr->getLine());
    }

    private function staticHelperPredicate(StaticCall $expr): string
    {
        $helper = $expr->class->getLast();
        $method = (string) $expr->name;
        $args = $expr->getArgs();

        if ($helper === 'NamingHelper' && $method === 'isName' && count($args) === 2) {
            $literal = $this->stringLiteral($args[1]->value, $expr->getLine());

            return $this->nameEquals($this->resolve($args[0]->value, $expr->getLine()), $literal, $expr->getLine());
        }

        if ($helper === 'MethodCallNameAnalyzer' && $method === 'isThisMethodCall' && count($args) === 2) {
            $literal = $this->stringLiteral($args[1]->value, $expr->getLine());

            return $this->backend->call('is_this_method_call', [
                ...(self::$target === 'php' ? ['$context', '$node'] : ['node']),
                $this->backend->bytes($literal),
            ]);
        }

        // Any other static helper whose source we can find is inlined rather than hand-translated.
        $helperClass = $this->findClassByName($helper);
        if ($helperClass !== null) {
            return $this->inlineMethod($helperClass['class'], $method, $args, $expr->getLine(), $helperClass['uses']);
        }

        throw new Refusal("unknown static helper {$helper}::{$method}()", $expr->getLine());
    }

    private function instanceofPredicate(Instanceof_ $expr): string
    {
        if (! $expr->class instanceof Name) {
            throw new Refusal('instanceof with a dynamic class', $expr->getLine());
        }

        $wanted = $this->resolveClassName($expr->class);
        $subject = $this->resolve($expr->expr, $expr->getLine());

        // `$type instanceof ObjectType` is a *type* test, not a node test.
        if ($wanted === ObjectType::class) {
            if ($subject['kind'] !== 'type') {
                throw new Refusal('ObjectType test on something that is not a resolved type', $expr->getLine());
            }

            if (self::$target === 'php') {
                // The SDK hands types to a node hook at specific positions only, requested through
                // FileAnalysisRequirement, rather than for any sub-expression. The receiver of a method
                // call is one of those positions; anything else is refused rather than approximated.
                if ($subject['rust'] !== 'node.object') {
                    throw new Refusal("the inferred type of {$subject['rust']} is not a position the SDK exposes");
                }

                $this->usesReceiverType = true;

                return $this->backend->call('type_is_named_object', ['$context->receiverType']);
            }

            return "support::type_is_named_object(context, {$subject['rust']})";
        }

        if ($wanted === Class_::class && $subject['kind'] === 'hook-node') {
            $this->narrowedToClass = true;

            return 'true'; // the class declaration hook only fires for classes
        }

        if ($subject['kind'] === 'hint-option' || $subject['kind'] === 'hint') {
            $suffix = $subject['kind'] === 'hint-option' ? '_option' : '';
            $hintPredicates = [
                UnionType::class => 'hint_is_union',
                IntersectionType::class => 'hint_is_intersection',
                Name::class => "hint{$suffix}_is_name",
            ];
            if (! isset($hintPredicates[$wanted])) {
                throw new Refusal("instanceof {$wanted} on a type hint", $expr->getLine());
            }

            // `hint_option_is_name` and `hint_is_name` differ only in whether the hint may be absent,
            // which a null-tolerant PHP helper covers with one name.
            $helper = self::$target === 'php'
                ? str_replace('_option', '', $hintPredicates[$wanted])
                : $hintPredicates[$wanted];

            return $this->backend->call($helper, [$this->operand($subject)]);
        }

        if ($wanted === ClassReflection::class) {
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('ClassReflection test on something else', $expr->getLine());
            }

            // "is there an enclosing class" — yes by construction inside a declaration hook.
            return $this->classFrom === 'metadata'
                ? 'true'
                : $this->backend->call('is_in_class', self::$target === 'php' ? ['$context', '$node'] : ['context']);
        }

        if ($subject['kind'] === 'name-selector') {
            if ($wanted === Identifier::class) {
                return $this->backend->call('selector_is_identifier', [$this->operand($subject)]);
            }

            throw new Refusal("instanceof {$wanted} on a member selector", $expr->getLine());
        }

        if ($subject['kind'] === 'extends') {
            if ($wanted === Name::class) {
                return $this->backend->call('has_extends', self::$target === 'php' ? ['$context', '$node'] : ['node']);
            }

            throw new Refusal("instanceof {$wanted} on an extends clause", $expr->getLine());
        }

        if (! isset(Vocabulary::NODE_PREDICATES[$wanted])) {
            throw new Refusal("no node predicate for instanceof {$wanted}", $expr->getLine());
        }

        return $this->backend->call(Vocabulary::NODE_PREDICATES[$wanted], [$this->operand($subject)]);
    }

    private function methodPredicate(MethodCall $expr): string
    {
        $method = (string) $expr->name;
        $args = $expr->getArgs();

        // Trinary-logic tails: ->yes() / ->no() on a type or scope query.
        if (($method === 'yes' || $method === 'no') && $expr->var instanceof MethodCall) {
            $inner = $expr->var;
            $innerName = (string) $inner->name;
            $innerArgs = $inner->getArgs();

            if ($innerName === 'hasMethod' && count($innerArgs) === 1) {
                $subject = $this->resolve($inner->var, $expr->getLine());
                $this->requireType($subject, $expr->getLine());
                $literal = $this->stringLiteral($innerArgs[0]->value, $expr->getLine());
                if (self::$target === 'php') {
                    if ($subject['rust'] !== 'node.object') {
                        throw new Refusal("the inferred type of {$subject['rust']} is not a position the SDK exposes");
                    }

                    $this->usesReceiverType = true;
                    $check = $this->backend->call('type_has_method', ['$context', '$context->receiverType', $this->backend->bytes($literal)]);
                } else {
                    $check = "support::type_has_method(context, {$subject['rust']}, b\"{$literal}\")";
                }

                return $method === 'yes' ? $check : "!({$check})";
            }

            if ($innerName === 'isInstanceOf' && count($innerArgs) === 1) {
                $subject = $this->resolve($inner->var, $expr->getLine());
                $this->requireType($subject, $expr->getLine());
                $literal = $this->classLiteral($innerArgs[0]->value, $expr->getLine());
                if (self::$target === 'php') {
                    if ($subject['rust'] !== 'node.object') {
                        throw new Refusal("the inferred type of {$subject['rust']} is not a position the SDK exposes");
                    }

                    $this->usesReceiverType = true;
                    $check = $this->backend->call('type_is_instance_of', ['$context', '$context->receiverType', $this->backend->bytes($literal)]);
                } else {
                    $check = "support::type_is_instance_of(context, {$subject['rust']}, b\"{$literal}\")";
                }

                return $method === 'yes' ? $check : "!({$check})";
            }

            if ($innerName === 'hasVariableType' && count($innerArgs) === 1
                && $inner->var instanceof Variable && $inner->var->name === 'scope'
            ) {
                $this->readsPriorScope = true;
                $name = $this->variableNameExpression($innerArgs[0]->value, $expr->getLine());
                $undefined = "support::variable_is_undefined(context, {$name})";

                return $method === 'no' ? $undefined : "!({$undefined})";
            }

            throw new Refusal("trinary tail on an unsupported query ->{$innerName}()", $expr->getLine());
        }

        if ($method === 'isInClass' && $expr->var instanceof Variable && $expr->var->name === 'scope') {
            // Inside a declaration hook the answer is yes by construction.
            return $this->classFrom === 'metadata'
                ? 'true'
                : $this->backend->call('is_in_class', self::$target === 'php' ? ['$context', '$node'] : ['context']);
        }

        // $classReflection->is($type) — the enclosing class, against a literal or a loop variable
        if ($method === 'is' && count($args) === 1) {
            $subject = $this->resolve($expr->var, $expr->getLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('is() on something other than the scope class', $expr->getLine());
            }

            return $this->enclosingClassIs($this->bytesValue($args[0]->value, $expr->getLine()));
        }

        // Reflection predicates. Inside a declaration hook these come from the class metadata, and
        // two of them are settled by which hook it is: the class hook fires only for classes, and
        // never for anonymous ones, which are a separate node in Mago.
        if (in_array($method, ['isClass', 'isAnonymous', 'isAbstract', 'isInterface'], true) && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal("{$method}() on something other than a class reflection", $expr->getLine());
            }

            if ($this->classFrom !== 'metadata') {
                throw new Refusal("{$method}() outside a declaration hook", $expr->getLine());
            }

            $this->usesMetadata = true;

            return match ($method) {
                'isClass' => $this->classHookIsClass(),
                'isAnonymous' => 'false',
                'isInterface' => 'false',
                default => 'support::metadata_is_abstract(metadata)',
            };
        }

        if ($method === 'getName' && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('getName() on something other than a class reflection', $expr->getLine());
            }

            $this->usesMetadata = true;

            throw new Refusal('getName() used as a predicate', $expr->getLine());
        }

        if ($method === 'isSubclassOf' && count($args) === 1) {
            $subject = $this->resolve($expr->var, $expr->getLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('isSubclassOf() on something other than the scope class', $expr->getLine());
            }

            $literal = $this->classLiteral($args[0]->value, $expr->getLine());

            // PHPStan's isSubclassOf() excludes the class itself; Mago's is_instance_of() includes
            // it. For the interface/abstract parents this vocabulary sees, the two coincide.
            return $this->enclosingClassIs($this->backend->bytes($literal));
        }

        if ($method === 'isFirstClassCallable') {
            // Mago parses `f(...)` as a PartialApplication, which never reaches a call hook, so
            // the guard cannot hold. Dropping it is recorded in the output rather than silent.
            return 'false';
        }

        // A private helper on the rule itself: same treatment as a static one.
        if ($expr->var instanceof Variable && $expr->var->name === 'this' && $this->currentClass instanceof Class_) {
            return $this->inlineMethod($this->currentClass, $method, $args, $expr->getLine());
        }

        throw new Refusal("method call outside the vocabulary ->{$method}()", $expr->getLine());
    }

    private function functionPredicate(FuncCall $expr): string
    {
        $name = $expr->name->toString();
        $args = $expr->getArgs();

        if (in_array($name, ['str_ends_with', 'str_starts_with', 'str_contains'], true) && count($args) === 2) {
            $subject = $this->resolve($args[0]->value, $expr->getLine());
            $needle = $this->bytesValue($args[1]->value, $expr->getLine());

            if ($subject['kind'] === 'file') {
                $support = ['str_ends_with' => 'file_ends_with', 'str_starts_with' => 'file_starts_with', 'str_contains' => 'file_contains'][$name];

                return $this->backend->call($support, self::$target === 'php' ? ['$context', $needle] : ['context', $needle]);
            }

            if (in_array($subject['kind'], ['class-name', 'bytes'], true)) {
                $support = ['str_ends_with' => 'bytes_end_with', 'str_starts_with' => 'bytes_start_with', 'str_contains' => 'bytes_contain'][$name];

                return $this->backend->call($support, [$this->operand($subject), $needle]);
            }

            throw new Refusal("{$name}() on a {$subject['kind']}", $expr->getLine());
        }

        // array_any(<list of strings>, fn ($x) => <predicate using $x>)
        if (in_array($name, ['array_any', 'array_all'], true) && count($args) === 2) {
            $options = $this->stringList($args[0]->value, $expr->getLine());
            $closure = $args[1]->value;
            if (! $closure instanceof ArrowFunction || count($closure->params) !== 1) {
                throw new Refusal("{$name}() with something other than a one-parameter arrow function", $expr->getLine());
            }

            $parameter = $closure->params[0]->var;
            if (! $parameter instanceof Variable || ! is_string($parameter->name)) {
                throw new Refusal("{$name}()'s parameter is not a simple variable", $expr->getLine());
            }

            $saved = $this->locals;
            $this->locals[$parameter->name] = ['rust' => 'item', 'kind' => 'bytes', 'php' => '$item'];
            try {
                $predicate = $this->translateCondition($closure->expr);
            } finally {
                $this->locals = $saved;
            }

            $list = $this->byteSliceList($options);
            $combinator = $name === 'array_any' ? 'any' : 'all';

            if (self::$target === 'php') {
                // PHP 8.4 has array_any(), but the generated rules should run on 8.1, so the support
                // runtime carries the combinator instead.
                return $this->backend->call($combinator === 'any' ? 'any_of' : 'all_of', [
                    $list,
                    "static fn (\$item): bool => {$predicate}",
                ]);
            }

            return "{$list}.iter().copied().{$combinator}(|item| {$predicate})";
        }

        if ($name === 'in_array' && count($args) >= 2) {
            if (count($args) < 3 || ! $args[2]->value instanceof ConstFetch
                || strtolower($args[2]->value->name->toString()) !== 'true'
            ) {
                // Loose in_array() compares with ==, which is not what any of these rules mean.
                throw new Refusal('in_array() without strict comparison', $expr->getLine());
            }

            $options = $this->stringList($args[1]->value, $expr->getLine());
            $subject = $this->resolve($args[0]->value, $expr->getLine());
            $list = $this->byteSliceList($options);

            return match ($subject['kind']) {
                'local-name' => "support::local_name_is_one_of({$subject['rust']}, &{$list})",
                'name-selector' => $this->backend->call('selector_is_one_of', [$this->operand($subject), self::$target === 'php' ? $list : '&' . $list]),
                'name-expr' => "support::name_is_one_of({$subject['rust']}, &{$list})",
                'extends' => "support::extends_is_one_of(context, node, &{$list})",
                default => throw new Refusal("in_array() over a {$subject['kind']}", $expr->getLine()),
            };
        }

        if ($name === 'is_string' && count($args) === 1) {
            $target = $args[0]->value;
            if ($target instanceof PropertyFetch && (string) $target->name === 'name') {
                $subject = $this->resolve($target->var, $expr->getLine());

                return self::$target === 'php'
                    ? $this->backend->call('direct_variable_name', [$this->operand($subject)]) . ' !== null'
                    : "support::direct_variable_name({$subject['rust']}).is_some()";
            }

            throw new Refusal('is_string() on something outside the vocabulary', $expr->getLine());
        }

        throw new Refusal("function call outside the vocabulary {$name}()", $expr->getLine());
    }

    /** `count($node->getArgs()) === N` and `$args === []`. */
    private function equality(Expr $left, Expr $right, int $line): string
    {
        // count(<args>) === N
        if ($left instanceof FuncCall && $left->name instanceof Name && $left->name->toString() === 'count') {
            $subject = $this->resolve($left->getArgs()[0]->value, $line);
            if ($subject['kind'] !== 'args') {
                throw new Refusal('count() of something other than an argument list', $line);
            }

            $number = $this->intLiteral($right, $line);
            $equals = self::$target === 'php' ? '===' : '==';

            return $this->backend->call('arg_count', [$this->operand($subject)]) . " {$equals} {$number}";
        }

        // <args> === []  /  <members> === []
        if ($right instanceof Array_ && $right->items === []) {
            $subject = $this->resolve($left, $line);
            if (isset(Vocabulary::ITERABLES[$subject['kind']])) {
                if (self::$target === 'php') {
                    return $this->operand($subject) . ' === []';
                }

                return "{$subject['rust']}.is_empty()";
            }

            if ($subject['kind'] !== 'args') {
                throw new Refusal("empty-array comparison against a {$subject['kind']}", $line);
            }

            return $this->backend->call('arg_count', [$this->operand($subject)])
                . (self::$target === 'php' ? ' === 0' : ' == 0');
        }

        // $flag === true / $flag === false
        if ($left instanceof Variable && is_string($left->name)
            && ($this->locals[$left->name]['kind'] ?? null) === 'bool'
            && ($wanted = $this->isBooleanLiteral($right)) !== null
        ) {
            $flag = $this->operand($this->locals[$left->name]);

            return $wanted === 'true' ? $flag : "!{$flag}";
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

            return $this->backend->call('is_uppercase', [$this->operand($inner)]);
        }

        // <name>->toString() === 'literal'   /   <string local> === 'literal'
        if ($left instanceof MethodCall && (string) $left->name === 'toString') {
            return $this->nameEquals($this->resolve($left->var, $line), $this->stringLiteral($right, $line), $line);
        }

        if ($left instanceof PropertyFetch && (string) $left->name === 'name') {
            $subject = $this->resolve($left->var, $line);
            $literal = $this->stringLiteral($right, $line);
            if (self::$target === 'php') {
                return $this->backend->call('direct_variable_name', [$this->operand($subject)])
                    . ' === ' . $this->backend->bytes($literal);
            }

            return "support::direct_variable_name({$subject['rust']}) == Some(&b\"{$literal}\"[..])";
        }

        $subject = $this->resolve($left, $line);
        if (in_array($subject['kind'], ['name-selector', 'name-expr', 'extends', 'hint', 'hint-option'], true)) {
            return $this->nameEquals($subject, $this->stringLiteral($right, $line), $line);
        }

        throw new Refusal(
            'comparison outside the vocabulary: ' . $left->getType() . ' against ' . $right->getType(),
            $line,
        );
    }

    /** `$intNode->value >= 1` and friends. */
    private function intComparison(BinaryOp $expr): string
    {
        $left = $expr->left;
        if (! $left instanceof PropertyFetch || (string) $left->name !== 'value') {
            throw new Refusal('numeric comparison outside the vocabulary', $expr->getLine());
        }

        $subject = $this->resolve($left->var, $expr->getLine());
        $number = $this->intLiteral($expr->right, $expr->getLine());
        $operator = match (true) {
            $expr instanceof GreaterOrEqual => '>=',
            $expr instanceof Greater => '>',
            $expr instanceof SmallerOrEqual => '<=',
            default => '<',
        };
        if (self::$target === 'php') {
            // Rust's `is_some_and` folds the absent case into the comparison; PHP has no equivalent, so
            // the operator is passed to the helper rather than emitted twice around a repeated call.
            return $this->backend->call('int_compares', [
                $this->operand($subject),
                $this->backend->bytes($operator),
                (string) $number,
            ]);
        }

        return "support::int_literal_value({$subject['rust']}).is_some_and(|value| value {$operator} {$number})";
    }

    /**
     * @param array<string, string>|array<string, mixed[]> $subject
     */
    private function nameEquals(array $subject, string $literal, int $line): string
    {
        return match ($subject['kind']) {
            'local-name' => "support::local_name_is({$subject['rust']}, b\"{$literal}\")",
            'name-selector' => $this->backend->call('selector_is', [$this->operand($subject), $this->backend->bytes($literal)]),
            'name-expr' => $this->backend->call('name_equals', [$this->operand($subject), $this->backend->bytes($literal)]),
            'extends' => $this->backend->call('extends_is', self::$target === 'php'
                ? ['$context', '$node', $this->backend->bytes($literal)]
                : ['context', 'node', $this->backend->bytes($literal)]),
            'hint', 'hint-option' => self::$target === 'php'
                ? $this->backend->call('hint_name_is', ['$context', $this->operand($subject), $this->backend->bytes($literal)])
                : sprintf(
                    'support::hint%s_name_is(context, %s, b"%s")',
                    $subject['kind'] === 'hint-option' ? '_option' : '',
                    $subject['rust'],
                    $literal,
                ),
            'expr' => "support::expression_selector_is({$subject['rust']}, b\"{$literal}\")",
            default => throw new Refusal("name comparison against a {$subject['kind']}", $line),
        };
    }

    /**
     * `$classReflection->isClass()` inside the class declaration hook.
     *
     * Always true there, and recording it means the rule counts as narrowed: PHPStan's `InClassNode`
     * visits interfaces, traits and enums, this guard discards them, and the class hook does the same
     * by never firing.
     */
    private function classHookIsClass(): string
    {
        $this->narrowedToClass = true;

        return 'true';
    }

    /** The enclosing-class test, from whichever source this hook provides. */
    private function enclosingClassIs(string $bytes): string
    {
        if ($this->classFrom === 'metadata') {
            $this->usesMetadata = true;

            return $this->backend->call('metadata_is', self::$target === 'php'
                ? ['$context', '$node', $bytes]
                : ['context', 'metadata', $bytes]);
        }

        return $this->backend->call('enclosing_class_is', self::$target === 'php'
            ? ['$context', '$node', $bytes]
            : ['context', $bytes]);
    }

    /**
     * @param array<string, string>|array<string, mixed[]> $subject
     */
    private function requireType(array $subject, int $line): void
    {
        if ($subject['kind'] !== 'type') {
            throw new Refusal('type query on something that is not a resolved type', $line);
        }
    }

    private function variableNameExpression(Expr $expr, int $line): string
    {
        if ($expr instanceof PropertyFetch && (string) $expr->name === 'name') {
            $subject = $this->resolve($expr->var, $line);

            return "support::direct_variable_name({$subject['rust']}).unwrap_or_default()";
        }

        if ($expr instanceof Variable && is_string($expr->name) && isset($this->locals[$expr->name])) {
            $local = $this->locals[$expr->name];
            if ($local['kind'] === 'variable-name') {
                return $local['rust'];
            }
        }

        throw new Refusal('variable name outside the vocabulary', $line);
    }

    // -----------------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------------

    /** @return array{rust: string, kind: string, fields?: array, key?: string} */
    private function resolve(Expr $expr, int $line): array
    {
        if ($expr instanceof Variable && $expr->name === 'node') {
            return ['rust' => 'node', 'kind' => 'hook-node', 'key' => '$node', 'php' => '$node'];
        }

        if ($expr instanceof Variable && is_string($expr->name)) {
            if (isset($this->locals[$expr->name])) {
                return $this->locals[$expr->name];
            }

            throw new Refusal("unknown local \${$expr->name}", $line);
        }

        if ($expr instanceof MethodCall
            && (string) $expr->name === 'getOriginalNode'
            && $expr->var instanceof Variable
            && $expr->var->name === 'node'
        ) {
            return ['rust' => 'node', 'kind' => 'hook-node', 'key' => '$node', 'php' => '$node'];
        }

        if ($expr instanceof MethodCall
            && (string) $expr->name === 'getClassProperties'
            && false
        ) {
            return [];
        }

        if ($expr instanceof MethodCall
            && (string) $expr->name === 'getFile'
            && $expr->var instanceof Variable
            && $expr->var->name === 'scope'
        ) {
            return ['rust' => 'context', 'kind' => 'file', 'php' => '$context'];
        }

        if ($expr instanceof MethodCall
            && (string) $expr->name === 'getClassReflection'
            && $expr->var instanceof Variable
            && in_array($expr->var->name, ['scope', 'node'], true)
        ) {
            return ['rust' => 'context', 'kind' => 'class-reflection'];
        }

        // $node->get(SomeCollector::class)
        if ($expr instanceof MethodCall
            && (string) $expr->name === 'get'
            && $expr->var instanceof Variable
            && $expr->var->name === 'node'
            && count($expr->getArgs()) === 1
        ) {
            $collector = $this->rawStringLiteral($expr->getArgs()[0]->value, $line);
            $short = substr($collector, (int) strrpos('\\' . $collector, '\\'));

            return ['rust' => "support::collected(\"{$short}\")", 'kind' => 'collected', 'collector' => $short];
        }

        if ($expr instanceof MethodCall && (string) $expr->name === 'getName' && $expr->args === []) {
            $base = $this->resolve($expr->var, $line);
            if ($base['kind'] !== 'class-reflection') {
                throw new Refusal('getName() on something other than a class reflection', $line);
            }

            if ($this->classFrom !== 'metadata') {
                throw new Refusal('getName() outside a declaration hook', $line);
            }

            $this->usesMetadata = true;

            return [
                'rust' => 'support::metadata_name(metadata)',
                'kind' => 'class-name',
                'php' => 'Support::enclosingClassName($context, $node)',
            ];
        }

        if ($expr instanceof MethodCall && (string) $expr->name === 'getProperties') {
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

        if ($expr instanceof MethodCall && (string) $expr->name === 'getArgs') {
            $args = ['rust' => $this->argListPath($line), 'kind' => 'args'];
            if (self::$target === 'php') {
                $args['php'] = $args['rust'];
            }

            return $args;
        }

        if ($expr instanceof MethodCall && (string) $expr->name === 'toString') {
            return $this->resolve($expr->var, $line);
        }

        if ($expr instanceof PropertyFetch) {
            $property = (string) $expr->name;
            $key = $this->exprKey($expr);

            // A narrowing binding for this exact path takes precedence.
            $baseKey = $this->exprKey($expr->var);
            if (isset($this->refinements[$baseKey][$property])) {
                $refined = $this->refinements[$baseKey][$property];
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

            if ($base['kind'] === 'hook-node' && isset(Vocabulary::FIELDS[$this->nodeKind][$property])) {
                [$rust, $kind] = Vocabulary::FIELDS[$this->nodeKind][$property];
                $descriptor = ['rust' => $rust, 'kind' => $kind, 'key' => $key];
                $php = Vocabulary::FIELDS[$this->nodeKind][$property][2] ?? null;
                if ($php !== null) {
                    $descriptor['php'] = $php;
                }

                return $descriptor;
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
                $phpTemplate = Vocabulary::KIND_FIELDS[$base['kind']][$property][2] ?? null;
                if (isset($base['php'])) {
                    $descriptor['php'] = str_replace('{base}', $base['php'], $phpTemplate);
                }

                return $descriptor;
            }

            if ($base['kind'] === 'hint-option' && $property === 'types') {
                $parts = ['rust' => "support::hint_parts({$base['rust']})", 'kind' => 'hint-parts', 'key' => $key];
                if (isset($base['php'])) {
                    $parts['php'] = $this->backend->call('hint_parts', [$base['php']]);
                }

                return $parts;
            }

            if ($base['kind'] === 'const-item' && $property === 'name') {
                return [
                    'rust' => "support::constant_item_name({$base['rust']})",
                    'kind' => 'bytes',
                    'key' => $key,
                    'php' => $this->backend->call('constant_item_name', [$this->operand($base)]),
                ];
            }

            if ($base['kind'] === 'expr' && $property === 'name') {
                // The name of an as-yet-unnarrowed expression; only comparisons can use this.
                return ['rust' => $base['rust'], 'kind' => 'expr', 'key' => $key];
            }

            if (self::$survey) {
                return ['rust' => "node.{$property}", 'kind' => 'expr', 'key' => $key];
            }

            throw new Refusal("no mapping for ->{$property} on a {$base['kind']}", $line);
        }

        throw new Refusal('access path outside the vocabulary: ' . $expr->getType(), $line);
    }

    // -----------------------------------------------------------------------
    // Literals
    // -----------------------------------------------------------------------

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

        throw new Refusal('expected a string literal', $line);
    }

    private function intLiteral(Node $expr, int $line): int
    {
        if ($expr instanceof Int_) {
            return $expr->value;
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
        $fqcn = $this->useMap[$alias] ?? $expr->class->toString();
        $constant = (string) $expr->name;

        if ($constant === 'class') {
            return $fqcn;
        }

        // The rule's own constants first: they are already parsed, and `self::` cannot be found by
        // searching the vendor tree for a file named after the class.
        if (in_array($expr->class->toString(), ['self', 'static'], true)) {
            if (isset($this->constants[$constant])) {
                return $this->constants[$constant];
            }

            throw new Refusal("self::{$constant} is not a string constant of this rule", $line);
        }

        $short = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
        foreach ($this->classFiles($this->file)[$short] ?? [] as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match('/const\s+(?:string\s+)?' . preg_quote($constant, '/') . '\s*=\s*\'([^\']*)\'/', $source, $match) === 1) {
                return $match[1];
            }
        }

        throw new Refusal("could not resolve {$alias}::{$constant}", $line);
    }

    /**
     * basename -> paths, over every vendor tree the rules seen so far live in.
     *
     * Merged and keyed by root rather than cached from the first rule: a rule file outside any
     * vendor directory would otherwise poison the cache with an empty index, and which rule that is
     * depends on argument order.
     */
    private function classFiles(string $ruleFile): array
    {
        $vendor = $ruleFile;
        while ($vendor !== '/' && $vendor !== '.' && basename($vendor) !== 'vendor') {
            $vendor = dirname($vendor);
        }

        if ($vendor === '/' || $vendor === '.' || isset(self::$indexedRoots[$vendor])) {
            return self::$classFiles;
        }

        self::$indexedRoots[$vendor] = true;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                self::$classFiles[$entry->getBasename('.php')][] = $entry->getPathname();
            }
        }

        return self::$classFiles;
    }

    /** Node-kind names PHP reserves, which the SDK spells with a trailing underscore. */
    private const array PHP_RESERVED_KINDS = ['class', 'function', 'default', 'list', 'print', 'echo', 'unset', 'exit', 'match', 'try', 'use', 'for', 'foreach', 'while', 'do', 'if', 'else', 'switch', 'return', 'global', 'static', 'break', 'continue', 'namespace', 'const', 'goto'];

    /** Rust identifiers that a PHP variable name could collide with. */
    private const array RUST_KEYWORDS = [
        'as', 'break', 'const', 'continue', 'crate', 'else', 'enum', 'extern', 'false', 'fn', 'for',
        'if', 'impl', 'in', 'let', 'loop', 'match', 'mod', 'move', 'mut', 'pub', 'ref', 'return',
        'self', 'static', 'struct', 'super', 'trait', 'true', 'type', 'unsafe', 'use', 'where',
        'while', 'async', 'await', 'dyn', 'abstract', 'become', 'box', 'do', 'final', 'macro',
        'override', 'priv', 'typeof', 'unsized', 'virtual', 'yield', 'try',
    ];

    private function snake(string $name): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', rtrim($name, '_')) ?? $name);

        return in_array($snake, self::RUST_KEYWORDS, true) ? $snake . '_' : $snake;
    }

    // -----------------------------------------------------------------------
    // Emission
    // -----------------------------------------------------------------------

    /**
     * Support helpers the linter tier cannot serve, and why.
     *
     * Each of these reaches for something the linter does not have: an inferred type, the variables
     * in scope at a point, or the class hierarchy across files. A rule that calls one is refused for
     * that tier rather than approximated, on the same principle as the rest of this transpiler. In
     * particular `enclosing_class_is` and `metadata_is` look syntactic and are not: both ask whether
     * a class is a subclass of another, which means walking a parent chain that may leave the file.
     */
    private const array LINT_BLOCKED = [
        'type_is_named_object' => 'the inferred type of an expression',
        'type_is_instance_of' => 'the inferred type of an expression',
        'type_has_method' => 'the inferred type of an expression',
        'variable_is_undefined' => 'the variables in scope at this point',
        'enclosing_class_is' => 'the class hierarchy, which can cross files',
        'metadata_is' => 'the class hierarchy, which can cross files',
        'collect' => 'data accumulated across every file',
        'collected' => 'data accumulated across every file',
        'collected_value' => 'data accumulated across every file',
        'clear_collected' => 'data accumulated across every file',
    ];

    /**
     * The same rule body, wrapped as a linter rule instead of an analyzer plugin.
     *
     * The body is emitted once and reused: it navigates the CST and calls support helpers, neither of
     * which is tier-specific. What differs is the wrapper, the way a finding is reported, and how a
     * guard bails out, since `check` returns unit where a hook returns a result.
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     */
    private function emitLint(string $className, array $hook): string
    {
        if ($this->isCollector || $hook['trait'] === 'AnalysisHook') {
            throw new Refusal('the linter has no whole-run hook, so a collector cannot run on that tier');
        }

        if ($this->readsPriorScope) {
            throw new Refusal('reads the scope before the node, which the linter does not track');
        }

        if (isset($hook['each'])) {
            throw new Refusal('per-item reporting is not mapped for the linter tier');
        }

        // On this tier the node kind *is* the adapter: matching `Node::ClassConstantAccess` reaches
        // exactly what `as_class_constant_access` reaches. That equivalence holds only for adapters
        // that are a plain variant match, so anything that also filters is refused rather than
        // silently widened. `as_assignment` is the one that matters: it rejects `+=` and friends,
        // which `NodeKind::Assignment` would happily match.
        $variantOnlyAdapters = ['as_class_constant_access', 'as_global_constant', 'as_instantiation', 'as_method_call'];
        if (isset($hook['adapter']) && ! in_array($hook['adapter'], $variantOnlyAdapters, true)) {
            throw new Refusal("the adapter {$hook['adapter']} filters as well as matching, which the node kind does not");
        }

        $this->reportSpan = 'node.span()';
        $body = $this->renderAll() . ($this->reportedInline ? '' : $this->reportStatement());
        foreach (self::LINT_BLOCKED as $helper => $reason) {
            if (str_contains($body, "support::{$helper}(")) {
                throw new Refusal("needs {$reason} (support::{$helper})");
            }
        }

        if ($this->usesMetadata) {
            throw new Refusal('needs the enclosing class metadata, which the linter hooks do not carry');
        }

        // A hook returns `HookResult`; `check` returns unit. The bail is the only place the body
        // encodes that, so it is rewritten rather than parameterised through every guard.
        $body = str_replace(['return Ok({BAIL});', 'return Ok(());'], 'return;', $body);
        if (str_contains($body, 'Ok(')) {
            throw new Refusal('body still returns a hook result after rewriting');
        }

        // The analyzer needs one of its own enum variants alongside the real code; the linter takes
        // the issue directly. The `.with_code(..)` the body already emits is PHPStan's identifier, so
        // a finding is labelled identically on both tiers.
        $body = preg_replace('/context\.report\(\s*\n\s*IssueCode::\w+,\s*\n/', "context.collector.report(\n", $body);
        $body = str_replace('Issue::error(', 'Issue::new(self.cfg.level, ', (string) $body);

        $kind = $hook['kind'];
        $identifier = $this->identifier ?? throw new Refusal('no identifier to use as the rule code');
        $struct = $className;
        $config = $className . 'Config';

        // Only imported when the body reaches for it: a rule whose adapter became the node-kind match
        // can end up calling no helper at all, and an unused import is a warning.
        $supportImport = str_contains($body, 'support::') ? "use crate::rule::transpiled::support;\n" : '';

        [$good, $bad] = $this->corpusExamples($className);
        $goodExample = $this->rustString($good);
        $badExample = $this->rustString($bad);

        return <<<RUST
// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.
use mago_allocator::Arena;
use schemars::JsonSchema;

use mago_reporting::Annotation;
use mago_reporting::Issue;
use mago_reporting::Level;
use mago_span::HasSpan;
use mago_syntax::cst::Node;
use mago_syntax::cst::NodeKind;

use crate::category::Category;
use crate::context::LintContext;
use crate::requirements::RuleRequirements;
use crate::rule::Config;
use crate::rule::LintRule;
{$supportImport}use crate::rule_meta::RuleMeta;
use crate::settings::RuleSettings;

#[derive(Debug, Clone)]
pub struct {$struct} {
    cfg: {$config},
}

#[derive(Debug, Clone, Copy, Eq, PartialEq, Hash, JsonSchema)]
#[cfg_attr(feature = "serde", derive(serde::Serialize, serde::Deserialize))]
#[cfg_attr(feature = "serde", serde(default, rename_all = "kebab-case", deny_unknown_fields))]
pub struct {$config} {
    pub level: Level,
}

impl Default for {$config} {
    fn default() -> Self {
        Self { level: Level::Error }
    }
}

impl Config for {$config} {
    fn level(&self) -> Level {
        self.level
    }

    fn default_enabled() -> bool {
        false
    }
}

impl LintRule for {$struct} {
    type Config = {$config};

    fn meta() -> &'static RuleMeta {
        const META: RuleMeta = RuleMeta {
            name: "{$className}",
            code: "{$identifier}",
            description: "Transpiled from the PHPStan rule {$className}.",
            good_example: {$goodExample},
            bad_example: {$badExample},
            category: Category::BestPractices,
            requirements: RuleRequirements::None,
        };

        &META
    }

    fn targets() -> &'static [NodeKind] {
        const TARGETS: &[NodeKind] = &[NodeKind::{$kind}];

        TARGETS
    }

    fn build(settings: &RuleSettings<Self::Config>) -> Self {
        Self { cfg: settings.config }
    }

    fn check<'arena, A>(&self, context: &mut LintContext<'_, 'arena, A>, node: Node<'_, 'arena>)
    where
        A: Arena,
    {
        let Node::{$kind}(node) = node else {
            return;
        };

{$body}    }
}
RUST;
    }

    /** The snake_case module name a generated linter rule is registered under. */
    public function lintModule(string $className): string
    {
        return $this->snake($className);
    }

    /**
     * The good and bad examples a generated linter rule documents itself with.
     *
     * Taken from the differential corpus rather than written by hand, because the corpus already says
     * which construct makes a rule fire and which near miss must leave it quiet, and those statements
     * are checked against PHPStan on every run. Inventing a second set of examples would mean
     * maintaining an unverified copy of the same claim.
     *
     * The unit extracted is the top-level declaration containing the annotation, with the file's
     * header, so the snippet parses on its own. `Mago`'s own example test lints it with only this rule
     * enabled, so other rules' annotations inside the same unit do not matter.
     *
     * @return array{string, string} good, bad
     */
    private function corpusExamples(string $className): array
    {
        $blank = "<?php\n";
        $units = [];
        foreach (glob(__DIR__ . '/../differential/corpus/*.php') ?: [] as $path) {
            $lines = file($path) ?: [];
            $header = [];
            $headerDepth = 0;
            $depth = 0;
            $unit = null;
            foreach ($lines as $line) {
                $opens = substr_count($line, '{');
                $closes = substr_count($line, '}');
                if ($depth === 0 && $unit === null) {
                    // A top-level declaration starts here, or this is still the file header.
                    if (preg_match('/^\s*(final |abstract |readonly )*(class|interface|trait|enum|function|const) /', $line) === 1) {
                        // A docblock or comment run immediately above belongs to this declaration, not
                        // to the file, or it would be repeated on top of every later unit.
                        $own = [];
                        while ($header !== [] && trim(end($header)) !== '' && ! str_ends_with(rtrim(end($header)), ';')) {
                            array_unshift($own, array_pop($header));
                        }

                        $unit = ['file' => basename($path), 'header' => $header, 'lines' => [...$own, $line], 'open' => $headerDepth];
                        $depth += $opens - $closes;
                        if ($depth <= 0 && str_contains($line, ';')) {
                            $units[] = $unit;
                            $unit = null;
                            $depth = 0;
                        }

                        continue;
                    }

                    $header[] = $line;
                    // A braced `namespace Foo { .. }` leaves the header open; the snippet has to close
                    // it again or it will not parse on its own.
                    $headerDepth += $opens - $closes;

                    continue;
                }

                $unit['lines'][] = $line;
                $depth += $opens - $closes;
                if ($depth <= 0) {
                    $units[] = $unit;
                    $unit = null;
                    $depth = 0;
                }
            }
        }

        $render = static function (array $unit): string {
            $header = implode('', $unit['header']);
            // The corpus keeps its fixtures in one namespace; a snippet does not need it, but the
            // `use` statements are load-bearing since the rules resolve written names.
            $header = (string) preg_replace('/^namespace .*;\n\n?/m', '', $header);

            $closing = str_repeat("\n}", max(0, $unit['open']));

            return rtrim($header . implode('', $unit['lines']) . $closing) . "\n";
        };

        $bad = $blank;
        $good = $blank;
        foreach ($units as $unit) {
            $body = implode('', $unit['lines']);
            $fires = preg_match('/@fires[^\n]*\b' . preg_quote($className, '/') . '\b/', $body) === 1;
            $silent = preg_match('/@silent[^\n]*\b' . preg_quote($className, '/') . '\b/', $body) === 1;
            // A `@diverges` site fires in one tool and not the other, so it is neither a clean good
            // example nor a canonical bad one.
            $diverges = preg_match('/@diverges[^\n]*\b' . preg_quote($className, '/') . '\b/', $body) === 1;
            $fires = $fires && ! $diverges;
            $silent = $silent && ! $diverges;
            if ($bad === $blank && $fires) {
                $bad = $render($unit);
                // The rules that only look at test files need the snippet to be named like one, and
                // the harness lets an example say so.
                if (str_contains($this->renderAll(), 'support::file_ends_with(')) {
                    $bad = (string) preg_replace('/^<\?php\n/', "<?php\n\n// file: " . $unit['file'], $bad, 1);
                    $bad = (string) preg_replace('/^(<\?php\n\n\/\/ file: [^\n]*)/', '$1' . "\n", $bad, 1);
                }
            }

            if ($good === $blank && $silent && ! $fires) {
                $good = $render($unit);
            }
        }

        return [$good, $bad];
    }

    /** A Rust string literal, since the examples are multi-line PHP. */
    private function rustString(string $value): string
    {
        return '"' . addcslashes($value, "\"\\\n\r\t") . '"';
    }

    /**
     * The PHP target's wrapper: a Mago SDK analyzer plugin.
     *
     * `getTargets()` does in PHP what the Rust adapter does by narrowing `Expression`, because the
     * hook table's `kind` already names the Mago node kind the SDK's `NodeKind` uses.
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     */
    private function emitPhp(string $className, array $hook): string
    {
        if ($hook['node'] === null) {
            throw new Refusal('PHP target: whole-run hooks are not wrapped yet');
        }

        if ($this->isCollector) {
            throw new Refusal('PHP target: collectors are not wrapped yet');
        }

        if ($this->message === null) {
            throw new Refusal('could not find the reported message');
        }

        $isLiteral = str_starts_with($this->message, '"') && str_ends_with($this->message, '"');
        $isFormatted = str_starts_with($this->message, 'sprintf(');
        if (! $isLiteral && ! $isFormatted) {
            throw new Refusal('PHP target: message is neither a literal nor a sprintf(): ' . $this->message);
        }

        $body = $this->renderAll();

        // A rule that already reported inside a loop has nothing to report at the end. Emitting it
        // anyway fired on every declaration, and PHP leaves the loop variable set after the loop, so
        // the message even looked plausible.
        $trailingReport = $this->reportedInline ? '' : <<<'REPORT'
        $context->report(
            Level::Error,
            '{CODE}',
            Issue::new({MESSAGE}, $node->span, 'here'),
        );

REPORT;
        $message = $isFormatted ? $this->message : $this->backend->bytes(substr($this->message, 1, -1));
        $code = $this->identifier ?? 'transpiled.' . $this->snake($className);
        $trailingReport = str_replace(['{CODE}', '{MESSAGE}'], [$code, $message], $trailingReport);
        // `NodeKind::Class` does not reference the enum case: PHP special-cases `::class` and yields
        // the class-name string, so the worker rejects the target with a type error naming neither the
        // rule nor the kind. The SDK names that case `Class_`, the same trailing-underscore convention
        // this file already uses for Rust keywords, so reserved names take the suffix.
        $kind = 'NodeKind::' . $hook['kind']
            . (in_array(strtolower($hook['kind']), self::PHP_RESERVED_KINDS, true) ? '_' : '');
        $identifier = 'transpiled/' . str_replace('_', '-', $this->snake($className));

        // Requirements are opt-in per capability: a rule that reads a type without asking for it gets
        // null, which would silently turn every check on it into a pass.
        $requirements = 'FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText';
        if ($this->usesReceiverType) {
            $requirements .= ', FileAnalysisRequirement::ReceiverType';
        }

        return <<<PHP
<?php

declare(strict_types=1);

// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.

namespace Transpiled;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;
use Sandermuller\PhpstanToMago\Runtime\Support;

final class {$className} implements Plugin, NodeAnalysisHook
{

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: '{$identifier}',
            name: '{$className}',
            description: 'Transpiled from PHPStan\'s {$className}.',
        );
    }

    public function register(PluginRegistry \$registry): void
    {
        \$registry->registerNodeAnalysisHook(\$this);
    }

    public function getTargets(): array
    {
        return [{$kind}];
    }

    public function getRequirements(): array
    {
        return [{$requirements}];
    }

    public function analyze(NodeAnalysisContext \$context): void
    {
        \$node = \$context->node;

{$body}{$trailingReport}    }

}

PHP;
    }

    /**
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     */
    private function emit(string $className, array $hook): string
    {
        $trait = $hook['trait'];
        $method = $hook['method'];
        $signatureType = $hook['node'];
        $adapter = $hook['adapter'] ?? null;
        $each = $hook['each'] ?? null;
        $extra = str_replace('{metadata}', $this->usesMetadata ? 'metadata' : '_metadata', $hook['extra'] ?? '');
        $returnType = 'HookResult<()>';
        $bail = '()';
        $tail = 'Ok(())';

        if ($this->readsPriorScope) {
            if ($hook['trait'] !== 'ExpressionHook') {
                throw new Refusal("no pre hook mapped for {$hook['trait']}");
            }

            // The rule reads the scope as it was before this node, so it must run on the pre hook.
            $method = 'before_expression';
            $returnType = 'HookResult<ExpressionHookResult>';
            $bail = 'ExpressionHookResult::Continue';
            $tail = 'Ok(ExpressionHookResult::Continue)';
        }

        $prologue = $adapter === null
            ? ''
            : "        let Some(node) = support::{$adapter}(node) else {\n            return Ok({BAIL});\n        };\n\n";
        $body = $prologue . $this->renderAll();
        if ($this->message === null && ! $this->isCollector) {
            throw new Refusal('could not find the reported message');
        }

        $ruleName = $this->snake($className);
        $ruleNameUpper = strtoupper($ruleName);

        if ($this->isCollector && ! $this->collected) {
            throw new Refusal('collector never returns a datum');
        }

        $report = $this->isCollector ? '' : null;
        $this->reportSpan = 'node.span()';
        $report ??= $this->reportedInline
            ? ''
            : ($each === null
                ? $this->reportStatement()
                : "        for item in node.{$each}.iter() {\n"
                    . "            context.report(\n"
                    . "                IssueCode::InvalidArgument,\n"
                    . "                Issue::error({$this->message})\n"
                    . "                    .with_annotation(Annotation::primary(item.span()).with_message(\"here\")),\n"
                    . "            );\n"
                    . "        }\n");

        if (($hook['classOnly'] ?? false) && ! $this->narrowedToClass) {
            throw new Refusal(
                'InClassNode fires for interfaces, traits and enums too, and this rule does not narrow '
                . 'to Class_, so it needs those declaration hooks as well',
            );
        }

        $body = str_replace('{BAIL}', $bail, $body);

        // The whole-run hook has no node to be handed, and a context of its own.
        if ($trait === 'AnalysisHook') {
            $body = str_replace('{BAIL}', $bail, $prologue . $this->renderAll());

            return <<<RUST
// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.
pub struct {$className};

static {$ruleNameUpper}_META: ProviderMeta =
    ProviderMeta::new("transpiled::{$ruleName}", "{$className}", "generated");

impl Provider for {$className} {
    fn meta() -> &'static ProviderMeta {
        &{$ruleNameUpper}_META
    }
}

impl AnalysisHook for {$className} {
    fn after_analysis(&self, context: &mut AnalysisHookContext<'_>) -> HookResult<()> {
{$body}        support::clear_collected();

        Ok(())
    }
}
RUST;
        }

        return <<<RUST
// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.
pub struct {$className};

static {$ruleNameUpper}_META: ProviderMeta =
    ProviderMeta::new("transpiled::{$ruleName}", "{$className}", "generated");

impl Provider for {$className} {
    fn meta() -> &'static ProviderMeta {
        &{$ruleNameUpper}_META
    }
}

impl {$trait} for {$className} {
    fn {$method}(&self, node: &{$signatureType}<'_>{$extra}, context: &mut HookContext<'_, '_>) -> {$returnType} {
{$body}{$report}
        {$tail}
    }
}
RUST;
    }
}

/** Assembles the emitted rules into one plugin module, so the whole output is generated. */
