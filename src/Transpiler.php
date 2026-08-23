<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
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
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
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
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;

/**
 * @phpstan-type Descriptor array{rust: string, kind: string, key?: string, php?: string, fields?: array<string, array{0: string, 1: string, 2?: string}>, collector?: string, service?: string, classPhp?: string, methodPhp?: string, indexPhp?: string, listPhp?: string, patternPhp?: string, subjectPhp?: string, reason?: string, as?: string, record?: array<string, array{rust: string, kind: string, php?: string, reason?: string, as?: string}>}
 * @phpstan-type Declaration array{class: ClassLike, uses: array<string, string>, namespace: string|null}
 * @phpstan-type RecordFields array<string, array{rust: string, kind: string, php?: string, reason?: string, as?: string}>
 */
final class Transpiler
{
    /** Survey mode: keep walking past gaps that are not about the body, to find the body gaps. */
    public static bool $survey = false;

    /** Which tier to emit for: 'analyzer' (a plugin) or 'linter' (a lint rule). */
    public static string $target = 'php';

    /**
     * Whether to emit a rule whose numbers do not yet agree with the original.
     *
     * Off by default, and the refusal it produces carries the measurement that says why — see
     * {@see Vocabulary::unverifiedAggregate()}. The flag exists so the emission can be exercised and measured
     * without the default being a rule that reports 95 % where the original reports 49 %.
     *
     * A rule-level counterpart existed briefly, for `CombinedMethodCallRule` reporting 33 findings on a real
     * project that the original did not. It is gone because the disagreement is: 26 were a real defect
     * (`Support::declaringClassName()` reading a flattened `usedTraits` list) and 7 were the consumer's own
     * phpstan-ignore comments, which the differential now accounts for separately.
     */
    public static bool $allowUnverified = false;

    /**
     * Where to read the good and bad examples a linter rule embeds in its metadata.
     *
     * Used by the linter target only. It used to be a path relative to this file, which meant a
     * sibling directory that ships with nothing: the examples came out empty and said so nowhere.
     */
    public static ?string $examplesDir = null;

    /** @var list<Stm> emitted statements, in source order: guards and bindings interleave */
    private array $lines = [];

    /** Renders {@see $lines} into the target language. */
    private readonly Backend $backend;

    /** Whether the body asks for the receiver's inferred type, which the PHP target must request. */
    private bool $usesReceiverType = false;

    /**
     * Whether the rule asks for the inferred type of a sub-expression.
     *
     * A separate requirement from `ReceiverType`, and a heavier one: it embeds every expression type in the
     * file, so it is requested only by a rule that asks for a position the ready-made ones do not cover.
     */
    private bool $usesExpressionTypes = false;

    /** The Rust expression producing the reported message, from the report site. */
    private ?string $message = null;

    /**
     * Whether the message is an expression the transpiler built rather than a literal or a `sprintf()`.
     *
     * An interpolated message becomes a concatenation, which is neither of the two shapes the emitter used to
     * accept. Recorded rather than sniffed out of the rendered text: `. ` appears inside message literals too.
     */
    private bool $messageIsExpression = false;

    /** PHPStan's `->identifier(..)`, which becomes the issue's code so the two tools agree on it. */
    private ?string $identifier = null;

    /**
     * Every identifier the rule reports under, in the order it takes them.
     *
     * `$identifier` holds the last one, which is what the trailing report uses. A merged rule reports under
     * one identifier per check, and a harness comparing the two tools on a single identifier would measure
     * one check and pass on the others' silence.
     *
     * @var list<string>
     */
    private array $identifiers = [];

    /**
     * Whole-project passes this rule's checks were handed to, rendered as calls.
     *
     * A rule with any of these is emitted as both a node hook and an after-analysis hook — one plugin, two
     * hook kinds, which `internal/probe-dual-hook-plugin.php` measured works. Nothing crosses between them:
     * `internal/probe-collect-across-workers.php` measured `afterAnalysis` running in a different process
     * than the node hooks above one worker, so a pass finds its own subjects.
     *
     * @var list<string>
     */
    private array $afterChecks = [];

    /** @var array<string, string> the rule's own string constants, by name */
    private array $constants = [];

    /** @var array<string, list<string>> the rule's own list-of-string constants, by name */
    private array $arrayConstants = [];

    /**
     * The keys of the rule's own map constants, by name.
     *
     * `['dump' => true, 'dd' => true]` is a set spelled as keys, and `isset(self::X[$name])` asks whether a
     * name is in it. The values are always `true` in the corpus and carry nothing.
     *
     * @var array<string, list<string>>
     */
    private array $constantKeys = [];

    /**
     * String literals bound to a helper's parameters, by parameter name.
     *
     * A helper called with a literal — `namespaceStartsWith($scope, 'App')` — can use that parameter where a
     * literal is required, and the value is known at transpile time. Kept apart from `$locals`, which holds
     * runtime values.
     *
     * Scoped exactly like `$locals`: saved and restored around every inline, and dropped for a name a loop
     * or closure binds. It used to outlive the inline that bound it, and a rule asking two checks then read
     * the first check's literal in the second — see {@see foreachAsAny}.
     *
     * @var array<string, string>
     */
    private array $literals = [];

    /**
     * Per-process caches a helper declares mid-body, by variable name, holding what each memoises.
     *
     * `static $cache = []` followed by a keyed fill is a cache around a question, and a cache is invisible to
     * the answer — so nothing is emitted for either statement and a read of `$cache[$k]` resolves to the
     * question instead. Distinct from {@see memoisedExpression}, which recognises a cache wrapping a *whole*
     * helper; this one sits between other statements and only the reads can say what it stood for.
     *
     * Scoped exactly like `$locals` and `$literals`, for the reason those are: a name is a helper's own, and one
     * outliving its inline is how a stale value reaches the next rule.
     *
     * @var array<string, Descriptor>
     */
    private array $caches = [];

    /**
     * Whether the identifier is an expression rather than a literal, so it is emitted unquoted.
     *
     * A rule that reports under a code decided at analysis time — `"...noDebugIn{$namespace}"` — has one
     * identifier expression, not several identifiers. Quoting it would report under the source text.
     */
    private bool $identifierIsExpression = false;

    /**
     * The rule's configured constructor properties, by property name.
     *
     * Read from the rule package's own neon rather than from the constructor signature, because a signature
     * cannot say which argument is a configured value and which is a PHPStan service. See
     * {@see PackageConfiguration}. A property here becomes a constructor parameter on the generated plugin,
     * carrying the package's default so a worker that passes nothing behaves like PHPStan.
     *
     * @var array<string, array{parameter: string, default: mixed, kind: string}>
     */
    private array $configured = [];

    /**
     * Constructor properties holding a PHPStan service, by property name, mapped to the service.
     *
     * Kept apart from the configured ones so a rule reading one is refused *by the name of the service*,
     * which says what would have to be translated for the rule to work, rather than as an unknown local.
     *
     * @var array<string, string>
     */
    private array $injected = [];

    /** Whether the rule reads a configured property, so the emitted plugin needs a constructor. */
    private bool $usesConfiguration = false;

    /**
     * Constructor properties computed in the body from configured values, constants or literals.
     *
     * Translatable in principle and not translated yet, so a rule reading one is refused as a derived
     * property rather than as an unknown local. Naming what it is keeps the refusal useful.
     *
     * @var array<string, string>
     */
    private array $derived = [];

    /**
     * Constructor derivations the generated plugin can carry verbatim, by property name.
     *
     * Only for the PHP target: the emitted plugin is PHP, so a derivation over configured values, literals
     * and pure functions is the same code. Rust has no equivalent, so the Rust targets refuse it.
     *
     * @var array<string, Expr>
     */
    private array $pure = [];

    /** The Mago node kind the hook's `node` currently refers to, for FIELDS lookup. */
    private string $nodeKind = '';

    /** @var array<string, Descriptor> PHP local name -> descriptor */
    private array $locals = [];

    /** @var array<string, string> alias -> fully qualified class name, from the rule's `use` list */
    private array $useMap = [];

    private int $bindCounter = 0;

    /**
     * Set when the rule asks what the scope knew *before* this node — `hasVariableType()` and
     * friends. Such a rule has to run on the pre hook, or it sees the state the node just created.
     */
    private bool $readsPriorScope = false;

    /**
     * Constructor parameters the rule package's neon does not wire, by the rule that declares them.
     *
     * Not the same as an unconfigured *package*: the package can wire other rules and skip this one. Reading
     * such a property has to refuse by naming that, or it falls through to the generic path and refuses with
     * `unknown local $this`, which points at the receiver instead of at the missing wiring.
     *
     * @var array<string, string>
     */
    private array $unwired = [];

    /**
     * Where the emitted report points, when the rule moves it off the node the hook fired for.
     *
     * Null means the hook's own node, which is what almost every rule wants. A rule that loops a class-like's
     * members and reports per member does not: PHPStan's `->line($member->getLine())` is what puts each finding
     * on its own member, and without carrying that across, every finding in such a rule lands on the class.
     */
    private ?string $anchor = null;

    /**
     * Whether {@see $anchor} names something only a loop body has in scope.
     *
     * An anchor read from a loop item is a PHP variable the emitted `foreach` binds, so a report emitted after that
     * loop would name a variable that is not there — a wrong span at best, and nothing static would see it. Every
     * rule in the corpus reports inside the loop that anchored it; this is what keeps that true.
     */
    private bool $anchorNeedsLoop = false;

    /**
     * A local holding a built rule error whose report has not been emitted yet, inside a loop.
     *
     * Null everywhere else. The trailing report is right for a rule whose guards bail out of `analyze()`, and
     * wrong for one whose guards `continue` — there the report has to sit inside the loop, and the `return` that
     * follows the assignment is what says the rule stops at the first finding.
     */
    private ?string $pendingReport = null;

    /**
     * Integer class constants of the rule being translated, by name.
     *
     * A rule names its thresholds — `self::MAX_NESTED_FOREACHES` — and the number is what it compares against, so
     * it folds here rather than becoming something the generated plugin carries.
     *
     * @var array<string, int>
     */
    private array $intConstants = [];

    /**
     * Constructor properties holding a stateless subtree finder, by name.
     *
     * `NodeFinder` carries nothing, so a rule that injects one instead of constructing one is asking the same
     * question either way, and the property reads as the same handle `new NodeFinder()` produces.
     *
     * @var array<string, true>
     */
    private array $finders = [];

    /** True while translating a loop body, so `continue` and inline reports are legal. */
    private bool $inLoop = false;

    /**
     * How many emitted loops enclose the statement being translated.
     *
     * Counted rather than flagged because a `return null` in an inlined helper means different things in the
     * two cases. If the enclosing loop is the *caller's*, the helper produced no value for the current item
     * and the iteration ends. If the helper opened the loop itself — a sweep over arguments looking for a
     * disqualifier — then leaving it must leave the helper, which in a flattened emission means leaving the
     * rule. `inLoop` alone cannot tell those apart, and treating both as `continue` made a sweep stop
     * disqualifying anything: strictly wider, and invisible in the emitted file.
     */
    private int $loopDepth = 0;

    /** The loop depth when the innermost inlined helper was entered; see {@see $loopDepth}. */
    private int $helperLoopFloor = 0;

    /**
     * True while inlining a helper whose return value *is* the finding.
     *
     * Inside such a helper `return null` means "no finding", the same exit as a rule's `return []`, and a
     * returned `RuleErrorBuilder` chain is the report itself.
     */
    private bool $inErrorHelper = false;

    /**
     * Stands in for a Rust expression that does not exist, on a descriptor only the PHP target ever reads.
     *
     * A comment rather than a plausible identifier on purpose: if one of these ever reaches emitted Rust, the
     * generated crate fails to compile instead of quietly analysing the wrong thing.
     */
    private const string PHP_ONLY = '/* PHP target only */';

    /**
     * Fields the record producer being inlined has bound so far, or null when none is being inlined.
     *
     * A rule package factors its detection into a producer that hands back a `{...}` record and a consumer
     * that reads one field out of it. `hihaho/phpstan-rules` does exactly this so one implementation can
     * drive both an error rule and a manifest collector. The record never survives translation: the producer's
     * guards become the rule's guards, and each key becomes a transpile-time binding the consumer's argument
     * reads. So this is a map from key to descriptor, not a runtime array.
     *
     * @var RecordFields|null
     */
    private ?array $recordFields = null;

    /**
     * Conditions under which an error helper reports, collected while inlining it.
     *
     * A helper shaped `if (A) return err; if (B) return err; return null;` reports exactly when `A || B`,
     * which is one guard followed by the report the rule already appends. Collecting them rather than
     * emitting each in place keeps the emitted shape the one everything else assumes.
     *
     * Each entry carries what that branch reports, because a helper may report different things in different
     * branches — `invadeUsageError()` returns one of two findings, each with its own message and identifier.
     * Where every branch reports the same thing the emitted shape is unchanged, which is what keeps the
     * reviewed snapshots reviewed.
     *
     * @var list<array{condition: string, message: string, code: string, anchor: string}>
     */
    private array $reportConditions = [];

    /**
     * Array constants the generated plugin has to declare, because a carried derivation names them.
     *
     * The value expression rather than a resolved list: a lookup table is written `['dump' => true]`, whose
     * data is in the keys, and the derivation is copied verbatim — so the constant is printed verbatim too.
     *
     * @var array<string, Expr>
     */
    private array $carriedConstants = [];

    /** How many independent checks have reported at rule level; see {@see inlineErrorHelper()}. */
    private int $checksReported = 0;

    /**
     * Whether this rule is emitted as one private method per check.
     *
     * A merged rule asks several *independent* checks of the same node in one pass, for the dispatch
     * saving. Flattened into one body, the first check's guards become the rule's guards, so its "not my
     * case" exits the rule and every later check is unreachable. One method per check gives each check's
     * guards their own thing to return from.
     *
     * Decided before translation, and only for a rule that really asks two, so a rule with one check
     * emits exactly what it emits today.
     */
    private bool $checkMode = false;

    /**
     * The checks emitted so far, each already rendered.
     *
     * @var list<array{name: string, signature: string, body: string}>
     */
    private array $checks = [];

    /**
     * Locals holding an error an inlined helper already reported.
     *
     * A rule that checks several things in one pass writes `$e = $this->someError(..); if ($e instanceof
     * RuleError) { $errors[] = $e; }` — three statements of bookkeeping around a decision the helper already
     * made. Inlining emits the report where the helper decides, so the bookkeeping translates to nothing, and
     * knowing *which* locals those are is what makes dropping it safe rather than a guess.
     *
     * @var array<string, true>
     */
    private array $reportedErrors = [];

    /** Set once a report has been emitted inside the body; suppresses the trailing one. */
    private bool $reportedInline = false;

    /** Current emission indentation, which a loop body increases. */
    private int $indent = 8;

    /**
     * Where each `$x = []` was bound, as a line index and indent, for the accumulators that turn out to be lists.
     *
     * A rule's `$x = []` is usually a report accumulator, which emits nothing — a report is emitted where it is
     * appended. When the appended value is a node instead, the accumulator is a real list and the emitted plugin
     * needs a real `$x = []` before the loop. That declaration cannot be written when the binding is read,
     * because nothing there says which kind it will be, so the slot is remembered and the declaration spliced
     * in at the first append.
     *
     * @var array<string, array{int, int}>
     */
    private array $accumulatorSlots = [];

    /**
     * Accumulators that turned out to hold nodes rather than findings.
     *
     * Kept apart from `$locals` because a loop saves and restores those around its body, which is right for the
     * loop variable and wrong for this: the append happens inside the loop and the count is read after it, so a
     * promotion recorded in `$locals` would be discarded exactly where it is needed.
     *
     * @var array<string, true>
     */
    private array $listAccumulators = [];

    /**
     * What each list accumulator was filled with, by name.
     *
     * A list of names metadata produced is compared case-insensitively, because metadata lowercases what it
     * holds. A list the rule filled with anything else is not: folding case there would be wider than the
     * strict comparison the rule wrote. The provenance is the only thing that tells the two apart, so it is
     * recorded where the append happens rather than guessed at the comparison.
     *
     * @var array<string, string>
     */
    private array $listItemKinds = [];

    /** What the report's annotation points at; a loop reports per item, not per node. */
    private string $reportSpan = 'node.span()';

    /**
     * Whether the message and identifier last taken have been reported under.
     *
     * A rule reporting two different things about one subject takes a message, reports it, then takes another.
     * Without this the second take reads as an overwrite and is refused — and refusing it is still right when
     * no report came in between, because then the first message was never emitted anywhere.
     */
    private bool $reportTaken = false;

    /**
     * Constructor-injected objects whose class this package can read, by property name.
     *
     * A rule package puts a small analyzer on its own class and delegates to it — `$this->enumAnalyzer->
     * detect(..)`. That is the same inlining as a trait's or a parent's method, one indirection further out,
     * and without it every such helper is a vocabulary gap named after somebody's class.
     *
     * @var array<string, string>
     */
    private array $collaborators = [];

    /**
     * Constructor properties holding a package's configuration value object, by property name.
     *
     * Kept apart from {@see $collaborators} because the answer is different in kind: a collaborator's methods
     * are *inlined*, and inlining a getter of one of these reaches `$this->parameters[...]` on an object the
     * plugin does not have, which refused as `unknown local $this` — a message pointing at the receiver rather
     * than at the shape. A getter here resolves to the neon parameter it reads instead, which is what
     * {@see AggregateRule} already does for the collector-and-consumer path.
     *
     * @var array<string, ConfigurationObject>
     */
    private array $valueObjects = [];

    /**
     * Runtime helper classes the emitted plugin has to import, by class name.
     *
     * A plugin importing `Support` alone was enough while every helper lived there. A named stand-in for a
     * collaborator does not, and an import it never makes is a plugin that loads and then fails on the first
     * call — the failure mode `.ai/guidelines/verification.md` opens with.
     *
     * @var array<string, true>
     */
    private array $runtimeHelpers = [];

    /**
     * The node kinds this rule's hook registers, when it registers more than one.
     *
     * Empty for every hook that is one kind, which is all but the expression family. A branch testing
     * `instanceof MethodCall` needs to know whether that kind is among the targets: if it is, the test is a
     * node-kind test; if it is not, the branch never runs.
     *
     * @var list<string>
     */
    private array $hookKinds = [];

    /** The php-parser class the rule's `getNodeType()` names, which decides how many kinds the hook covers. */
    private string $nodeType = '';

    /** Set when the rule narrows `getOriginalNode()` to `Class_`, which the class hook guarantees. */
    private bool $narrowedToClass = false;

    /**
     * Why the guard being translated cannot hold in Mago's model, when that is known.
     *
     * A guard that translates to a constant used to be dropped with one generic comment, whatever the
     * reason. Three of those drops are sound by construction — the node the guard tests for never reaches
     * the hook — and were verified by putting the case in a rule's *good* example and watching the port
     * stay silent. Anything else translating to a constant is a hole, not a proof, so it is refused. This
     * field is what separates the two.
     */
    private ?string $unreachableGuard = null;

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

    /** The class-like whose `self::` constants are currently in scope, i.e. the one being inlined. */
    private ?ClassLike $currentClass = null;

    /**
     * The rule's own class, which `$this` means however deep an inline has gone.
     *
     * `$currentClass` is swapped to whichever trait or parent is being inlined, so a name is resolved against
     * that file's imports. But `$this` does not move: a method on one trait calling a method on a *sibling*
     * trait is ordinary PHP, and looking it up on the trait alone finds nothing — `DetectsInvadeUsage` calls
     * `namespaceStartsWith()`, which lives on `ChecksNamespace`, and both are used by the rule.
     */
    private ?ClassLike $ruleClass = null;

    /**
     * The rule's own import map, for the same reason.
     *
     * @var array<string, string>
     */
    private array $ruleUses = [];

    /**
     * The namespace the rule file declares, so a helper found in the rule's own class-like can be named
     * fully. A helper found through a trait or a parent carries its own, from {@see SourceIndex}.
     */
    private ?string $ruleNamespace = null;

    /**
     * What survey mode assumed on the way to its answer, so a refusal can say so.
     *
     * @var list<string>
     */
    private array $assumed = [];

    /**
     * How deep inlining currently is. Zero means the rule's own body, which several emission decisions read.
     */
    private int $inlineDepth = 0;

    /**
     * The helpers currently being inlined, innermost last, so one that reaches itself is refused by name.
     *
     * This replaced a flat depth cap of 4. The cap was written as a recursion guard and worked as one by
     * accident: it also refused a helper chain that merely happened to be five deep and terminated perfectly
     * well. `hihaho/phpstan-rules` v3.15.2 added one level to an existing chain — report → instanceCallFlagSite
     * → agreedFlagSite → flagRecord → isFirstPartyClass — and two rules that emitted at 3.15.1 refused with
     * "nests deeper than 4", a message about this tool's own arithmetic rather than about the rule.
     *
     * Keyed on the method name alone, not the declaring class: a chain that re-enters a same-named method of
     * another class is refused too. That is the safe direction — refusing a shape nothing has needed yet,
     * rather than following a cycle this tool cannot prove terminates.
     *
     * @var list<string>
     */
    private array $inlining = [];

    /**
     * A runaway backstop, not a shape limit: real recursion is caught by name above, so reaching this means
     * a chain long enough that the emitted expression would be unreadable anyway.
     */
    private const int INLINE_DEPTH_LIMIT = 24;

    /**
     * The functions a constructor derivation may call and still be copied verbatim.
     *
     * Closed on purpose, and every entry is a pure array or string operation with no dependency on state
     * beyond its arguments. Adding to it is adding to what the generated plugin promises to reproduce.
     *
     * @var list<string>
     */
    private const array PURE_FUNCTIONS = [
        'array_combine', 'array_fill_keys', 'array_filter', 'array_flip', 'array_keys', 'array_map',
        'array_merge', 'array_unique', 'array_values', 'count', 'explode', 'implode', 'in_array',
        'ltrim', 'rtrim', 'sprintf', 'str_replace', 'strtolower', 'strtoupper', 'trim',
    ];

    private readonly SourceIndex $index;

    public function __construct(private readonly string $file)
    {
        $this->backend = self::$target === 'php' ? new PhpBackend() : new RustBackend();
        $this->index = new SourceIndex();
    }

    /**
     * @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>} the emitted rule, plus what the caller needs to register and attribute it
     */
    /**
     * What this rule would emit, or a refusal naming what stops it.
     *
     * In survey mode two checks are deliberately relaxed so the report can say what a rule needs *in total*
     * rather than stopping at its first structural blocker: a node type with no hook is assumed to have one,
     * and a property with no field mapping is assumed to resolve. Both are useful and both were silent, and a
     * silent assumption is how a ranking goes wrong: on 143 vendored rules, 25 named a different first
     * obstacle here than an emit run does, and every one of those was a body-level gap sitting *behind* a
     * blocker the survey had walked past. Closing such a gap moves nothing.
     *
     * So the assumptions travel with the answer. A refusal raised under one says which one.
     *
     * @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>} the emitted rule, plus what the caller needs to register and attribute it
     */
    public function transpile(): array
    {
        try {
            return $this->translate();
        } catch (Refusal $refusal) {
            throw $this->assumed === []
                ? $refusal
                : new Refusal($refusal->getMessage() . ', assuming ' . implode(' and ', $this->assumed));
        }
    }

    /** @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>} */
    private function translate(): array
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
        $this->ruleClass = $class;
        $this->ruleUses = $this->useMap;
        $this->ruleNamespace = SourceIndex::namespaceOf($ast, $className);
        $this->collectConstants($class);

        // A collector-and-consumer pair has no per-file body to translate, so it is recognised and re-emitted
        // rather than walked. Checked before anything else reads the body, because reading it would refuse on
        // constructs that are beside the point for this shape.
        if (self::$target === 'php' && $this->implementsCollector($class) && AggregateRule::onlyFeedsAWriter($this->file)) {
            throw new Refusal(
                'every rule that consumes this collector reports nothing and writes a file instead, so the '
                . 'pair cannot become a plugin whatever the collector body does',
            );
        }

        if (self::$target === 'php') {
            $aggregate = AggregateRule::from($class, $this->file, PackageConfiguration::forRuleFile($this->file));
            if ($aggregate instanceof AggregateRule) {
                return $this->emitAggregate($className, $aggregate);
            }
        }

        $this->collectConfiguration($class, $this->qualified($className, $ast));
        $this->isCollector = $this->implementsCollector($class);
        $this->collectorName = $className;
        $nodeType = $this->findNodeType($class);
        if (! isset(Vocabulary::HOOKS[$nodeType])) {
            if (! self::$survey) {
                // A rule that names an *abstract* php-parser class asks PHPStan for every node under it and
                // then branches on the concrete ones — `NoDynamicNameRule` says so in a comment, calling
                // `return Expr::class` "a trick to allow multiple node types". That is not a missing table row.
                // Mago's hooks are per node kind and a plugin may register several, so the shape is reachable,
                // but the rule's `instanceof` branches would each have to *rebind* which kind the body is
                // reading: `$node->name` means a different child under `MethodCall` than under
                // `ClassConstantAccess`, and the field table is keyed by kind. Naming it here so the refusal
                // says which change it wants rather than reading as an omission.
                if (in_array($nodeType, self::MULTI_KIND_NODE_TYPES, true)) {
                    throw new Refusal(
                        "{$nodeType} covers several node kinds, so the rule branches on the concrete one. A "
                        . 'plugin can register several targets, but each branch would have to rebind which kind '
                        . 'the body reads, and the field table is keyed by one kind per rule',
                    );
                }

                throw new Refusal("no hook mapping for node type $nodeType");
            }

            // Survey: assume the hook exists, to see what the body would need.
            //
            // The assumption has to travel with the answer. On 143 vendored rules, 25 reported a different
            // first obstacle here than an emit run does, and in every one of those the emit run named a
            // missing hook while the survey named something in the body *behind* it. A ranking built from
            // survey output then counts body-level gaps for rules that no body-level fix can move: closing
            // `unknown local $this` for `FetchingDeprecatedConstRule` buys nothing while `Expr_ConstFetch`
            // has no hook. So a refusal raised under the assumption says it was raised under it.
            $short = substr($nodeType, (int) strrpos('\\' . $nodeType, '\\'));

            $hook = ['trait' => 'SurveyHook', 'method' => 'survey', 'node' => $short, 'kind' => $short];
            $this->nodeKind = $short;
            $processNode = $this->findMethod($class, 'processNode');
            $this->assume("a hook for {$nodeType}");
            foreach ($processNode->stmts ?? [] as $stmt) {
                $this->translateStatement($stmt);
            }

            return [
                'name' => $className,
                'trait' => $hook['trait'],
                'arguments' => [],
                'node' => $short,
                'kind' => $hook['kind'],
                'module' => $this->snake($className),
                'rust' => '',
                'identifier' => $this->identifier,
                'identifiers' => array_values(array_unique($this->identifiers)),
                'messages' => [],
            ];
        }

        $hook = Vocabulary::HOOKS[$nodeType];

        // A hook Mago answers only through the PHP SDK. Refused by name rather than registered against a
        // guessed Rust trait, which `ModuleEmitter` would turn into a crash instead of a refusal.
        if (($hook['phpOnly'] ?? false) && self::$target !== 'php') {
            throw new Refusal("a {$hook['kind']} hook, which only the PHP target carries");
        }

        $this->nodeKind = $hook['kind'];
        $this->classFrom = $hook['classFrom'] ?? 'scope';

        $this->nodeType = $nodeType;
        $this->hookKinds = $this->targetKinds($hook);
        if (count($this->hookKinds) < 2) {
            $this->hookKinds = [];
        }

        $processNode = $this->findMethod($class, 'processNode');
        $this->checkMode = self::$target === 'php' && $this->independentChecks($processNode) >= 2;
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
            // Every identifier, not only the last: a harness comparing the two tools has to look for all of
            // them, or a merged rule's other checks pass by being ignored rather than by agreeing.
            'identifiers' => array_values(array_unique($this->identifiers)),
            // The configured values the generated plugin carries as constructor defaults, read from the
            // package's own neon. Handed back so a harness can register the *original* rule with the same
            // values: a rule whose two sides are configured differently is not a comparison.
            'arguments' => array_map(static fn (array $configured): mixed => $configured['default'], $this->configured),
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
    /**
     * A member name written literally.
     *
     * `$object->$method()` and `Foo::{$name}` put an expression here rather than a name, and casting
     * that to string is a TypeError rather than a useful answer. The vocabulary has nothing to say
     * about a dynamic name, so this refuses instead of crashing.
     */
    private function memberName(Node|string $name, int $line): string
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
    /**
     * The rule's fully qualified name, which is how the package's neon wires it.
     *
     * @param Stmt[] $ast
     */
    private function qualified(string $className, array $ast): string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name instanceof Name) {
                return $stmt->name->toString() . '\\' . $className;
            }
        }

        return $className;
    }

    /**
     * @param Stmt[] $ast
     */
    private function collectUses(array $ast): void
    {
        $this->useMap = [...$this->useMap, ...Uses::collect($ast)];
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

    private function declaresMethod(ClassLike $class, string $name): bool
    {
        foreach ($class->getMethods() as $method) {
            if ((string) $method->name === $name) {
                return true;
            }
        }

        return false;
    }

    private function findMethod(ClassLike $class, string $name): ClassMethod
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
    /**
     * The plugin for a collector-and-consumer pair: one after-analysis hook over the whole project.
     *
     * The counting lives in {@see TypeCoverage}, whose numbers were
     * brought into agreement with the original rule on a real project. This is only the shape around it: the
     * configured threshold as a constructor default, the rule's own message and code, and one finding per
     * declaration that is missing a type, anchored where the declaration is.
     *
     * @return array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>}
     */
    private function emitAggregate(string $className, AggregateRule $aggregate): array
    {
        $identifier = 'transpiled/' . str_replace('_', '-', $this->snake($className));
        $threshold = rtrim(rtrim(sprintf('%.1f', $aggregate->default), '0'), '.');
        $message = $this->backend->bytes($aggregate->message);
        $code = $this->backend->bytes($aggregate->identifier);
        $metric = $aggregate->metric;

        $template = <<<'PHP'
<?php

declare(strict_types=1);

// GENERATED by phpstan-to-mago from {CLASS}. Do not edit by hand.

namespace Transpiled;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Sandermuller\PhpstanToMago\Runtime\TypeCoverage;

final class {CLASS} implements AfterAnalysisHook, Plugin
{
    public function __construct(public readonly float $required = {THRESHOLD}) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: {PLUGIN},
            name: {NAME},
            description: {DESCRIPTION},
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook($this);
    }

    /** @return list<never> */
    public function getTargets(): array
    {
        return [];
    }

    /** @return list<never> */
    public function getRequirements(): array
    {
        return [];
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $coverage = TypeCoverage::{METRIC}($context);
        if ($coverage->total === 0 || $coverage->percentage() >= $this->required) {
            return;
        }

        $message = sprintf(
            {MESSAGE},
            $coverage->total,
            $coverage->typed,
            $coverage->percentage(),
            $this->required,
        );

        foreach ($coverage->missing as $location) {
            $context->report(Level::Error, {CODE}, Issue::at($message, $location));
        }
    }
}
PHP;

        $rust = strtr($template, [
            '{CLASS}' => $className,
            '{THRESHOLD}' => $threshold,
            '{PLUGIN}' => $this->backend->bytes($identifier),
            '{NAME}' => $this->backend->bytes($className),
            '{DESCRIPTION}' => $this->backend->bytes("Transpiled from PHPStan's {$className}."),
            '{METRIC}' => $metric,
            '{MESSAGE}' => $message,
            '{CODE}' => $code,
        ]);

        return [
            'name' => $className,
            'trait' => 'AnalysisHook',
            'node' => null,
            'kind' => 'CollectedData',
            'module' => $this->snake($className),
            'rust' => $rust,
            'arguments' => [],
            'identifier' => $aggregate->identifier,
            'identifiers' => [$aggregate->identifier],
            'messages' => [$aggregate->message],
        ];
    }

    /**
     * Sorts the rule's constructor properties into configured values and PHPStan services.
     *
     * A property is only configured if the package's neon says so. When the package declares no neon there
     * is nothing to read, so a constructor property stays unknown and the rule is refused when it reads
     * one — which is the honest outcome: a default nobody declared would be a guess.
     */
    private function collectConfiguration(ClassLike $class, string $className): void
    {
        $constructor = $class->getMethod('__construct');
        if (! $constructor instanceof ClassMethod) {
            return;
        }

        // A declared service type is evidence on its own, so it is read whether or not the package ships a
        // neon. Without this, a rule in a package that wires nothing had every constructor property come back
        // as an unknown local, which named neither the obstacle nor what to do about it.
        $configuration = PackageConfiguration::forRuleFile($this->file);
        $wired = $configuration instanceof PackageConfiguration ? $configuration->argumentsFor($className) : [];
        foreach ($constructor->getParams() as $position => $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                continue;
            }

            // Matched by name where the neon names the argument, by position where it wires positionally.
            $name = $param->var->name;
            $argument = null;
            foreach ($wired as $index => $candidate) {
                if ($candidate['name'] === $name || $candidate['name'] === (string) $index && $index === $position) {
                    $argument = $candidate;

                    break;
                }
            }

            if ($argument === null) {
                // php-parser's `NodeFinder` is stateless, so a rule that takes one in its constructor is not
                // carrying configuration and not holding a PHPStan service — it just did not want to write `new`
                // twice. Classified here or the property read refuses as an unwired configured value, which points
                // at the package's neon for something the neon has no business wiring.
                if ($param->type instanceof Name && $param->type->getLast() === 'NodeFinder') {
                    $this->finders[$name] = true;

                    continue;
                }

                // The package may not wire this rule at all. A declared type is still evidence: nothing but a
                // PHPStan service is spelled `ReflectionProvider`, and classifying it says what would have to
                // be translated instead of calling the property unknown.
                $service = $this->serviceTypeOf($param);
                if ($service !== null) {
                    $this->injected[$name] = $service;

                    continue;
                }

                if ($this->takeOwnObject($name, $param, $configuration)) {
                    continue;
                }

                // A constructor parameter the neon does not wire and whose type names no service. Recorded so
                // that reading it refuses by naming *that* — `hihaho/phpstan-rules` registers only the
                // constructor and nullsafe variants of its positional-flag family, and the two it leaves to a
                // combined rule refused with `unknown local $this`, which points at nothing.
                $this->unwired[$name] = $className;

                continue;
            }

            if ($argument['kind'] === 'service') {
                $this->injected[$name] = $argument['reference'];

                continue;
            }

            $default = $configuration instanceof PackageConfiguration && $configuration->hasParameter($argument['reference'])
                ? $configuration->defaultFor($argument['reference'])
                : $argument['reference'];

            $this->configured[$name] = [
                'parameter' => $argument['reference'],
                'default' => $default,
                'kind' => $this->configKind($default),
            ];
        }

        $this->traceConstructorBody($constructor);
    }

    /**
     * Follows what the constructor body derives, so a derived property is refused for the right reason.
     *
     * Every rule in the corpus that has a constructor also has a body. Some derive a lookup table from
     * configured values and constants, which is translatable; one resolves a class through
     * `ReflectionProvider` and stores the reflection, which is not. Both used to read back as
     * `unknown local $this`, which named neither. Tracing the assignments lets the refusal say which it is.
     */
    private function traceConstructorBody(ClassMethod $constructor): void
    {
        foreach ($constructor->stmts ?? [] as $statement) {
            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                continue;
            }

            $target = $statement->expr->var;
            if (! $target instanceof PropertyFetch
                || ! $target->var instanceof Variable
                || $target->var->name !== 'this'
            ) {
                continue;
            }

            $property = $this->memberName($target->name, $statement->getStartLine());

            // `$this->nodeFinder = new NodeFinder();` — a stateless helper the rule constructs once rather than
            // per call. Not a derivation of anything: the same handle `new NodeFinder()` produces inline.
            $constructed = $statement->expr->expr;
            if ($constructed instanceof New_
                && $constructed->class instanceof Name
                && $constructed->class->getLast() === 'NodeFinder'
            ) {
                $this->finders[$property] = true;

                continue;
            }

            $service = $this->serviceBehind($statement->expr->expr);
            if ($service !== null) {
                $this->injected[$property] = $service;

                continue;
            }

            if ($this->isPureDerivation($statement->expr->expr)) {
                $this->pure[$property] = $statement->expr->expr;

                continue;
            }

            // A pass that only validates the configured names and rewrites them to their declared spelling.
            // The plugin carries the configured map itself and folds case where it compares — see
            // {@see canonicalisingPass()} for why that is the same question rather than an approximation.
            $aliased = $this->canonicalisingPass($statement->expr->expr);
            if ($aliased !== null) {
                // Through `foldedKeys()`, not as the raw value: the pass being dropped keyed its map by each
                // name's declared spelling, so two configured keys naming one trait in different cases became a
                // single entry. Carrying the map as written kept both, and the case-insensitive match at the use
                // site then found both — the same finding reported twice.
                $this->pure[$property] = new StaticCall(
                    new Name('Support'),
                    'foldedKeys',
                    [new Arg(new Variable($aliased))],
                );

                continue;
            }

            // Two different obstacles, and the refusal has to say which. Either the package wires nothing
            // for this rule, so there is no configured value to derive from, or it wires values and the
            // derivation still reaches outside the pure set. Only the second is a transpiler gap.
            $this->derived[$property] = $this->configured === []
                ? 'the package wires no configured values for this rule, so there is nothing to derive from'
                : 'the derivation reaches outside the set the generated constructor can carry';
        }
    }

    /**
     * Refuses a call whose receiver is a property holding a PHPStan service, naming the service.
     *
     * The method is a symptom; the service behind it is what would have to be translated onto Mago's
     * codebase. Saying `->hasClass() is outside the vocabulary` points at the wrong thing to fix.
     */
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
            if (self::$target !== 'php') {
                throw new Refusal('a function-existence question, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->backend->call('function_exists', [
                '$context',
                $this->nameText($this->resolve($args[0]->value, $expr->getStartLine()), $expr->getStartLine()),
            ]);
        }

        if ($method === 'hasClass' && count($args) === 1) {
            // The name can be a literal — `hasClass(Foo::class)` — or a name read out of the analysed file by
            // `$scope->resolveName()`. The second is what the positional-flag rules do, and it is the whole
            // point of asking: the class under analysis is not known at transpile time.
            $named = $this->resolvedNameArgument($args[0]->value, $expr->getStartLine());
            if ($named !== null) {
                return $this->backend->call('class_exists', ['$context', $named]);
            }

            $literal = $this->classLiteral($args[0]->value, $expr->getStartLine());

            return $this->backend->call('class_exists', self::$target === 'php'
                ? ['$context', $this->backend->bytes($literal)]
                : ['context', $this->backend->bytes($literal)]);
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
        if (self::$target !== 'php') {
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

            return ['rust' => $this->backend->bytes($literal), 'kind' => 'bytes', 'php' => $this->backend->bytes($literal)];
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
                'php' => $this->backend->call('text_of', [$this->operand($subject)]),
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
        if (! isset($this->injected[$property])) {
            return;
        }

        throw new Refusal(
            "\${$property} holds the PHPStan service {$this->injected[$property]}, so ->{$method}() has to "
            . "be translated onto Mago's codebase instead",
            $expr->getStartLine(),
        );
    }

    /**
     * The generated plugin's constructor, or nothing when the rule reads no configured value.
     *
     * Each parameter carries the rule package's own default, so a worker that constructs the plugin with no
     * arguments behaves like PHPStan at package defaults. The consumer overrides by passing values in its
     * worker — from `[extension-hosts.<name>.environment]` or argv — which is what keeps the generated file
     * free of any one project's configuration.
     */
    private function emitConstructor(): string
    {
        if (! $this->usesConfiguration) {
            return '';
        }

        $derived = [];
        $assignments = [];
        foreach ($this->pure as $property => $expression) {
            $derived[] = '    private readonly array $' . $property . ';';
            $assignments[] = '        $this->' . $property . ' = '
                . (new Standard(['shortArraySyntax' => true]))->prettyPrintExpr($expression) . ';';
        }

        $parameters = [];
        foreach ($this->configured as $property => $configured) {
            $type = match ($configured['kind']) {
                'config-list' => 'array',
                'config-bool' => 'bool',
                'config-number' => is_float($configured['default']) ? 'float' : 'int',
                default => 'string',
            };

            $parameters[] = '        public readonly ' . $type . ' $' . $property
                . ' = ' . $this->phpDefault($configured['default']) . ',';
        }

        // A derived property is assigned in the body, from the parameters above. The rule's own parameter
        // names are kept, which is what lets the derivation be copied rather than rewritten.
        $body = $assignments === [] ? ' {}' : " {\n" . implode("\n", $assignments) . "\n    }";

        // A constant the derivation names, declared here so the copy has something to refer to. Written with
        // the rule's own name and values, because the derivation is copied verbatim.
        $constants = [];
        foreach ($this->carriedConstants as $name => $value) {
            $constants[] = '    /** Carried from the rule, whose derivation names it. */';
            $constants[] = '    private const array ' . $name . ' = '
                . (new Standard(['shortArraySyntax' => true]))->prettyPrintExpr($value) . ';';
            $constants[] = '';
        }

        $properties = implode("\n", $constants) . ($derived === [] ? '' : implode("\n", $derived) . "\n");
        // A rule may derive a property without taking any configured value, and an empty parameter list read
        // as a formatting accident rather than as "this takes nothing".
        $signature = $parameters === []
            ? '    public function __construct()'
            : "    public function __construct(\n" . implode("\n", $parameters) . "\n    )";

        return "\n" . $properties . "\n" . $signature . $body . "\n";
    }

    /** A configured default, written as the PHP literal the generated constructor defaults to. */
    private function phpDefault(mixed $default): string
    {
        if (is_array($default)) {
            $items = [];
            foreach ($default as $item) {
                $items[] = $this->phpDefault($item);
            }

            return '[' . implode(', ', $items) . ']';
        }

        return match (true) {
            is_bool($default) => $default ? 'true' : 'false',
            is_int($default), is_float($default) => (string) $default,
            is_string($default) => $this->backend->bytes($default),
            default => 'null',
        };
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

            if (self::$target !== 'php') {
                throw new Refusal('in_array() over a computed list, which only the PHP target carries', $line);
            }

            return $this->backend->call('names_contain', [
                $this->operand($haystack),
                $this->nameText($this->resolve($args[0]->value, $line), $line),
            ]);
        }

        $options = $this->stringList($args[1]->value, $line);
        if (! $strict) {
            $this->refuseLooseUnlessItAgreesWithStrict($options, $line);
        }

        return $this->oneOf($args[0]->value, $options, 'in_array()', $line);
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
    private function oneOf(Expr $subjectExpr, array $options, string $asked, int $line): string
    {
        $subject = $this->resolve($subjectExpr, $line);
        $list = $this->byteSliceList($options);

        return match ($subject['kind']) {
            'local-name' => "support::local_name_is_one_of({$subject['rust']}, &{$list})",
            'name-selector' => $this->backend->call('selector_is_one_of', [$this->operand($subject), self::$target === 'php' ? $list : '&' . $list]),
            'name-expr' => $this->nameExprIsOneOf($subject, $list),
            'extends' => "support::extends_is_one_of(context, node, &{$list})",
            'bytes', 'class-name' => $this->backend->call('bytes_is_one_of', [$this->operand($subject), self::$target === 'php' ? $list : '&' . $list]),
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
     * - Every element is a written string literal. {@see stringList} accepts nothing else.
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
        if (self::$target !== 'php') {
            return "support::name_is_one_of({$subject['rust']}, &{$list})";
        }

        return $this->backend->call('bytes_is_one_of', [
            $this->backend->call('text_of', [$this->operand($subject)]),
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
            && ($this->locals[$table->name]['kind'] ?? null) === 'captures'
        ) {
            return $this->capturedGroup(
                $this->locals[$table->name],
                $this->stringLiteral($fetch->dim, $expr->getStartLine()),
                $expr->getStartLine(),
            ) . ' !== null';
        }

        // A lookup table the constructor built — from configured values, a class constant and literals — is a
        // property on the generated plugin, so membership in it is an array read at analysis time rather than a
        // set known at transpile time.
        if ($table instanceof PropertyFetch && $table->var instanceof Variable && $table->var->name === 'this') {
            if (self::$target !== 'php') {
                throw new Refusal('isset() over a constructed lookup table, which only the PHP target carries', $expr->getStartLine());
            }

            $property = $this->resolve($table, $expr->getStartLine());

            return 'isset(' . $this->operand($property) . '[' . $this->stringValue($fetch->dim, $expr->getStartLine()) . '])';
        }

        // The same table reached through a parameter: a helper takes `$this->unsafeMethodsLookup` as an
        // argument, so inside it the lookup is a local. Still the plugin's own property at runtime.
        if ($table instanceof Variable && is_string($table->name) && isset($this->locals[$table->name])) {
            if (self::$target !== 'php') {
                throw new Refusal('isset() over a lookup table passed to a helper, which only the PHP target carries', $expr->getStartLine());
            }

            $bound = $this->locals[$table->name];
            if (in_array($bound['kind'], ['lookup', 'config-list'], true)) {
                return 'isset(' . $this->operand($bound) . '[' . $this->stringValue($fetch->dim, $expr->getStartLine()) . '])';
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
        if (! isset($this->constantKeys[$name])) {
            throw new Refusal("isset() over self::{$name}, which is not a constant map of string keys", $expr->getStartLine());
        }

        return $this->oneOf($fetch->dim, $this->constantKeys[$name], 'isset()', $expr->getStartLine());
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

        return self::$target === 'php'
            ? $this->operand($first) . ' === ' . $this->operand($second)
            : $this->operand($first) . ' == ' . $this->operand($second);
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
            && in_array($this->nodeKind, self::HOOK_KINDS_ALWAYS_IN_A_CLASS, true)
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

        if (! in_array($subject['kind'], ['bytes', 'class-name'], true)) {
            throw new Refusal("null comparison against a {$subject['kind']}", $line);
        }

        return self::$target === 'php'
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
    private function resolvePropertyDeclaration(array $base, string $property, string $key): ?array
    {
        if ($base['kind'] === 'property-item' && $property === 'name') {
            return [
                'rust' => "support::property_item_name({$base['rust']})",
                'kind' => 'bytes',
                'key' => $key,
                'php' => $this->backend->call('property_item_name', [$this->operand($base)]),
            ];
        }

        if ($base['kind'] === 'hook-node' && $property === 'props') {
            return [
                'rust' => 'support::property_items(context, node)',
                'kind' => 'property-items',
                'key' => $key,
                'php' => $this->backend->call('property_items', ['$context', '$node']),
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

        if (self::$target !== 'php') {
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

        if (isset($this->injected[$property])) {
            throw new Refusal(
                "\${$property} holds the PHPStan service {$this->injected[$property]}, which has no "
                . 'injectable equivalent; its uses have to be translated instead',
                $line,
            );
        }

        if (isset($this->finders[$property])) {
            return ['rust' => self::PHP_ONLY, 'kind' => 'node-finder', 'key' => $key, 'php' => self::PHP_ONLY];
        }

        if (isset($this->pure[$property])) {
            if (self::$target !== 'php') {
                throw new Refusal(
                    "\${$property} is derived in the constructor, which only the PHP target can carry",
                    $line,
                );
            }

            $this->usesConfiguration = true;

            return [
                'rust' => 'self.' . $this->snake($property),
                'kind' => 'config-list',
                'key' => $key,
                'php' => '$this->' . $property,
            ];
        }

        if (isset($this->derived[$property])) {
            throw new Refusal(
                "\${$property} is computed in the constructor and {$this->derived[$property]}",
                $line,
            );
        }

        if (isset($this->unwired[$property])) {
            throw new Refusal(
                "\${$property} is a constructor parameter the package's neon does not wire for "
                . "{$this->unwired[$property]}, and its type names no PHPStan service, so there is no value for "
                . 'the generated plugin to carry',
                $line,
            );
        }

        if (! isset($this->configured[$property])) {
            return null;
        }

        $this->usesConfiguration = true;

        return [
            'rust' => 'self.' . $this->snake($property),
            'kind' => $this->configured[$property]['kind'],
            'key' => $key,
            'php' => '$this->' . $property,
        ];
    }

    /**
     * The PHPStan service a constructor parameter's declared type names, if it names one.
     *
     * Only for a rule the package's neon does not wire, where the type is the only evidence there is. Kept to
     * the services the corpus actually injects rather than guessing from any unknown class: an unrecognised
     * type stays unknown, which is the honest answer.
     */
    private function serviceTypeOf(Param $param): ?string
    {
        $type = $param->type;
        if (! $type instanceof Name) {
            return null;
        }

        $short = $type->getLast();

        return match ($short) {
            'ReflectionProvider' => 'reflectionProvider',
            'Parser' => 'parser',
            'RuleLevelHelper' => 'ruleLevelHelper',
            default => null,
        };
    }

    /**
     * Whether a constructor derivation touches nothing but configured values, literals and pure functions.
     *
     * The PHP target can carry such a derivation verbatim: the generated plugin is PHP, the rule's own
     * constructor parameters are in scope there under the same names, and every function below is a pure
     * array or string operation. That is a copy, not an approximation — which is why the allowlist is closed
     * and anything else is refused. A method call, a `$this->` read, a `new`, a class constant, or a function
     * nobody vouched for all make it impure: any of them could depend on state the plugin does not have, and a
     * class constant provably does — the generated plugin carries no constants.
     */
    private function isPureDerivation(Expr $expr): bool
    {
        foreach ((new NodeFinder())->find([$expr], static fn (Node $node): bool => true) as $node) {
            if (! $this->isPureNode($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The configured value a constructor derivation is a canonicalising pass over, or null.
     *
     * `TraitRequiresInterfaceRule` derives its trait => interface map by walking the configured one, throwing
     * on a name that is not an existing trait or interface, and storing each name as the reflection reports
     * it. Neither half survives translation, and neither needs to:
     *
     * - The validation throws at construction for a misconfigured pair. A plugin that does not throw is not
     *   wider in what it *reports*: a trait that does not exist is used by no class, so the pair matches
     *   nothing either way.
     * - The rewriting exists so a configured name spelled in a different case still matches. Mago's metadata
     *   lowercases every name it holds, so a case-insensitive comparison at the use site asks exactly that
     *   question. Probed, not assumed — `usedTraits` came back lowercased, and transitive.
     *
     * So the property is an alias for the configured value, recorded as a derivation whose expression *is*
     * that value. Everything downstream then works unchanged: the constructor assigns it, and a read of it
     * resolves like any other carried configuration.
     *
     * One divergence, and it is the configured spelling that causes it: where a configured name differs in
     * case from the declaration, the original's message names the declared spelling and the port names the
     * configured one. Mago's class store answers nothing for a trait name, so there is no declared spelling
     * to recover at the use site.
     */
    private function canonicalisingPass(Expr $expr): ?string
    {
        if (! $this->isOwnMethodCall($expr) || count($expr->getArgs()) !== 1) {
            return null;
        }

        $over = $expr->getArgs()[0]->value;
        if (! $over instanceof Variable || ! is_string($over->name) || ! isset($this->configured[$over->name])) {
            return null;
        }

        $declaring = $this->declaringOf($expr->name->toString());
        if ($declaring === null) {
            return null;
        }

        return $this->rewritesNamesOnly($this->findMethod($declaring['class'], $expr->name->toString()), $over->name)
            ? $over->name
            : null;
    }

    /**
     * Whether a helper walks one map and builds another holding the same names, and does nothing else.
     *
     * `$out = []; foreach ($configured as $k => $v) { <guards that throw> $out[<k>] = <v>; } return $out;`
     * where each side of the assignment is the loop variable itself or that variable put through the
     * reflection lookup that yields its declared spelling.
     */
    private function rewritesNamesOnly(ClassMethod $helper, string $parameter): bool
    {
        $statements = $helper->stmts ?? [];
        if (count($statements) !== 3
            || ! $statements[0] instanceof Expression
            || ! $statements[0]->expr instanceof Assign
            || ! $statements[0]->expr->var instanceof Variable
            || ! is_string($statements[0]->expr->var->name)
            || ! $statements[0]->expr->expr instanceof Array_
            || $statements[0]->expr->expr->items !== []
            || ! $statements[1] instanceof Foreach_
            || ! $statements[2] instanceof Return_
        ) {
            return false;
        }

        $built = $statements[0]->expr->var->name;
        [, $loop, $return] = $statements;

        if (! $return->expr instanceof Variable || $return->expr->name !== $built) {
            return false;
        }

        if (! $loop->expr instanceof Variable
            || $loop->expr->name !== $parameter
            || ! $loop->keyVar instanceof Variable
            || ! is_string($loop->keyVar->name)
            || ! $loop->valueVar instanceof Variable
            || ! is_string($loop->valueVar->name)
        ) {
            return false;
        }

        return $this->storesBothNames($loop->stmts, $built, $loop->keyVar->name, $loop->valueVar->name);
    }

    /**
     * The loop body: guards that only throw, then one assignment carrying both names into the built map.
     *
     * @param array<Stmt> $body
     */
    private function storesBothNames(array $body, string $built, string $key, string $value): bool
    {
        $stored = null;
        foreach ($body as $statement) {
            if ($statement instanceof If_ && $this->throwsOnly($statement)) {
                continue;
            }

            if ($stored instanceof Assign || ! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                return false;
            }

            $stored = $statement->expr;
        }

        if (! $stored instanceof Assign
            || ! $stored->var instanceof ArrayDimFetch
            || ! $stored->var->var instanceof Variable
            || $stored->var->var->name !== $built
            || ! $stored->var->dim instanceof Expr
        ) {
            return false;
        }

        return $this->isDeclaredSpellingOf($stored->var->dim, $key)
            && $this->isDeclaredSpellingOf($stored->expr, $value);
    }

    /** A guard whose only job is to reject a misconfiguration, which the generated plugin does not carry. */
    private function throwsOnly(If_ $guard): bool
    {
        return $guard->elseifs === []
            && ! $guard->else instanceof Else_
            && count($guard->stmts) === 1
            && $guard->stmts[0] instanceof Expression
            && $guard->stmts[0]->expr instanceof Throw_;
    }

    /** `$name`, or the reflection lookup that rewrites `$name` to the spelling its declaration uses. */
    private function isDeclaredSpellingOf(Expr $expr, string $name): bool
    {
        if ($expr instanceof Variable && $expr->name === $name) {
            return true;
        }

        if (! $expr instanceof MethodCall
            || $this->memberName($expr->name, $expr->getStartLine()) !== 'getName'
            || ! $expr->var instanceof MethodCall
            || $this->memberName($expr->var->name, $expr->var->getStartLine()) !== 'getClass'
            || count($expr->var->getArgs()) !== 1
        ) {
            return false;
        }

        $argument = $expr->var->getArgs()[0]->value;

        return $argument instanceof Variable
            && $argument->name === $name
            && $this->serviceBehind($expr->var->var) !== null;
    }

    /** One node of a derivation, judged on its own; see {@see isPureDerivation()} for what that means. */
    private function isPureNode(Node $node): bool
    {
        if ($node instanceof MethodCall || $node instanceof StaticCall || $node instanceof New_) {
            return false;
        }

        // `$this->earlier` where `earlier` is a property this same constructor already derived: the generated
        // constructor assigns them in order, so the copy reads exactly what the original read. Any other
        // property read is state the plugin does not have.
        if ($node instanceof PropertyFetch) {
            return $node->var instanceof Variable
                && $node->var->name === 'this'
                && isset($this->pure[$this->memberName($node->name, $node->getStartLine())]);
        }

        // A class constant used to make a derivation impure, because the generated plugin carried no constants
        // and copying the derivation emitted a reference to nothing. The plugin can carry it instead, so the
        // constant is *taken* here and declared alongside the constructor.
        if ($node instanceof ClassConstFetch) {
            return $this->takeDerivedConstant($node);
        }

        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && in_array($node->name->toString(), self::PURE_FUNCTIONS, true);
        }

        // `$this` is not a value the derivation reads, it is the receiver of a property read judged above.
        if ($node instanceof Variable && $node->name === 'this') {
            return true;
        }

        return ! $node instanceof Variable || ! is_string($node->name) || isset($this->configured[$node->name]);
    }

    /**
     * Records a class constant a derivation reads, so the generated plugin can declare it.
     *
     * Only `self::` or `static::` naming an array constant this class or its hierarchy declares: a constant on
     * *another* class is that class's business, and a scalar has not been needed. False leaves the derivation
     * impure, which is the safe answer.
     */
    private function takeDerivedConstant(ClassConstFetch $fetch): bool
    {
        if (self::$target !== 'php'
            || ! $fetch->class instanceof Name
            || ! in_array($fetch->class->toString(), ['self', 'static'], true)
            || ! $fetch->name instanceof Identifier
        ) {
            return false;
        }

        $name = $fetch->name->toString();
        $value = $this->currentClass instanceof ClassLike ? $this->constantValue($this->currentClass, $name) : null;
        if (! $value instanceof Array_) {
            return false;
        }

        $this->carriedConstants[$name] = $value;

        return true;
    }

    /** A constant's value expression, looked up the way `self::` resolves it: this class, then its ancestry. */
    private function constantValue(ClassLike $class, string $name): ?Expr
    {
        foreach ($this->hierarchy()->selfAndAncestors($class) as $declaring) {
            foreach ($declaring->getConstants() as $const) {
                foreach ($const->consts as $candidate) {
                    if ($candidate->name->toString() === $name) {
                        return $candidate->value;
                    }
                }
            }
        }

        return null;
    }

    /** The PHPStan service an expression reaches for, if any. */
    private function serviceBehind(Expr $expr): ?string
    {
        foreach ((new NodeFinder())->findInstanceOf([$expr], Variable::class) as $variable) {
            if (is_string($variable->name) && isset($this->injected[$variable->name])) {
                return $this->injected[$variable->name];
            }
        }

        foreach ((new NodeFinder())->findInstanceOf([$expr], PropertyFetch::class) as $fetch) {
            if ($fetch->var instanceof Variable && $fetch->var->name === 'this') {
                $name = $this->memberName($fetch->name, $expr->getStartLine());
                if (isset($this->injected[$name])) {
                    return $this->injected[$name];
                }
            }
        }

        return null;
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
            $this->constantKeys[$name] = $keys;
        }
    }

    private function collectConstants(ClassLike $class): void
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
                    $this->constants[(string) $c->name] = $c->value->value;

                    continue;
                }

                if ($c->value instanceof Int_) {
                    $this->intConstants[(string) $c->name] = $c->value->value;

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
            if (self::$target !== 'php') {
                throw new Refusal('an interpolated message, which only the PHP target carries', $expr->getStartLine());
            }

            $parts = [];
            foreach ($expr->parts as $part) {
                if ($part instanceof InterpolatedStringPart) {
                    $parts[] = $this->backend->bytes($part->value);

                    continue;
                }

                if (! $part instanceof Expr) {
                    throw new Refusal('a message interpolates something that is not an expression', $expr->getStartLine());
                }

                $parts[] = $this->stringValue($part, $expr->getStartLine());
            }

            $this->messageIsExpression = true;

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
            && isset($this->arrayConstants[$this->memberName($expr->name, $expr->getStartLine())])
        ) {
            return $this->arrayConstants[$this->memberName($expr->name, $expr->getStartLine())];
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

    /** Records something survey mode took for granted, once per distinct assumption. */
    private function assume(string $note): void
    {
        if (! in_array($note, $this->assumed, true)) {
            $this->assumed[] = $note;
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
        if (in_array($name, $this->inlining, true)) {
            throw new Refusal("{$what} {$name}() reaches {$name}() again, so it cannot be inlined", $line);
        }

        if (count($this->inlining) >= self::INLINE_DEPTH_LIMIT) {
            throw new Refusal("{$what} {$name}() nests deeper than " . self::INLINE_DEPTH_LIMIT, $line);
        }

        $this->inlining[] = $name;
        ++$this->inlineDepth;
    }

    /** Leaves the helper {@see enterInline()} entered. */
    private function leaveInline(): void
    {
        array_pop($this->inlining);
        --$this->inlineDepth;
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
        $savedLocals = $this->locals;
        $savedLiterals = $this->literals;
        $savedCaches = $this->caches;
        $savedConstants = $this->constants;
        $savedInts = $this->intConstants;
        $savedArrayConstants = $this->arrayConstants;
        $savedClass = $this->currentClass;
        $savedUses = $this->useMap;

        $this->locals = $this->bindParameters($method, $args, $methodName, $line);
        $this->constants = [];
        $this->intConstants = [];
        $this->arrayConstants = [];
        $this->currentClass = $class;
        if ($uses !== null) {
            $this->useMap = $uses;
        }

        $this->collectConstants($class);
        $this->enterInline($methodName, 'inlining', $line);

        try {
            return $this->translateMethodAsPredicate($method, $line);
        } finally {
            $this->leaveInline();
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
            $this->constants = $savedConstants;
            $this->intConstants = $savedInts;
            $this->arrayConstants = $savedArrayConstants;
            $this->currentClass = $savedClass;
            $this->useMap = $savedUses;
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
                $this->literals[$param->var->name] = $literal;
                $bound[$param->var->name] = [
                    'rust' => $this->backend->bytes($literal),
                    'kind' => 'bytes',
                    'php' => $this->backend->bytes($literal),
                ];

                continue;
            }

            unset($this->literals[$param->var->name]);

            // A PHPStan service the rule was constructed with is not a value the plugin carries, and reading
            // one refuses. Its *calls* do translate though — `$reflectionProvider->hasClass()` becomes a
            // codebase question — so the parameter binds to the service by name and the inlined body's
            // `$reflectionProvider->..` reaches the same translation `$this->reflectionProvider->..` does.
            $service = $this->serviceArgument($argument, $line);
            if ($service !== null) {
                $bound[$param->var->name] = ['rust' => self::PHP_ONLY, 'kind' => 'service', 'service' => $service];

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
            return $this->injected[$this->memberName($argument->name, $line)] ?? null;
        }

        if ($argument instanceof Variable && is_string($argument->name)) {
            $local = $this->locals[$argument->name] ?? null;

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
        if ($subject['kind'] === 'sole-class' && self::$target === 'php') {
            $subject = [
                'rust' => self::PHP_ONLY,
                'kind' => 'class-names',
                'php' => $this->handlePart($subject, 'listPhp', $statement->getStartLine()),
            ];
        }

        if (! isset(Vocabulary::ITERABLES[$subject['kind']])) {
            throw new Refusal("no iteration mapped for a {$subject['kind']} in an inlined helper", $statement->getStartLine());
        }

        $saved = $this->locals;
        // The loop variable shadows anything of the same name, including a literal an *earlier* inline bound
        // to a parameter called the same thing. `ChecksNamespace` binds `$namespace` to `'App'` for the
        // singular check and iterates a configured list under the same name for the plural one, so without
        // this the second check compares every item against the first check's literal.
        $savedLiterals = $this->literals;
        $savedCaches = $this->caches;
        unset($this->literals[$item->name]);
        $bound = 'item' . ($depth === 0 ? '' : (string) $depth);
        $this->locals[$item->name] = [
            'rust' => $bound,
            'kind' => Vocabulary::ITERABLES[$subject['kind']]['item'],
            'php' => '$' . $bound,
        ] + (isset($subject['as']) ? ['as' => $subject['as']] : []);

        try {
            $predicate = $this->anyBodies($body, $depth);
        } finally {
            $this->locals = $saved;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
        }

        if (self::$target === 'php') {
            return $this->backend->call('any_of', [
                $this->operand($subject),
                "static fn (\${$bound}): bool => {$predicate}",
            ]);
        }

        $iterable = str_replace('{rust}', $subject['rust'], Vocabulary::ITERABLES[$subject['kind']]['iter']);

        return "{$iterable}.any(|{$bound}| {$predicate})";
    }

    /**
     * What one level of an "any of them" loop tests: a guard, or a further loop.
     *
     * php-parser models attributes in two levels — a declaration has attribute *groups*, each holding attributes
     * — so `foreach ($groups as $group) { foreach ($group->attrs as $attr) { if (..) return true; } }` is the only
     * way to ask "does it carry this attribute". One level cannot express that, and flattening the two into one
     * would be inventing a shape the source does not have.
     */
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

            // Named, because a line number alone does not say *which* helper: two rules refused at "line 50"
            // and the file was a different one each time, which cost a reader a wrong conclusion about what
            // the refusal was asking for.
            throw new Refusal(sprintf(
                'statement in %s() outside the vocabulary: %s',
                $method->name->toString(),
                $this->describe($statement),
            ), $statement->getStartLine());
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
        if (self::$target !== 'php') {
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

        $arguments = [$entry['takes'] === 'context' ? '$context' : '$context->source'];
        $args = $expr->getArgs();
        foreach ($entry['arguments'] as $position) {
            $argument = $args[$position] ?? null;
            if (! $argument instanceof Arg) {
                throw new Refusal("{$method}() has no argument {$position} for its runtime helper", $line);
            }

            $arguments[] = $this->operand($this->resolve($argument->value, $line));
        }

        $call = $entry['helper'] . '(' . implode(', ', $arguments) . ')';
        $this->runtimeHelpers[explode('::', $entry['helper'])[0]] = true;

        return ['rust' => self::PHP_ONLY, 'kind' => $entry['kind'], 'php' => $call];
    }

    /** Whether an expression is the bare `$this`. */
    private function isThis(Expr $expr): bool
    {
        return $expr instanceof Variable && $expr->name === 'this';
    }

    /** The descriptor kind a configured default belongs to, in one place so the two callers cannot drift. */
    private function configKind(mixed $default): string
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
            $this->configured[$property] ??= [
                'parameter' => $path,
                'default' => $default,
                'kind' => $this->configKind($default),
            ];

            $this->usesConfiguration = true;

            return [
                'rust' => 'self.' . $this->snake($property),
                'kind' => $this->configured[$property]['kind'],
                'php' => '$this->' . $property,
            ];
        }

        throw new Refusal(
            "{$getter}() reads " . implode(' or ', $paths) . ", which this package's neon does not declare",
            $line,
        );
    }

    /**
     * Records a parameter holding an object of this package's own, and says whether it did.
     *
     * Two kinds, and only one of them has its methods inlined. A **configuration value object** resolves its
     * getters to the neon parameters they read — inlining one instead reached `$this->parameters[...]` on an
     * object the plugin does not have, and refused as `unknown local $this`, a message about the receiver
     * rather than about the shape. A **collaborator** is anything else the rule delegates to, whose methods
     * are inlined like a trait's or a parent's, so a package that keeps a small analyzer on its own class does
     * not become a vocabulary gap.
     *
     * The value object is checked first: both are "an object from this package", and the wrong order would
     * inline the one that must not be.
     */
    private function takeOwnObject(string $name, Param $param, ?PackageConfiguration $configuration): bool
    {
        $valueObject = $this->valueObjectFor($param, $configuration);
        if ($valueObject instanceof ConfigurationObject) {
            $this->valueObjects[$name] = $valueObject;

            return true;
        }

        if ($param->type instanceof Name) {
            $this->collaborators[$name] = $param->type->getLast();
        }

        return false;
    }

    /**
     * The configuration value object a constructor parameter declares, or null when it declares something else.
     *
     * Recognised the same way the aggregate path recognises it: the neon builds the class from exactly one
     * parameter root, which is what {@see PackageConfiguration::valueObjectRoot()} answers, and the class sits
     * beside the rules one directory up from `Rules/` — where every package checked puts it.
     */
    private function valueObjectFor(Param $param, ?PackageConfiguration $configuration): ?ConfigurationObject
    {
        if (! $param->type instanceof Name || ! $configuration instanceof PackageConfiguration) {
            return null;
        }

        $candidate = dirname($this->file, 2) . '/' . $param->type->getLast() . '.php';
        if (! is_file($candidate)) {
            return null;
        }

        $namespace = SourceIndex::namespaceOf(
            (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($candidate)) ?? [],
            $param->type->getLast(),
        );
        if ($namespace === null) {
            return null;
        }

        $root = $configuration->valueObjectRoot($namespace . '\\' . $param->type->getLast());

        return $root === null ? null : ConfigurationObject::fromFile($candidate, $root);
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

        $valueObject = $this->valueObjects[$this->memberName($expr->var->name, $line)] ?? null;
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
        $type = $this->collaborators[$property] ?? null;

        return $type === null ? null : $this->findClassByName($type);
    }

    /**
     * `$classReflection->getParentClassesNames()` — the ancestry, parents only.
     *
     * `ClassLikeMetadata` keeps that list under `parentClasses`, which is not `getClassAncestors()`: that one
     * folds in interfaces and traits, and a rule walking parents to find an overridden method means parents.
     *
     * @return Descriptor|null
     */
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

        if (self::$target !== 'php') {
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

        if (self::$target !== 'php') {
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
        if ($base['kind'] !== 'hook-node' || ! in_array($this->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)) {
            return null;
        }

        if (self::$target !== 'php') {
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
     * One branch of a choice, as a PHP string expression.
     *
     * An interpolation is the interesting case: `"request('{$firstArg->value}')"` is a label built around
     * something read off the node, and it becomes a concatenation of the literal parts with the value between
     * them. Only the PHP target, which is where a produced value is a PHP string in the first place.
     */
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
            return $this->backend->bytes($expr->value);
        }

        if ($expr instanceof InterpolatedString) {
            if (self::$target !== 'php') {
                throw new Refusal('an interpolated value, which only the PHP target carries', $expr->getStartLine());
            }

            $parts = [];
            foreach ($expr->parts as $part) {
                $parts[] = $part instanceof InterpolatedStringPart
                    ? $this->backend->bytes($part->value)
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
        $savedLocals = $this->locals;
        $savedLiterals = $this->literals;
        $savedCaches = $this->caches;
        $this->locals = $this->bindParameters($helper, $args, $name, $line) + $this->locals;

        try {
            foreach ($bindings as $binding) {
                $this->bindLocal($binding, $line);
            }

            // Each branch is rendered the way a message is: a written word, a class constant, or a string built
            // from something read off the node. `requestHelperCallLabel()` returns `request('key')` or
            // `request(...)`, and the first of those is an interpolation.
            $expression = $this->choiceValue($final);
            foreach (array_reverse($guards) as [$condition, $value]) {
                $expression = $this->backend->conditional(
                    $this->translateCondition($condition),
                    $this->choiceValue($value),
                    $expression,
                );
            }
        } finally {
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
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
        return $this->index->find($shortName, $this->file);
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

        $this->caches[$name] = ['rust' => self::PHP_ONLY, 'kind' => 'unfilled-cache', 'php' => self::PHP_ONLY];

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

        $this->caches[$cache] = $this->resolve($stored, $statement->getStartLine());

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

        return ($this->caches[$table->name]['kind'] ?? null) === 'unfilled-cache' ? $table->name : null;
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
        if ($this->checkMode && $this->inlineDepth === 0 && $this->isBranchCheck($stmt)) {
            $this->translateBranchCheck($stmt);

            return;
        }

        // if (COND) { $message = ..; $errors[] = RuleErrorBuilder::..; }  — a conditional report rather than a
        // guard. A rule that reports two different things about the same subject writes one of these per
        // finding, and each carries its own message and identifier.
        if ($stmt->elseifs === [] && ! $stmt->else instanceof Else_ && $this->isConditionalReport($stmt->stmts)) {
            $this->translateConditionalReport($stmt);

            return;
        }

        if ($stmt->elseifs !== [] || count($stmt->stmts) !== 1
            || ($stmt->else instanceof Else_ && ! $this->isFlagAssignment($stmt->stmts[0]))
        ) {
            throw new Refusal('if statement that is not a single-statement guard', $stmt->getStartLine());
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
            $this->translateGuard($stmt->cond, $this->backend->bail());

            return;
        }

        // `return []` leaves the whole rule; `continue` only ends this iteration. Which one it
        // is comes from the guard's own body, not from whether we happen to be in a loop.
        if ($this->isReturnEmptyArray($stmt->stmts)) {
            $exit = $this->backend->bail();
        } elseif (($this->isCollector || $this->inErrorHelper) && $this->isReturnNull($stmt->stmts)) {
            // `return null` in an inlined helper means "no value", not "stop the rule" — but only when the
            // enclosing loop belongs to the caller. Then it is the current item's answer and the iteration
            // ends; the rule's own check on the produced value follows, so both agree on what null means. A
            // loop the helper opened itself is the other case, and leaving it has to leave the helper.
            $exit = $this->loopDepth > 0 && $this->loopDepth === $this->helperLoopFloor
                ? 'continue;'
                : $this->backend->bail();
        } elseif ($only instanceof Continue_ && ! $only->num instanceof Expr) {
            if (! $this->inLoop) {
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
            || ! isset($this->reportedErrors[$condition->expr->name])
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
            && ($this->locals[$only->expr->name]['kind'] ?? null) === 'accumulator'
            && ! isset($this->listAccumulators[$only->expr->name]);
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
            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                return false;
            }

            $last = $index === count($statements) - 1;
            $appends = $statement->expr->var instanceof ArrayDimFetch
                && ! $statement->expr->var->dim instanceof Expr
                && $this->isRuleErrorBuilder($statement->expr->expr);

            if ($last !== $appends) {
                return false;
            }
        }

        return true;
    }

    /** Emits `if (COND) { report(..); }`, with the block's own statements inside it. */
    private function translateConditionalReport(If_ $stmt): void
    {
        if (self::$target !== 'php') {
            throw new Refusal('a conditional report, which only the PHP target carries', $stmt->getStartLine());
        }

        $condition = $this->translateCondition($stmt->cond);
        $this->lines[] = new Stm('if-open', ['condition' => $condition], $this->indent);
        $this->indent += 4;

        try {
            foreach ($stmt->stmts as $statement) {
                $this->translateStatement($statement);
            }
        } finally {
            $this->indent -= 4;
        }

        $this->lines[] = new Stm('block-close', [], $this->indent);
    }

    /**
     * `$this->something(...)`, with a plain method name.
     *
     * @phpstan-assert-if-true MethodCall&object{name: Identifier} $value
     */
    private function isOwnMethodCall(Expr $value): bool
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
        if (! $this->inErrorHelper
            || ! $only instanceof Return_
            || ! $only->expr instanceof Expr
            || ! $this->isRuleErrorBuilder($only->expr)
        ) {
            return false;
        }

        // Read after taking the message, so each branch records the message and identifier *it* reports
        // under rather than whichever was taken last.
        $this->takeMessage($only->expr);
        $this->reportConditions[] = [
            'condition' => $this->stripOuterParentheses($this->translateCondition($stmt->cond)),
            'message' => $this->reportedMessage(),
            'code' => $this->reportedCode(),
            'anchor' => $this->anchor ?? $this->defaultAnchor(),
        ];
        // The next branch is free to report something else: this one is now accounted for.
        $this->reportTaken = true;

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
        if ($this->reportConditions === []) {
            return false;
        }

        $reports = [];
        foreach ($this->reportConditions as $branch) {
            $reports[$branch['message'] . '|' . $branch['code'] . '|' . $branch['anchor']] = true;
        }

        // Outside check mode the rule appends one report of its own, so the helper only has to say when to
        // reach it. In check mode there is no trailing report to reach: a guard that bailed would leave the
        // check silent, which is the failure this whole mode exists to avoid.
        if (count($reports) === 1 && ! $this->checkMode) {
            // The helper reports when any of them holds, so the rule bails when none does.
            $this->lines[] = new Stm('guard', [
                'condition' => '!((' . implode(' || ', array_column($this->reportConditions, 'condition')) . '))',
                'exit' => $this->backend->bail(),
            ], $this->indent);

            return true;
        }

        if (self::$target !== 'php') {
            throw new Refusal('a helper reporting different findings per branch, which only the PHP target carries');
        }

        // Branches reporting the same finding collapse to one `if` over their disjunction, rather than the
        // same report written once per branch.
        $branches = count($reports) === 1
            ? [['condition' => implode(' || ', array_column($this->reportConditions, 'condition'))] + $this->reportConditions[0]]
            : $this->reportConditions;

        foreach ($branches as $branch) {
            $this->lines[] = new Stm('if-open', ['condition' => $branch['condition']], $this->indent);
            $this->indent += 4;
            $this->lines[] = new Stm('report', [
                'anchor' => $branch['anchor'],
                'message' => $branch['message'],
                'code' => $branch['code'],
            ], $this->indent);
            $this->indent -= 4;
            $this->lines[] = new Stm('block-close', [], $this->indent);
        }

        $this->reportedInline = true;

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
        if ($this->inlineDepth === 0 && $this->checksReported >= 1) {
            throw new Refusal(
                'a rule that asks several independent checks in one pass: flattening them would let the first '
                . "one's guards exit the rule, leaving the rest unreachable",
                $line,
            );
        }
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
            'local-name', 'name-selector', 'name-expr' => $this->backend->call('text_of', [$this->operand($subject)]),
            default => throw new Refusal("cannot read a {$subject['kind']} as a name", $line),
        };
    }

    /**
     * The node kinds the emitted plugin registers for.
     *
     * One kind for every hook but the class-declaration one, where the breadth depends on whether the rule
     * restricted itself to classes. Trait is absent on purpose: PHPStan's `InClassNode` does not fire for a
     * trait either, which the same control showed.
     *
     * Every class-like kind, unconditionally, because the rule's own class test is now asked at runtime rather
     * than folded away — see {@see classHookIsClass()}. Two earlier attempts tried to decide the breadth from
     * whether the rule narrows: a syntactic pre-pass over the source, then the flag the fold set during
     * translation. Both were wrong in the same direction, because neither the presence of the predicate nor its
     * translation proves the *rule* is class-only: compounded or negated, it is not. Not deciding is exact.
     *
     * A rule naming an *abstract* php-parser class asks PHPStan for every node beneath it and branches on the
     * concrete kind, so the plugin registers each — see {@see Vocabulary::HOOK_KINDS}.
     *
     * @param array<string, string>|array<string, null>|array<string, bool> $hook
     *
     * @return list<string>
     */
    private function targetKinds(array $hook): array
    {
        $named = Vocabulary::HOOK_KINDS[$this->nodeType] ?? null;
        if ($named !== null) {
            return $named;
        }

        $kind = (string) $hook['kind'];

        return ($hook['classOnly'] ?? false) === true
            ? [$kind, 'Enum', 'Interface']
            : [$kind];
    }

    /**
     * How many independent checks the rule's body asks of one node.
     *
     * A check is `$x = $this->someError(..)` where the helper builds a rule error: the rule keeps whatever
     * came back, and moves on to ask the next one. Counted before translation because the answer decides how
     * the whole body is emitted, and a rule asking one check must emit what it emits today.
     */
    private function independentChecks(ClassMethod $processNode): int
    {
        $checks = 0;
        foreach ($processNode->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Expression || ! $stmt->expr instanceof Assign) {
                continue;
            }

            $call = $stmt->expr->expr;
            if (! $stmt->expr->var instanceof Variable || ! $this->isOwnMethodCall($call)) {
                continue;
            }

            $method = $call->name->toString();
            $declaring = $this->declaringOf($method);
            if ($declaring !== null && $this->buildsRuleError($this->findMethod($declaring['class'], $method))) {
                ++$checks;
            }
        }

        return $checks + $this->branchChecks($processNode);
    }

    /**
     * How many of the rule's checks are written as a branch rather than a helper call.
     *
     * `if (<this is my case>) { <guards> return [$error]; }`, twice over, is how a rule registered for several
     * node kinds handles each of them — `NoDynamicNameRule` has one branch for the two static accesses and one
     * for the four calls. Each is a check in the same sense a helper is: its guards decline that case, not the
     * rule, and `return []` inside one has to mean "not this branch" rather than "not this node".
     */
    private function branchChecks(ClassMethod $processNode): int
    {
        $checks = 0;
        foreach ($processNode->stmts ?? [] as $statement) {
            if ($statement instanceof If_ && $this->isBranchCheck($statement)) {
                ++$checks;
            }
        }

        return $checks;
    }

    /** A branch whose body is a guard chain ending in a built rule error, which is a check. */
    private function isBranchCheck(If_ $statement): bool
    {
        if ($statement->elseifs !== [] || $statement->else instanceof Else_ || count($statement->stmts) < 2) {
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
        $checkStart = count($this->lines);

        $this->lines[] = new Stm('guard', [
            'condition' => '!(' . $this->stripOuterParentheses($this->translateCondition($statement->cond)) . ')',
            'exit' => $this->backend->bail(),
        ], $this->indent);

        foreach ($statement->stmts as $inner) {
            $this->translateStatement($inner);
        }

        $this->lines[] = $this->reportNode();
        $this->closeCheck($checkStart, $this->branchCheckName($statement), $this->locals);
        $this->reportTaken = true;
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

        return 'branch' . (count($this->checks) + 1);
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
        if ($this->checkMode && $this->inlineDepth === 0) {
            return count($this->lines);
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
        if ($reported && $this->inlineDepth === 1) {
            // Depth 1 is the outermost inline: the check whose guards land in the rule body.
            ++$this->checksReported;
        }

        if ($checkStart === null) {
            return;
        }

        // A helper that took a message rather than collecting conditions reports once, after its guards.
        // Outside check mode the rule appends that report itself; here it belongs to the check, because the
        // check is what the guards above it decline.
        if (! $reported) {
            $this->lines[] = $this->reportNode();
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
        foreach (array_splice($this->lines, $from) as $statement) {
            $body .= $this->backend->render($statement);
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

        $this->checks[] = [
            'name' => $name,
            'signature' => implode(', ', $parameters),
            'body' => $body,
        ];

        $this->lines[] = new Stm('check-call', [
            'name' => $name,
            'arguments' => implode(', ', $arguments),
        ], $this->indent);

        // Every check reports for itself, so the rule has no trailing report to make.
        $this->reportedInline = true;
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
        if ($stmt->expr instanceof Expr && $this->isRuleErrorBuilder($stmt->expr)) {
            $this->takeMessage($stmt->expr);

            return;
        }

        // A record producer's terminal statement is the record itself, or a further producer it hands off to.
        if ($this->recordFields !== null && $stmt->expr instanceof Array_) {
            $this->bindRecordFields($stmt->expr);

            return;
        }

        if ($this->recordFields !== null
            && $stmt->expr instanceof MethodCall
            && $this->isOwnMethodCall($stmt->expr)
        ) {
            $this->recordFields = $this->inlineRecordProducer($stmt->expr, $stmt->getStartLine());

            return;
        }

        // A producer of one value rather than a record — `lastBareFlagIndex()` hands back an index — binds
        // under the empty key, which {@see inlineValueProducer} unwraps. Same machinery either way: the guards
        // are the rule's guards and the terminal return is a transpile-time binding.
        if ($this->recordFields !== null && $stmt->expr instanceof Expr && ! $this->isNullConstant($stmt->expr)) {
            $this->recordFields = ['' => $this->recordField($this->resolve($stmt->expr, $stmt->getStartLine()))];

            return;
        }

        // A trailing `return null` is "no finding". When guards have already collected the conditions
        // under which the helper *does* report, it is the fall-through of those, and emitting a bail
        // here would put an unconditional exit in front of the report.
        if (! $this->isReturnNull([$stmt])) {
            throw new Refusal('an error helper returns something other than null or a built rule error', $stmt->getStartLine());
        }

        if ($this->reportConditions === []) {
            $this->lines[] = new Stm('bail', [], $this->indent);
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
        if (self::$target !== 'php' || ! $this->currentClass instanceof ClassLike) {
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

        $arguments[] = $this->backend->bytes($identifier);
        $this->afterChecks[] = $entry['pass'] . '(' . implode(', ', $arguments) . ')';
        $this->identifiers[] = $identifier;

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
    private function inlineErrorHelper(string $method, array $args, int $line, ?Expr $target = null): void
    {
        // Remembered so the bookkeeping the original does with the returned error can be dropped: by the time
        // this returns, whatever the helper decided has already been reported.
        if ($target instanceof Variable && is_string($target->name)) {
            $this->reportedErrors[$target->name] = true;
        }

        if ($this->takeCrossFileCheck($method, $args, $line)) {
            return;
        }

        $checkStart = $this->openCheck($line);

        $declaring = $this->currentClass instanceof ClassLike
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
        $savedLocals = $this->locals;
        $savedLiterals = $this->literals;
        $savedCaches = $this->caches;

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
                    $this->locals = $savedLocals;
                    $this->literals = $savedLiterals;
                    $this->caches = $savedCaches;
                    $this->locals[$target->name] = $produced;

                    return;
                }
            }

            if ($classified !== null && $target instanceof Variable && is_string($target->name)) {
                // Bound as a nullable string. The rule's own `=== null` guard then bails, and the value goes
                // into the message and the report code, which is what the original does with it.
                $local = $this->snake($target->name);
                $this->lines[] = new Stm('declare', ['target' => $local, 'value' => $classified], $this->indent);
                $this->locals = $savedLocals;
                $this->literals = $savedLiterals;
                $this->caches = $savedCaches;
                $this->locals[$target->name] = [
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
            if ($this->inLoop && $target instanceof Variable) {
                throw new Refusal(
                    "{$method}() is assigned inside a loop and hands back a record, whose fields are "
                    . 'expressions over the item the emitted foreach binds, so folding it into a name declared '
                    . 'before the loop would read that item after it is out of scope',
                    $line,
                );
            }

            throw new Refusal("{$method}() is assigned but does not build a rule error", $line);
        }

        $savedConstants = $this->constants;
        $savedInts = $this->intConstants;
        $savedArrayConstants = $this->arrayConstants;
        $savedClass = $this->currentClass;
        $savedUses = $this->useMap;
        $savedInHelper = $this->inErrorHelper;
        $savedConditions = $this->reportConditions;

        $this->locals = $this->bindParameters($helper, $args, $method, $line);
        $this->constants = [];
        $this->intConstants = [];
        $this->arrayConstants = [];
        $this->currentClass = $declaring['class'];
        $this->useMap = $declaring['uses'];
        $this->inErrorHelper = true;
        $this->reportConditions = [];
        $this->collectConstants($declaring['class']);
        $this->enterInline($method, 'inlining', $line);

        try {
            foreach ($helper->stmts ?? [] as $statement) {
                $this->translateStatement($statement);
            }

            // The caller's locals, not the helper's: the parameters a check method needs are the ones the
            // rule had bound before it asked, and `$this->locals` here is the helper's own scope.
            $this->finishCheck($checkStart, $method, $savedLocals);

            // Whatever this helper took has now been emitted, or handed to the rule's trailing report. A rule
            // that asks several helpers in one pass is free to take a different message from the next one.
            $this->reportTaken = true;
        } finally {
            $this->leaveInline();
            $this->reportConditions = $savedConditions;
            $this->inErrorHelper = $savedInHelper;
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
            $this->constants = $savedConstants;
            $this->intConstants = $savedInts;
            $this->arrayConstants = $savedArrayConstants;
            $this->currentClass = $savedClass;
            $this->useMap = $savedUses;
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
        $declaring = $this->currentClass instanceof ClassLike
            ? $this->declaringOf($builder)
            : null;
        if ($declaring === null) {
            return null;
        }

        $this->locals[$parameter->name] = [
            'rust' => self::PHP_ONLY,
            'kind' => 'record',
            'record' => $this->inlineRecordProducer($producer, $line),
        ];

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
        $declaring = $this->currentClass instanceof ClassLike
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
     * @return array{rust: string, kind: string, php?: string, reason?: string, as?: string}|null
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
    private function inlineProducer(ClassMethod $helper, array $declaring, string $name, array $args, int $line): array
    {
        $savedLocals = $this->locals;

        $savedLiterals = $this->literals;

        $savedCaches = $this->caches;
        $savedConstants = $this->constants;
        $savedInts = $this->intConstants;
        $savedArrayConstants = $this->arrayConstants;
        $savedClass = $this->currentClass;
        $savedUses = $this->useMap;
        $savedInHelper = $this->inErrorHelper;
        $savedFields = $this->recordFields;
        $savedFloor = $this->helperLoopFloor;
        // Any loop already open belongs to the caller, so a `return null` inside this helper ends the caller's
        // iteration. A loop the helper opens raises the depth past this floor, and leaving that one has to
        // leave the helper instead.
        $this->helperLoopFloor = $this->loopDepth;

        $this->locals = $this->bindParameters($helper, $args, $name, $line);
        $this->constants = [];
        $this->intConstants = [];
        $this->arrayConstants = [];
        $this->currentClass = $declaring['class'];
        $this->useMap = $declaring['uses'];
        $this->inErrorHelper = true;
        $this->recordFields = [];
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

                $fields = $this->recordFields ?? [];
            }
        } finally {
            $this->leaveInline();
            $this->helperLoopFloor = $savedFloor;
            $this->recordFields = $savedFields;
            $this->inErrorHelper = $savedInHelper;
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
            $this->constants = $savedConstants;
            $this->intConstants = $savedInts;
            $this->arrayConstants = $savedArrayConstants;
            $this->currentClass = $savedClass;
            $this->useMap = $savedUses;
        }

        if ($fields === []) {
            throw new Refusal("{$name}() is read as a producer but hands back nothing", $line);
        }

        return $fields;
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

        $this->recordFields = $fields;
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

        $declaresForwarded = $this->currentClass instanceof ClassLike
            ? $this->declaringOf($forwarded)
            : null;
        if ($declaresForwarded === null) {
            return null;
        }

        // The shim's own parameters are what the forwarded call passes on — `flagSiteForNew($node, $scope,
        // $reflectionProvider, $firstPartyNamespaces)` names all four. Bound before handing the inner
        // arguments back, or they resolve against the rule's scope, where those names do not exist.
        $this->locals = $this->bindParameters($helper, $args, $method, $line) + $this->locals;

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

        if (self::$target !== 'php') {
            throw new Refusal("{$helper->name->toString()}() reads a name's last segment, which only the PHP target carries", $line);
        }

        $of = $this->resolve($args[0]->value, $line);

        return [
            'rust' => self::PHP_ONLY,
            'kind' => 'bytes',
            'php' => $this->backend->call('last_name_segment', [$this->nameText($of, $line)]),
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

        if (self::$target !== 'php') {
            throw new Refusal("{$helper->name->toString()}() reads a list's duplicates, which only the PHP target carries", $line);
        }

        $of = $this->resolve($args[0]->value, $line);
        if (! in_array($of['kind'], ['list', 'class-names'], true)) {
            throw new Refusal("the duplicates of a {$of['kind']}", $line);
        }

        return [
            'rust' => self::PHP_ONLY,
            'kind' => 'list',
            'php' => $this->backend->call('repeated_values', [$this->operand($of)]),
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
        $savedLocals = $this->locals;
        $savedLiterals = $this->literals;
        $savedCaches = $this->caches;
        $savedConstants = $this->constants;
        $savedInts = $this->intConstants;
        $savedArrayConstants = $this->arrayConstants;
        $savedConstantKeys = $this->constantKeys;
        $savedClass = $this->currentClass;
        $savedUses = $this->useMap;

        $this->locals = $this->bindParameters($helper, $args, $method, $line);
        $this->constants = [];
        $this->intConstants = [];
        $this->arrayConstants = [];
        $this->constantKeys = [];
        $this->currentClass = $declaring['class'];
        $this->useMap = $declaring['uses'];
        $this->collectConstants($declaring['class']);
        $this->enterInline($method, 'inlining the classifier', $line);

        try {
            $expression = self::$target === 'php' ? 'null' : 'None';
            foreach (array_reverse($cases) as [$condition, $value]) {
                $expression = $this->backend->conditional(
                    $this->stripOuterParentheses($this->translateCondition($condition)),
                    $this->backend->bytes($value),
                    $expression,
                );
            }

            return $expression;
        } finally {
            $this->leaveInline();
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
            $this->constants = $savedConstants;
            $this->intConstants = $savedInts;
            $this->arrayConstants = $savedArrayConstants;
            $this->constantKeys = $savedConstantKeys;
            $this->currentClass = $savedClass;
            $this->useMap = $savedUses;
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
    private function buildsRuleError(ClassMethod $method): bool
    {
        foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Return_::class) as $return) {
            if ($return->expr instanceof Expr && $this->isRuleErrorBuilder($return->expr)) {
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
     * The hierarchy walker, sharing this transpiler's cross-file lookup so a trait or base class resolves
     * exactly the way a static helper reference already does.
     */
    /**
     * Where a `$this->method()` call is declared, looking at the rule itself when the inlined file has no it.
     *
     * @return Declaration|null
     */
    private function declaringOf(string $method): ?array
    {
        $found = $this->currentClass instanceof ClassLike
            ? $this->hierarchy()->declaring($this->currentClass, $method, $this->useMap, $this->ruleNamespace)
            : null;

        if ($found !== null || ! $this->ruleClass instanceof ClassLike || $this->ruleClass === $this->currentClass) {
            return $found;
        }

        return $this->hierarchy()->declaring($this->ruleClass, $method, $this->ruleUses, $this->ruleNamespace);
    }

    private function hierarchy(): Hierarchy
    {
        return new Hierarchy(fn (string $shortName): ?array => $this->findClassByName($shortName));
    }

    /** A `self::CONST` / `static::CONST` string constant declared by this rule. */
    private function selfConstant(ClassConstFetch $expr): string
    {
        $class = $expr->class instanceof Name ? $expr->class->toString() : '';
        $name = $this->memberName($expr->name, $expr->getStartLine());
        if (in_array($class, ['self', 'static'], true) && isset($this->constants[$name])) {
            return $this->constants[$name];
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

        try {
            return $this->backend->bytes(str_replace('\\', '\\\\', $this->rawStringLiteral($expr, $line)));
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
        return $this->backend->call($helper, [
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
        if (self::$target !== 'php') {
            throw new Refusal('a list length compared numerically, which only the PHP target carries', $line);
        }

        $subject = $this->resolve($count->getArgs()[0]->value, $line);

        // An argument list is not a PHP array on the other side — it is a node whose `Argument` children are
        // the arguments — so it counts through the helper that already answers `count($args) === 0` on the
        // equality path. Reaching here at all was the gap: the same expression compared with `<` refused
        // where compared with `===` it emitted, which reads as the vocabulary not covering argument counts.
        if ($subject['kind'] === 'args') {
            return $this->backend->call('arg_count', [$this->operand($subject)]);
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
            return $this->backend->bytes($this->rawStringLiteral($expr, $line));
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
            return self::$target === 'php'
                ? $this->backend->bytes($expr->value)
                : '"' . addcslashes($expr->value, '"\\') . '"';
        }

        if ($expr instanceof ClassConstFetch) {
            $raw = $this->rawStringLiteral($expr, $line);

            return self::$target === 'php' ? $this->backend->bytes($raw) : '"' . addcslashes($raw, '"\\') . '"';
        }

        if ($expr instanceof MethodCall
            && in_array($this->memberName($expr->name, $expr->getStartLine()), ['getLine', 'getStartLine'], true)
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
            if (! $this->inLoop) {
                throw new Refusal('continue outside a loop', $stmt->getStartLine());
            }

            $this->lines[] = new Stm('continue', [], $this->indent);

            return;
        }

        // A collector's terminal statement hands back the datum to record.
        if ($this->isCollector && $stmt instanceof Return_) {
            $this->translateCollect($stmt);

            return;
        }

        // Inside an error helper the return value is the finding itself, not a list holding it.
        if ($this->inErrorHelper && $stmt instanceof Return_) {
            $this->translateErrorHelperReturn($stmt);

            return;
        }

        // Terminal: return [ ...error... ];  or  $x = RuleErrorBuilder...; return [$x];
        if ($stmt instanceof Return_) {
            // `return [$error];` inside the loop that built it: report here and stop, which is what the original
            // does. Emitted at the return rather than at the assignment, because only the return distinguishes
            // "report the first one and stop" from "collect them all".
            if ($this->inLoop
                && $this->pendingReport !== null
                && $stmt->expr instanceof Array_
                && count($stmt->expr->items) === 1
                && ($only = $stmt->expr->items[0]) !== null
                && $only->value instanceof Variable
                && $only->value->name === $this->pendingReport
            ) {
                $this->lines[] = $this->reportNode();
                $this->lines[] = new Stm('bail', [], $this->indent);
                $this->reportedInline = true;
                $this->pendingReport = null;

                return;
            }

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

            // $name = $this->something(...);  where a runtime helper answers it. Before the inlining path
            // below, which walks the method's own statements — and a helper stands in for exactly the methods
            // whose statements do not translate, so inlining first refuses inside the method being replaced.
            if ($value instanceof MethodCall
                && $stmt->expr->var instanceof Variable
                && is_string($stmt->expr->var->name)
            ) {
                $answered = $this->resolveCollaboratorCall($value, $stmt->getStartLine());
                if ($answered !== null) {
                    $this->locals[$stmt->expr->var->name] = $answered;

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
                $this->lines[] = $this->reportNode();
                $this->reportedInline = true;
                $this->reportTaken = true;

                return;
            }

            // $someList[] = <a node>  — an accumulator being filled with what the loop kept, rather than
            // with findings. Only a count is read from it today, and `countable()` is what allows that.
            if ($stmt->expr->var instanceof ArrayDimFetch
                && ! $stmt->expr->var->dim instanceof Expr
                && $stmt->expr->var->var instanceof Variable
                && is_string($stmt->expr->var->var->name)
                && (($this->locals[$stmt->expr->var->var->name]['kind'] ?? null) === 'accumulator'
                    || isset($this->listAccumulators[$stmt->expr->var->var->name]))
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
                if ($this->inLoop && $stmt->expr->var instanceof Variable && is_string($stmt->expr->var->name)) {
                    $this->pendingReport = $stmt->expr->var->name;
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
        $exit ??= $this->backend->bail();
        if ($exit === $this->backend->bail() && $this->tryRefine($cond)) {
            return;
        }

        $this->unreachableGuard = null;
        $bail = $this->stripOuterParentheses($this->translateCondition($cond));
        if ($bail === 'false') {
            // Dropping a guard widens the rule, so it is only allowed where the guard is *provably*
            // unreachable and the translation said which proof applies. Without one, refuse: a silently
            // widened rule reports what the original filtered out, and nothing downstream can see it.
            if ($this->unreachableGuard === null) {
                throw new Refusal('guard translates to a constant with no reason it cannot hold', $cond->getStartLine());
            }

            $this->lines[] = new Stm('comment', ['text' => 'guard dropped: ' . $this->unreachableGuard], $this->indent);
            $this->unreachableGuard = null;

            return;
        }

        if ($bail === 'true') {
            // The other direction of the same fold, and never right: a guard that always exits means the rule can
            // never report anything, so emitting it would produce a plugin that loads and does nothing.
            throw new Refusal(
                'a guard that always exits, so the rule could never report: ' . ($this->unreachableGuard ?? 'no reason recorded'),
                $cond->getStartLine(),
            );
        }

        $this->lines[] = new Stm('guard', ['condition' => $bail, 'exit' => $exit], $this->indent);
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

    /** @var array<string, array<string, array{0: string, 1: string, 2?: string}>> expression key -> refined fields */
    private array $refinements = [];

    private function exprKey(Expr $expr): string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            $local = $this->locals[$expr->name] ?? null;

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
            throw new Refusal('else branch that does more than set a flag', $stmt->getStartLine());
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
            $savedLocals = $this->locals;
            $savedLiterals = $this->literals;
            $savedCaches = $this->caches;
            $this->locals[$stmt->valueVar->name] = ['rust' => $subject['rust'], 'kind' => 'collected'];
            try {
                foreach ($stmt->stmts as $inner) {
                    $this->translateStatement($inner);
                }
            } finally {
                $this->locals = $savedLocals;
                $this->literals = $savedLiterals;
                $this->caches = $savedCaches;
            }

            return;
        }

        // A rule looping the classes a type names iterates the list, not the single-class reduction.
        if ($subject['kind'] === 'sole-class' && self::$target === 'php') {
            $subject = [
                'rust' => self::PHP_ONLY,
                'kind' => 'class-names',
                'php' => $this->handlePart($subject, 'listPhp', $stmt->getStartLine()),
            ];
        }

        if (! isset(Vocabulary::ITERABLES[$subject['kind']])) {
            throw new Refusal("no iteration mapped for a {$subject['kind']}", $stmt->getStartLine());
        }

        $iterable = Vocabulary::ITERABLES[$subject['kind']];
        $variable = $this->snake($stmt->valueVar->name);

        $savedLocals = $this->locals;

        $savedLiterals = $this->literals;

        $savedCaches = $this->caches;
        $savedLoop = $this->inLoop;
        $this->locals[$stmt->valueVar->name] = ['rust' => $variable, 'kind' => $iterable['item']];
        if (isset($subject['as'])) {
            // Every item of a list of found nodes is of the kind that was searched for.
            $this->locals[$stmt->valueVar->name]['as'] = $subject['as'];
        }

        if (self::$target === 'php') {
            $this->locals[$stmt->valueVar->name]['php'] = '$' . $variable;
        }

        $this->inLoop = true;
        ++$this->loopDepth;

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
            --$this->loopDepth;
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
        }

        $this->lines[] = new Stm('block-close', [], $this->indent);
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

        $savedLocals = $this->locals;

        $savedLiterals = $this->literals;

        $savedCaches = $this->caches;
        $savedLoop = $this->inLoop;
        $this->inLoop = true;
        ++$this->loopDepth;

        $pad = str_repeat(' ', $this->indent);
        $this->lines[] = new Stm('for-open', ['subject' => $subject['rust']], $this->indent);
        $this->indent += 4;

        $bindings = [];
        if (! $stmt->valueVar instanceof Array_) {
            throw new Refusal('destructuring foreach over something other than a list', $stmt->getStartLine());
        }

        foreach ($stmt->valueVar->items as $index => $item) {
            if ($item === null || ! $item->value instanceof Variable || ! is_string($item->value->name)) {
                throw new Refusal('destructuring into something other than simple variables', $stmt->getStartLine());
            }

            $name = $this->snake($item->value->name);
            $bindings[count($this->lines)] = $name;
            $this->lines[] = new Stm('collected-value', ['name' => $name, 'index' => (string) $index], $this->indent);
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
            --$this->loopDepth;
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
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
            throw new Refusal('collector returns something other than a list of values', $stmt->getStartLine());
        }

        $values = [];
        foreach ($stmt->expr->items as $item) {
            if ($item === null) {
                throw new Refusal('collector returns a list with a hole', $stmt->getStartLine());
            }

            $values[] = $this->stringValue($item->value, $stmt->getStartLine()) . '.to_string()';
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
     * @param Descriptor $descriptor
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
    /** The message a report carries, quoted when the rule wrote a literal and bare when it computed one. */
    private function reportedMessage(): string
    {
        $message = $this->message ?? throw new Refusal('reporting before the message is known');
        $literal = str_starts_with($message, '"') && str_ends_with($message, '"');

        return $literal ? $this->backend->bytes(substr($message, 1, -1)) : $message;
    }

    private function reportNode(): Stm
    {
        if (self::$target !== 'php') {
            return new Stm('raw', ['text' => $this->reportStatement()]);
        }

        if ($this->message === null) {
            throw new Refusal('reporting before the message is known');
        }

        if ($this->anchorNeedsLoop && ! $this->inLoop) {
            throw new Refusal(
                'a report anchored on a loop item but emitted outside the loop, where the item is no longer bound',
            );
        }

        return new Stm('report', [
            'anchor' => $this->anchor ?? $this->defaultAnchor(),
            'message' => $this->reportedMessage(),
            // PHPStan's own identifier is the code, so a finding is labelled the same by both tools. Written
            // as PHP here rather than in the backend: a rule that classifies what it found computes its code,
            // and only this side knows whether the code is a literal to quote or an expression to keep.
            'code' => $this->reportedCode(),
        ], $this->indent);
    }

    /**
     * The span `->line(<expr>)` names, as a PHP expression, refusing anything that is not a node's own line.
     *
     * `getLine()` and `getStartLine()` are the same question of a declaration. A computed line number is not:
     * a report points at a node here, not at an integer, so there is nothing to translate it to.
     */
    private function reportAnchor(Expr $expr, int $line): string
    {
        if (self::$target !== 'php') {
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
                $parts[] = $this->backend->bytes($part->value);

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

        $this->identifierIsExpression = true;

        return self::$target === 'php'
            ? implode(' . ', $parts)
            : 'format!("{}", ' . implode(', ', $parts) . ')';
    }

    /** The reported code, written as PHP: quoted when it is a literal, kept as-is when the rule computes it. */
    private function reportedCode(): string
    {
        $identifier = $this->identifier ?? throw new Refusal('no identifier to use as the reported code');

        return $this->identifierIsExpression ? $identifier : $this->backend->bytes($identifier);
    }

    /** Pulls the message and the identifier out of a `RuleErrorBuilder::message(..)->..->build()` chain. */
    private function takeMessage(Expr $chain): void
    {
        while ($chain instanceof MethodCall) {
            if ((string) $chain->name === 'identifier' && count($chain->getArgs()) === 1) {
                // Reset first: a rule that reports several things may compute one code and write the next as a
                // literal, and a flag that only ever turns on left the literal unquoted — a plugin naming an
                // undefined constant. `interpolatedIdentifier()` turns it back on when it applies.
                $this->identifierIsExpression = false;
                $identifier = $this->interpolatedIdentifier($chain->getArgs()[0]->value, $chain->getStartLine())
                    ?? $this->rawStringLiteral($chain->getArgs()[0]->value, $chain->getStartLine());
                // A second identifier is only a problem if the first was never reported under: a rule that
                // reports two different things takes one per finding, and the report in between is what makes
                // the change deliberate rather than an overwrite nobody sees.
                if ($this->identifier !== null && $this->identifier !== $identifier && ! $this->reportTaken) {
                    throw new Refusal('a second identifier before the first was reported', $chain->getStartLine());
                }

                $this->identifier = $identifier;
                $this->identifiers[] = $identifier;
            }

            // `->line($classMethod->getLine())` moves the finding off the node the hook fired for and onto the
            // member the rule is really talking about. A rule looping a class-like's methods reports one finding
            // per method, and every one of them would otherwise land on the class's own line.
            if ((string) $chain->name === 'line' && count($chain->getArgs()) === 1) {
                $this->anchor = $this->reportAnchor($chain->getArgs()[0]->value, $chain->getStartLine());
                $this->anchorNeedsLoop = $this->inLoop;
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
        if ($this->message !== null && $this->message !== $message && ! $this->reportTaken) {
            throw new Refusal('a second message before the first was reported', $chain->getStartLine());
        }

        $this->message = $message;
        $this->reportTaken = false;
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

    // -----------------------------------------------------------------------
    // Local bindings
    // -----------------------------------------------------------------------

    /**
     * Appends to a list accumulator, declaring it at its binding site the first time.
     *
     * Restricted to appending a *node* — what a subtree search or a member loop yielded — because that is the
     * only accumulator shape the corpus has, and a list of anything else would need a rendering for its items
     * that nothing has pinned down.
     */
    private function appendToList(string $name, Expr $value, int $line): void
    {
        if (self::$target !== 'php') {
            throw new Refusal('a list a rule builds, which only the PHP target carries', $line);
        }

        $item = $this->resolve($value, $line);
        // A class name renders as a PHP string, so a list of them is as well defined as a list of nodes. The
        // restriction below is about having a rendering for the item, not about what a list may hold.
        if (! in_array($item['kind'], ['expr', 'found-node', 'method-decl', 'class-name', 'bytes'], true)) {
            throw new Refusal("appending a {$item['kind']} to a list", $line);
        }

        if (! isset($this->listAccumulators[$name])) {
            [$slot, $indent] = $this->accumulatorSlots[$name]
                ?? throw new Refusal("appending to \${$name}, which was never bound to an empty array", $line);

            array_splice($this->lines, $slot, 0, [new Stm('declare-list', ['target' => $name], $indent)]);
            // Every later slot moved down by one, so a second accumulator declares in the right place too.
            foreach ($this->accumulatorSlots as $other => [$otherSlot, $otherIndent]) {
                if ($otherSlot > $slot) {
                    $this->accumulatorSlots[$other] = [$otherSlot + 1, $otherIndent];
                }
            }

            $this->listAccumulators[$name] = true;
        }

        $this->listItemKinds[$name] = $item['kind'];
        $this->lines[] = new Stm('append', ['target' => $name, 'value' => $this->operand($item)], $this->indent);
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

        if (self::$target !== 'php') {
            throw new Refusal('a configured map walked by key, which only the PHP target carries', $stmt->getStartLine());
        }

        $key = $this->snake($stmt->keyVar->name);
        $value = $this->snake($stmt->valueVar->name);

        $savedLocals = $this->locals;
        $savedLiterals = $this->literals;
        $savedCaches = $this->caches;
        $savedLoop = $this->inLoop;
        $this->inLoop = true;
        unset($this->literals[$stmt->keyVar->name], $this->literals[$stmt->valueVar->name]);
        $this->locals[$stmt->keyVar->name] = ['rust' => $key, 'kind' => 'config-bytes', 'php' => '$' . $key];
        $this->locals[$stmt->valueVar->name] = ['rust' => $value, 'kind' => 'config-bytes', 'php' => '$' . $value];

        $this->lines[] = new Stm('foreach-keyed-open', [
            'iterable' => $this->operand($subject),
            'key' => $key,
            'variable' => $value,
        ], $this->indent);
        $this->indent += 4;
        ++$this->loopDepth;

        try {
            foreach ($stmt->stmts as $statement) {
                $this->translateStatement($statement);
            }
        } finally {
            --$this->loopDepth;
            $this->indent -= 4;
            $this->inLoop = $savedLoop;
            $this->locals = $savedLocals;
            $this->literals = $savedLiterals;
            $this->caches = $savedCaches;
        }

        $this->lines[] = new Stm('block-close', [], $this->indent);

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
        if (self::$target !== 'php') {
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

        $this->locals[$args[2]->value->name] = [
            'rust' => self::PHP_ONLY,
            'kind' => 'captures',
            'php' => self::PHP_ONLY,
            'patternPhp' => $this->backend->bytes($pattern),
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
            . $this->backend->bytes($group) . ')';
    }

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
            $this->accumulatorSlots[$name] = [count($this->lines), $this->indent];

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
            if (isset($this->locals[$name])) {
                throw new Refusal("\${$name} is assigned a condition twice, and the second would be ignored", $line);
            }

            $condition = '(' . $this->translateCondition($value) . ')';
            $this->locals[$name] = ['rust' => $condition, 'kind' => 'bool', 'php' => $condition];

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
            $this->locals[$name] = ['rust' => $this->translateSprintf($value), 'kind' => 'message'];

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
            $this->locals[$name] = $this->resolve($value, $line);

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
            [$index, $unwrapped, $list] = $argIndex;
            $bind = 'arg' . ($index === 0 ? '' : (string) $index) . '_value';
            $pad = str_repeat(' ', $this->indent);
            $this->lines[] = new Stm('bind-arg', ['bind' => $bind, 'args' => $this->operand($list), 'index' => (string) $index], $this->indent);
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
            $this->locals[$name] = ['rust' => self::PHP_ONLY, 'kind' => 'unassigned', 'php' => 'null'];

            return;
        }

        // $x = <resolvable path>  (plain alias, inheriting any refinement)
        try {
            $subject = $this->resolve($value, $line);
        } catch (Refusal $refusal) {
            throw new Refusal('assignment value outside the vocabulary: ' . $refusal->getMessage(), $line);
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
            && ($this->locals[$container->name]['kind'] ?? null) === 'args'
        ) {
            return [$value->dim->value, $unwrapped, $this->locals[$container->name]];
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

            if (self::$target !== 'php') {
                throw new Refusal("a found {$kind}'s arguments, which only the PHP target carries", $line);
            }

            return 'Support::argumentList($context, ' . $this->operand($subject) . ')';
        }

        $kinds = self::ARGUMENT_LIST_KINDS;

        // An instantiation carries one too, and `new Foo;` carries none — which the PHP helper answers as an
        // empty list, the same thing PHPStan's `getArgs()` returns. The Rust field is not optional in the same
        // way, so that target keeps refusing rather than guessing. A nullsafe call is PHP-only for its own
        // reason: {@see Vocabulary::HOOKS} refuses that hook on the Rust targets before this is reached.
        if (self::$target === 'php') {
            $kinds[] = 'Instantiation';
            $kinds[] = 'NullSafeMethodCall';
        }

        if (! in_array($this->nodeKind, $kinds, true)) {
            throw new Refusal("no argument list on a {$this->nodeKind} node", $line);
        }

        return self::$target === 'php'
            ? 'Support::argumentList($context, $node)'
            : '&node.argument_list';
    }

    // -----------------------------------------------------------------------
    // Conditions
    // -----------------------------------------------------------------------

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
                if (self::$target !== 'php') {
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

        if ($helper === 'NamingHelper' && $method === 'isName' && count($args) === 2) {
            $literal = $this->stringLiteral($args[1]->value, $expr->getStartLine());

            return $this->nameEquals($this->resolve($args[0]->value, $expr->getStartLine()), $literal, $expr->getStartLine());
        }

        if ($helper === 'MethodCallNameAnalyzer' && $method === 'isThisMethodCall' && count($args) === 2) {
            $literal = $this->stringLiteral($args[1]->value, $expr->getStartLine());

            return $this->backend->call('is_this_method_call', [
                ...(self::$target === 'php' ? ['$context', '$node'] : ['node']),
                $this->backend->bytes($literal),
            ]);
        }

        // `self::other()` inside an analyzer class already being inlined: the class is the one we are in, so it
        // needs no lookup. Without this, `findClassByName('self')` finds nothing and the refusal names `self`,
        // which points at no file anyone can open.
        if (in_array($helper, ['self', 'static'], true) && $this->currentClass instanceof ClassLike) {
            return $this->inlineMethod($this->currentClass, $method, $args, $expr->getStartLine(), $this->useMap);
        }

        // Any other static helper whose source we can find is inlined rather than hand-translated.
        $helperClass = $this->findClassByName($helper);
        if ($helperClass !== null) {
            return $this->inlineMethod($helperClass['class'], $method, $args, $expr->getStartLine(), $helperClass['uses']);
        }

        throw new Refusal("unknown static helper {$helper}::{$method}()", $expr->getStartLine());
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
            if (self::$target !== 'php') {
                throw new Refusal('a class-named type test, which only the PHP target carries', $expr->getStartLine());
            }

            return 'Support::soleObjectClass(' . $this->operand($subject) . ') !== null';
        }

        // `$type instanceof ConstantStringType` asks whether the type is one literal string. Mago renders such
        // a type as plain `string`, and carries the literal on the scalar's refinement — probed, because the
        // rendering answers "not constant" for every string there is.
        if ($subject['kind'] === 'type' && $wanted === 'PHPStan\Type\Constant\ConstantStringType') {
            if (self::$target !== 'php') {
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

            if (self::$target === 'php') {
                // The descriptor already carries how the type is reached, and which requirement that needs was
                // recorded where it was built. This used to insist on the receiver, on the belief that no other
                // position was exposed; a probe says a node hook can ask about any sub-expression.
                return $this->backend->call('type_is_named_object', [$this->operand($subject)]);
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
            if (self::$target !== 'php') {
                throw new Refusal('a union-type test, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->backend->call('type_is_union', [$this->operand($subject)]);
        }

        // `$node->name instanceof Identifier` — php-parser types a written member name as an identifier and a
        // computed one as an expression. Mago spells the same split structurally, which is what this reads.
        if ($wanted === Identifier::class && $subject['kind'] === 'name-part') {
            if (self::$target !== 'php') {
                throw new Refusal('a written-name test, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->backend->call('is_written_name', [$this->operand($subject)]);
        }

        // `$node->class instanceof Expr` — php-parser types a written class part as `Name` and anything computed
        // as an expression, so this asks "is the class dynamic". Mago has no such split in the tree; the
        // question is whether the part is a written name.
        if ($wanted === Expr::class && in_array($subject['kind'], ['expr', 'name-expr', 'name-part'], true)) {
            if (self::$target !== 'php') {
                throw new Refusal('a dynamic-name test, which only the PHP target carries', $expr->getStartLine());
            }

            // Two spellings of the same question, and they need different helpers. A *class* part arrives
            // unwrapped, so a written one is a name node. A *member* part arrives as its selector, which is
            // never a name node — asking `isName()` of one answers false for every call there is, and the
            // guard would then report on all of them.
            $helper = $subject['kind'] === 'name-part' ? 'is_written_name' : 'is_name';

            return '! ' . $this->backend->call($helper, [$this->operand($subject)]);
        }

        if ($subject['kind'] === 'hook-node'
            && isset(Vocabulary::EXPRESSION_KINDS[$wanted])
            && $this->hookKinds !== []
        ) {
            if (! in_array(Vocabulary::EXPRESSION_KINDS[$wanted], $this->hookKinds, true)) {
                return $this->unreachable("this plugin does not register {$wanted}, so the branch never runs");
            }

            // `instanceof MethodCall` also holds for `?->` on PHPStan's side: it desugars a nullsafe call into
            // a `MethodCall` carrying a `virtualNullsafeMethodCall` attribute, which is why
            // `hihaho/phpstan-rules` has a trait method that tests for exactly that. Mago keeps the two kinds
            // apart, so the test has to name both or the port stays silent where the original reports —
            // measured on a fixture pair, where PHPStan reported the nullsafe call and the port did not.
            $kinds = [Vocabulary::EXPRESSION_KINDS[$wanted]];
            if ($wanted === MethodCall::class && in_array('NullSafeMethodCall', $this->hookKinds, true)) {
                $kinds[] = 'NullSafeMethodCall';
            }

            $tests = [];
            foreach ($kinds as $kind) {
                $tests[] = $this->backend->call('node_kind_is', ['$context', '$node', $this->backend->bytes($kind)]);
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
            $helper = self::$target === 'php'
                ? str_replace('_option', '', $hintPredicates[$wanted])
                : $hintPredicates[$wanted];

            return $this->backend->call($helper, [$this->operand($subject)]);
        }

        if ($wanted === ClassReflection::class) {
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('ClassReflection test on something else', $expr->getStartLine());
            }

            return $this->classFrom === 'metadata'
                ? $this->alwaysHolds('a declaration hook fires inside a class-like, so there is always an enclosing class')
                : $this->backend->call('is_in_class', self::$target === 'php' ? ['$context', '$node'] : ['context']);
        }

        if ($subject['kind'] === 'name-selector') {
            if ($wanted === Identifier::class) {
                return $this->backend->call('selector_is_identifier', [$this->operand($subject)]);
            }

            throw new Refusal("instanceof {$wanted} on a member selector", $expr->getStartLine());
        }

        // `$node->name instanceof Identifier` on the class-like under analysis asks whether it is named. Mago makes
        // an anonymous class a separate node kind, so this hook only ever fires for a named one — the same
        // reasoning that makes `isAnonymous()` unreachable here.
        if ($wanted === Identifier::class
            && ($subject['key'] ?? null) === '$node->name'
            && in_array($this->nodeKind, self::CLASS_LIKE_HOOK_KINDS, true)
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
            if (self::$target !== 'php') {
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

            if (self::$target !== 'php') {
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

        if ($subject['kind'] === 'extends') {
            if ($wanted === Name::class) {
                return $this->backend->call('has_extends', self::$target === 'php' ? ['$context', '$node'] : ['node']);
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
                if (self::$target !== 'php') {
                    throw new Refusal('a class-ancestry test between constructed types, which only the PHP target carries', $line);
                }

                return $this->negateUnless(
                    $tail === 'yes',
                    $this->backend->call('class_descends_from', ['$context', $child, $parent]),
                );
            }
        }

        // `$type->isCallable()->yes()` — whether the type can be called. Mago carries a callable as one of a
        // type's atomic parts, and a closure as a named object, which is what the helper reads.
        if ($name === 'isCallable' && $args === []) {
            if (self::$target !== 'php') {
                throw new Refusal('a callable-type test, which only the PHP target carries', $line);
            }

            return $this->negateUnless(
                $tail === 'yes',
                $this->backend->call('type_is_callable', [$this->operand($this->resolve($inner->var, $line))]),
            );
        }

        if ($name === 'isInstanceOf' && count($args) === 1) {
            $literal = $this->classLiteral($args[0]->value, $line);

            return $this->negateUnless(
                $tail === 'yes',
                $this->typeQuery($inner, 'type_is_instance_of', ['rust' => $literal, 'kind' => 'bytes', 'php' => $this->backend->bytes($literal)], $line),
            );
        }

        if (
            $name === 'hasVariableType' && count($args) === 1
            && $inner->var instanceof Variable && $inner->var->name === 'scope'
        ) {
            // The rule asks about the scope *before* this node, which only the pre hook can answer.
            $this->readsPriorScope = true;
            $variable = $this->variableNameExpression($args[0]->value, $line);

            return $this->negateUnless($tail === 'no', "support::variable_is_undefined(context, {$variable})");
        }

        throw new Refusal("trinary tail on an unsupported query ->{$name}()", $line);
    }

    /**
     * A question about an expression's inferred type.
     *
     * The two callers differ only in the helper and how the argument is read, so the part that matters,
     * which position the SDK will answer for, is written once.
     */
    /**
     * A question asked of an inferred type, with the thing asked about as a descriptor.
     *
     * @param Descriptor $about the name the question names
     */
    private function typeQuery(MethodCall $inner, string $helper, array $about, int $line): string
    {
        $subject = $this->resolve($inner->var, $line);
        $this->requireType($subject, $line);

        if (self::$target !== 'php') {
            return "support::{$helper}(context, {$subject['rust']}, b\"{$about['rust']}\")";
        }

        // Every type descriptor already carries how it is reached — `$context->receiverType` where the SDK
        // hands it over ready-made, `Support::expressionType()` where the rule asks by node. The old form of
        // this check refused anything but the receiver, on the belief that nothing else was exposed; a probe
        // says otherwise, and the requirement each one needs is recorded where the descriptor is built.
        return $this->backend->call($helper, ['$context', $this->operand($subject), $this->operand($about)]);
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
            return $this->backend->bytes($this->classLiteral($argument, $line));
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
     * A predicate that cannot be false in Mago's model, with the proof that says so.
     *
     * The mirror of {@see unreachable()}. Both record why; which one applies depends on whether the rule asks the
     * question straight or negated, and a guard is dropped when the *bail* folds to false either way.
     */
    /**
     * The guard a hook needs before the rule's own body, when Mago's node kind is wider than PHPStan's.
     *
     * php-parser has a node class per binary operator; Mago has one `Binary` kind and puts the operator in a
     * child. So a rule registered for `Concat` translates to a hook that also fires for `+` and `===`, and the
     * operator check the rule never had to write is what keeps the port exactly as wide as the rule.
     *
     * @param array<string, string|null>|array<string, bool> $hook
     */
    private function gate(array $hook): string
    {
        $condition = $hook['gate'] ?? null;
        if (! is_string($condition)) {
            return '';
        }

        return "        // The hook's node kind is wider than the rule's, so the rule's own kind is checked first.\n"
            . "        if (! {$condition}) {\n            return;\n        }\n\n";
    }

    /**
     * Where a report lands when the rule anchors it on nothing but the node the hook fired for.
     *
     * The node's own span, except for the whole-file hook: PHPStan's `FileNode` takes its position from the
     * first statement it holds, while Mago's `Program` starts at byte zero, and the two differ by however many
     * lines sit above the first statement.
     */
    private function defaultAnchor(): string
    {
        return $this->nodeKind === 'Program' ? 'Support::fileAnchor($context, $node)' : '$node->span';
    }

    private function alwaysHolds(string $reason): string
    {
        $this->unreachableGuard = $reason;

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
            // Inside a declaration hook the answer is yes by construction.
            return $this->classFrom === 'metadata'
                ? 'true'
                : $this->backend->call('is_in_class', self::$target === 'php' ? ['$context', '$node'] : ['context']);
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
                if (self::$target !== 'php') {
                    throw new Refusal('isAbstract() on a declaration, which only the PHP target carries', $expr->getStartLine());
                }

                return 'Support::declarationIsAbstract($context, ' . $this->operand($subject) . ')';
            }
        }

        // Reflection predicates. Inside a declaration hook these come from the class metadata, and
        // two of them are settled by which hook it is: the class hook fires only for classes, and
        // never for anonymous ones, which are a separate node in Mago.
        if (in_array($method, ['isClass', 'isAnonymous', 'isAbstract', 'isInterface', 'isTrait', 'isEnum'], true) && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal("{$method}() on something other than a class reflection", $expr->getStartLine());
            }

            if ($this->classFrom !== 'metadata') {
                throw new Refusal("{$method}() outside a declaration hook", $expr->getStartLine());
            }

            $this->usesMetadata = true;

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
                default => self::$target === 'php'
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
            if (self::$target !== 'php') {
                throw new Refusal('a declared-method test, which only the PHP target carries', $expr->getStartLine());
            }

            // Through the name-argument path, which takes a written literal without resolving it: a
            // `Scalar_String` is not an access path, and `hasMethod('rules')` is the commonest spelling there is.
            return $this->backend->call('class_has_method', [
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

            if (self::$target !== 'php') {
                throw new Refusal('an implemented-interface test, which only the PHP target carries', $expr->getStartLine());
            }

            return $this->backend->call('class_implements', [
                '$context',
                '$node',
                $this->nameText($this->resolve($args[0]->value, $expr->getStartLine()), $expr->getStartLine()),
            ]);
        }

        if ($method === 'getName' && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());

            if ($subject['kind'] !== 'class-reflection') {
                throw new Refusal('getName() on something other than a class reflection', $expr->getStartLine());
            }

            $this->usesMetadata = true;

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
            return $this->enclosingClassIs($this->backend->bytes($literal));
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
            if ($subject['kind'] === 'type' && self::$target === 'php') {
                $subject = [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'named-class',
                    'php' => 'Support::soleObjectClass(' . $this->operand($subject) . ')',
                ];
            }

            if ($subject['kind'] === 'named-class') {
                $name = $method === 'hasConstructor'
                    ? $this->backend->bytes('__construct')
                    : $this->operand($this->methodNameArgument($args, $method, $expr->getStartLine()));

                return $this->backend->call('method_exists', ['$context', $this->operand($subject), $name]);
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

        if ($expr->var instanceof Variable && $expr->var->name === 'this' && $this->currentClass instanceof ClassLike) {
            return $this->inlineOwnHelper($method, $args, $expr->getStartLine());
        }

        $reflected = $this->reflectionProviderCall($expr, $method, $args);
        if ($reflected !== null) {
            return $reflected;
        }

        if (in_array($method, ['isRelative', 'isSpecialClassName'], true) && $args === []) {
            $subject = $this->resolve($expr->var, $expr->getStartLine());
            $support = $method === 'isRelative' ? 'is_relative_name' : 'is_special_class_name';

            return $this->backend->call($support, [$this->operand($subject)]);
        }

        $this->refuseCallOnService($expr, $method);

        $configured = $this->resolveValueObjectGetter($expr, $method, $expr->getStartLine());
        if ($configured !== null) {
            return $this->operand($configured);
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

                return $this->backend->call($support, self::$target === 'php' ? ['$context', $needle] : ['context', $needle]);
            }

            // A method's written name is a name node here and a string to the rule, so it goes through the byte
            // helpers like any other name once its text is read.
            if ($subject['kind'] === 'method-name' && self::$target === 'php') {
                $support = ['str_ends_with' => 'bytes_end_with', 'str_starts_with' => 'bytes_start_with', 'str_contains' => 'bytes_contain'][$name];

                return $this->backend->call($support, ['Support::methodName(' . $this->operand($subject) . ')', $needle]);
            }

            if (in_array($subject['kind'], ['class-name', 'bytes', 'name-expr'], true)) {
                $support = ['str_ends_with' => 'bytes_end_with', 'str_starts_with' => 'bytes_start_with', 'str_contains' => 'bytes_contain'][$name];
                // A name node carries its text; the byte helpers take the text, which is the same route
                // `in_array()` and a message argument take for this kind.
                $value = $subject['kind'] === 'name-expr' && self::$target === 'php'
                    ? $this->backend->call('text_of', [$this->operand($subject)])
                    : $this->operand($subject);

                return $this->backend->call($support, [$value, $needle]);
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

            $saved = $this->locals;
            $savedLiterals = $this->literals;
            $savedCaches = $this->caches;
            unset($this->literals[$parameter->name]);
            try {
                $this->locals[$parameter->name] = ['rust' => 'item', 'kind' => 'bytes', 'php' => '$item'];
                $predicate = $this->translateCondition($closure->expr);
            } finally {
                $this->locals = $saved;
                $this->literals = $savedLiterals;
                $this->caches = $savedCaches;
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
            return $this->inArrayPredicate($args, $expr->getStartLine());
        }

        if ($name === 'file_exists' && count($args) === 1) {
            return $this->pathExistsPredicate($args[0]->value, $expr->getStartLine());
        }

        if ($name === 'is_string' && count($args) === 1) {
            $target = $args[0]->value;
            if ($target instanceof PropertyFetch && (string) $target->name === 'name') {
                $subject = $this->resolve($target->var, $expr->getStartLine());

                return self::$target === 'php'
                    ? $this->backend->call('direct_variable_name', [$this->operand($subject)]) . ' !== null'
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
            if (self::$target !== 'php') {
                throw new Refusal("{$method}() on a method reflection, which only the PHP target carries", $line);
            }

            $helper = $method === 'isPublic' ? 'reflectedMethodIsPublic' : 'reflectedMethodIsPrivate';

            return 'Support::' . $helper . '($context, '
                . $this->handlePart($subject, 'classPhp', $line) . ', '
                . $this->handlePart($subject, 'methodPhp', $line) . ')';
        }

        // The method-declaration hook's own node is the declaration, so the same helpers answer for it — once
        // it is a part, which is what they navigate.
        if ($subject['kind'] === 'hook-node' && $this->nodeKind === 'Method') {
            $subject = ['rust' => self::PHP_ONLY, 'kind' => 'method-decl', 'php' => 'Support::asPart($context, $node)'];
        }

        if (! in_array($subject['kind'], ['method-decl', 'maybe-method-decl'], true)) {
            return null;
        }

        if (self::$target !== 'php') {
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
        if (in_array($predicate, self::PHP_ONLY_PREDICATES, true) && self::$target !== 'php') {
            throw new Refusal("instanceof {$wanted}, which only the PHP target carries", $line);
        }

        $arguments = in_array($predicate, self::CONTEXT_PREDICATES, true)
            ? ['$context', $this->operand($subject)]
            : [$this->operand($subject)];

        return $this->backend->call($predicate, $arguments);
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
        if (self::$target !== 'php') {
            throw new Refusal('a filesystem check, which only the PHP target carries', $line);
        }

        $subject = $this->resolve($path, $line);
        if (! in_array($subject['kind'], ['bytes', 'message'], true)) {
            throw new Refusal("file_exists() of a {$subject['kind']}", $line);
        }

        return $this->backend->call('path_exists', [$this->operand($subject)]);
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

        $equals = self::$target === 'php' ? '===' : '==';

        return $this->backend->call('arg_count', [$this->operand($subject)])
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
        return $subject['kind'] === 'sole-class' && self::$target === 'php'
            ? $this->handlePart($subject, 'listPhp', $line) . ' === []'
            : null;
    }

    /** `count($node->getArgs()) === N` and `$args === []`. */
    private function equality(Expr $left, Expr $right, int $line): string
    {
        if ($left instanceof FuncCall && $left->name instanceof Name && $left->name->toString() === 'count') {
            return $this->countComparison($left, $right, $line);
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
                return $this->backend->call('arg_count', [$this->operand($subject)])
                    . (self::$target === 'php' ? ' === 0' : ' == 0');
            }

            $emptyClasses = $this->noClassNamed($subject, $line);
            if ($emptyClasses !== null) {
                return $emptyClasses;
            }

            // A list the rule built is as emptiable as one the vocabulary produced; it is absent from `ITERABLES`
            // only because nothing iterates it back.
            if (isset(Vocabulary::ITERABLES[$subject['kind']]) || $subject['kind'] === 'list') {
                if (self::$target === 'php') {
                    return $this->operand($subject) . ' === []';
                }

                return "{$subject['rust']}.is_empty()";
            }

            throw new Refusal("empty-array comparison against a {$subject['kind']}", $line);
        }

        // $flag === true / $flag === false
        if ($left instanceof Variable && is_string($left->name)
            && ($this->locals[$left->name]['kind'] ?? null) === 'bool'
            && ($wanted = $this->isBooleanLiteral($right)) !== null
        ) {
            $flag = $this->operand($this->locals[$left->name]);

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

            return $this->backend->call('is_uppercase', [$this->operand($inner)]);
        }

        $betweenStrings = $this->stringComparison($left, $right, $line);
        if ($betweenStrings !== null) {
            return $betweenStrings;
        }

        // <name>->toString() === 'literal'   /   <string local> === 'literal'
        if ($left instanceof MethodCall && (string) $left->name === 'toString') {
            return $this->nameEquals($this->resolve($left->var, $line), $this->stringLiteral($right, $line), $line);
        }

        // `->toLowerString() === 'null'` is the same comparison with the case folded, and the name helpers already
        // fold case — so the fold is what the rule wrote, not something extra to emit.
        if ($left instanceof MethodCall
            && $left->name instanceof Identifier
            && $left->name->toString() === 'toLowerString'
        ) {
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

        // A value the rule computed, against a literal: `strtolower($name->getLast()) !== 'request'` is two
        // strings, and the case fold is the rule's own. Compared as strings rather than through the name
        // helpers, which fold case again and would make the fold invisible.
        if (in_array($subject['kind'], ['bytes', 'class-name'], true) && self::$target === 'php') {
            return $this->operand($subject) . ' === ' . $this->backend->bytes($this->stringLiteral($right, $line));
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
        if (self::$target !== 'php') {
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
     * @param Descriptor $subject
     */
    private function nameEquals(array $subject, string $literal, int $line): string
    {
        return match ($subject['kind']) {
            'local-name' => "support::local_name_is({$subject['rust']}, b\"{$literal}\")",
            'name-selector' => $this->backend->call('selector_is', [$this->operand($subject), $this->backend->bytes($literal)]),
            'name-expr' => $this->backend->call('name_equals', [$this->operand($subject), $this->backend->bytes($literal)]),
            // Already a string — a loop's bound item, a helper's parameter, the enclosing namespace. Compared
            // directly, because there is no node left to ask.
            'bytes', 'class-name' => self::$target === 'php'
                ? $this->operand($subject) . ' === ' . $this->backend->bytes($literal)
                : $this->operand($subject) . ' == b"' . $literal . '"',
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
            // Both are already resolved names, so the comparison is a string one — and both are compared against
            // a fully-qualified name, because that is what php-parser hands a rule after PHPStan has resolved
            // the AST.
            'attribute-name' => 'Support::nameIs(Support::attributeName($context, ' . $this->operand($subject) . '), ' . $this->backend->bytes($literal) . ')',
            'method-name' => 'Support::nameIs(Support::methodName(' . $this->operand($subject) . '), ' . $this->backend->bytes($literal) . ')',
            // `$node->name->toString() === 'class'` on a member name: the part carries its own text, and PHP
            // compares member names case-insensitively, which `nameIs()` already does.
            'name-part' => 'Support::nameIs(Support::textOf(' . $this->operand($subject) . '), ' . $this->backend->bytes($literal) . ')',
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
        $this->unreachableGuard = $reason;

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
        if (self::$target !== 'php') {
            $this->narrowedToClass = true;

            return $this->alwaysHolds('the class declaration hook fires for classes, never for an interface');
        }

        return $this->backend->call('declaration_kind_is', ['$context', '$node', $this->backend->bytes('Class')]);
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
        if (self::$target !== 'php') {
            throw new Refusal("a {$described} declaration test, which only the PHP target carries");
        }

        return $this->backend->call('declaration_kind_is', ['$context', '$node', $this->backend->bytes($kind)]);
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
            && isset($this->arrayConstants[$this->memberName($expr->name, $line)])
        ) {
            $rendered = $this->byteSliceList($this->arrayConstants[$this->memberName($expr->name, $line)]);

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
            if (self::$target !== 'php') {
                throw new Refusal('getType() as a value, which only the PHP target carries', $line);
            }

            $of = $this->resolve($expr->getArgs()[0]->value, $line);

            // Compared against the vocabulary's own navigation to this node kind's receiver rather than a
            // hardcoded path, so a hook whose receiver is reached differently cannot pass by accident. The
            // receiver arrives ready-made under `ReceiverType`, so it is preferred where it applies.
            $receiver = Vocabulary::FIELDS[$this->nodeKind]['var'][2] ?? null;
            if ($receiver !== null && ($of['php'] ?? null) === $receiver) {
                $this->usesReceiverType = true;

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

            $this->usesExpressionTypes = true;

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
                if (self::$target !== 'php') {
                    throw new Refusal('a constant type\u{2019}s value, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'php' => 'Support::constantStringOf(' . $this->operand($of) . ')',
                ];
            }
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

            $helper = $of['kind'] === 'type-without-null' ? 'soleObjectClassIgnoringNull' : 'soleObjectClass';

            // Two renderings of one question, because rules ask it two ways. Most ask `count(..) === 1` and
            // then use the name, which is what `sole-class` is for. One iterates the list instead, and giving
            // that the single-class reduction would go silent on a union receiver — narrower than the rule, in
            // the direction this project refuses. So the list travels with it.
            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'sole-class',
                'php' => 'Support::' . $helper . '(' . $this->operand($of) . ')',
                'listPhp' => 'Support::objectClasses(' . $this->operand($of) . ')',
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

            if (self::$target !== 'php') {
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
            && isset($this->caches[$expr->var->name])
        ) {
            $cached = $this->caches[$expr->var->name];
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
            if (self::$target !== 'php') {
                throw new Refusal('a resolved function name, which only the PHP target carries', $line);
            }

            $named = $this->resolve($expr->var->getArgs()[0]->value, $line);

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => $this->backend->call('function_name', ['$context', $this->nameText($named, $line)]),
            ];
        }

        if ($expr instanceof MethodCall && $this->memberName($expr->name, $expr->getStartLine()) === 'getName' && $expr->args === []) {
            $base = $this->resolve($expr->var, $line);

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
                if ($this->classFrom !== 'metadata' && self::$target !== 'php') {
                    throw new Refusal('getName() outside a declaration hook', $line);
                }

                $this->usesMetadata = $this->classFrom === 'metadata';

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
                if (self::$target !== 'php') {
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
            if (self::$target !== 'php') {
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
            if (self::$target !== 'php') {
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
                if (self::$target !== 'php') {
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

            if (self::$target !== 'php') {
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
            if (self::$target !== 'php') {
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
            if (self::$target !== 'php') {
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
                    'php' => 'Support::docblockTags(' . $this->operand($base) . ', ' . $this->backend->bytes($tag) . ')',
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
            if (self::$target !== 'php') {
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

            if (self::$target !== 'php') {
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
            if (self::$target === 'php') {
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
            if (self::$target !== 'php') {
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
            if (self::$target !== 'php') {
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

                return ['rust' => $this->backend->bytes($folded), 'kind' => 'bytes', 'php' => $this->backend->bytes($folded)];
            } catch (Refusal) {
                if (self::$target !== 'php') {
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
                if (self::$target !== 'php') {
                    throw new Refusal('a joined list, which only the PHP target carries', $line);
                }

                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'bytes',
                    'php' => 'implode(' . $this->backend->bytes($glue->value) . ', ' . $this->operand($of) . ')',
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

            if (self::$target !== 'php') {
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

            $counted = $this->backend->call('arg_count', [$this->operand($subject)]);

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

            // Keyed on what the node *is*, not on what the hook fired for. A descriptor carries `as` when its
            // node kind is known from where it came — every node a subtree search found is of the kind that was
            // searched for — and the hook's own node is the kind the hook targets.
            $navigating = $base['kind'] === 'hook-node' ? $this->nodeKind : ($base['as'] ?? null);
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
            if ($base['kind'] === 'expr' && $property === 'value' && self::$target === 'php') {
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
            if ($base['kind'] === 'expr' && $property === 'name' && self::$target === 'php') {
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

            // `$node->name->name` on a Name node is its text, the same thing `->toString()` yields. Both
            // spellings appear in real rules, so both resolve to the name itself rather than a new kind.
            $declared = $this->resolvePropertyDeclaration($base, $property, $key);
            if ($declared !== null) {
                return $declared;
            }

            if (in_array($base['kind'], ['name-expr', 'name-selector', 'local-name'], true) && $property === 'name') {
                return $base + ['key' => $key];
            }

            if (self::$survey) {
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
            if ($subject['kind'] === 'class-reflection' && self::$target === 'php') {
                $subject = [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'named-class',
                    'php' => 'Support::enclosingClassName($context, $node)',
                ];
            }

            if ($subject['kind'] === 'named-class') {
                $asked = $this->memberName($expr->name, $expr->getStartLine());
                $named = $asked === 'getConstructor'
                    ? $this->backend->bytes('__construct')
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
            if (self::$target !== 'php') {
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
            if (self::$target !== 'php') {
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
            if (isset($this->listAccumulators[$expr->name])) {
                return [
                    'rust' => self::PHP_ONLY,
                    'kind' => 'list',
                    'php' => '$' . $expr->name,
                    'as' => $this->listItemKinds[$expr->name] ?? '',
                ];
            }

            if (isset($this->locals[$expr->name])) {
                return $this->locals[$expr->name];
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
                if (self::$target !== 'php') {
                    throw new Refusal('a name\u{2019}s last segment, which only the PHP target carries', $line);
                }

                $text = in_array($of['kind'], ['bytes', 'class-name'], true)
                    ? $this->operand($of)
                    : $this->backend->call('text_of', [$this->operand($of)]);

                return ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => $this->backend->call('last_name_segment', [$text])];
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

            if (self::$target !== 'php') {
                throw new Refusal('a case fold as a value, which only the PHP target carries', $line);
            }

            $text = in_array($of['kind'], ['name-selector', 'local-name', 'name-expr'], true)
                ? $this->backend->call('text_of', [$this->operand($of)])
                : $this->operand($of);

            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => $this->backend->call($expr->name->toString() === 'strtolower' ? 'lower_bytes' : 'upper_bytes', [$text]),
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

            if (self::$target !== 'php') {
                throw new Refusal('the analysed file’s directory, which only the PHP target carries', $line);
            }

            return ['rust' => self::PHP_ONLY, 'kind' => 'bytes', 'php' => 'Support::fileDirectory($context)'];
        }

        // `$array->items[0]` — an element by position. Null when the literal has fewer, which is what the
        // rule's own `instanceof ArrayItem` guard then tests.
        if ($expr instanceof ArrayDimFetch && $expr->dim instanceof Int_) {
            $list = $this->resolve($expr->var, $line);
            if ($list['kind'] === 'array-items') {
                if (self::$target !== 'php') {
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
            && ($this->locals[$expr->var->name]['kind'] ?? null) === 'captures'
            && $expr->dim instanceof Expr
        ) {
            return [
                'rust' => self::PHP_ONLY,
                'kind' => 'bytes',
                'php' => $this->capturedGroup(
                    $this->locals[$expr->var->name],
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
                if (self::$target !== 'php') {
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

        if ($expr instanceof Variable && is_string($expr->name) && isset($this->literals[$expr->name])) {
            return $this->literals[$expr->name];
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
            if (isset($this->intConstants[$name])) {
                return $this->intConstants[$name];
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
        $fqcn = $this->useMap[$alias] ?? $written;

        // An unimported `Foo::class` in a namespaced file is `<namespace>\Foo`, and PHP resolves it that way
        // whether or not the rule wrote an import. Taking the short name instead emits a comparison against a
        // name no ancestor has, so the rule loads, runs and matches nothing — the failure mode that looks like
        // coverage. Only for a name that is neither imported nor already qualified.
        if ($fqcn === $written
            && $this->ruleNamespace !== null
            && ! $expr->class instanceof FullyQualified
            && ! str_contains($written, '\\')
            && ! in_array($written, ['self', 'static', 'parent'], true)
        ) {
            $fqcn = $this->ruleNamespace . '\\' . $written;
        }

        $constant = $this->memberName($expr->name, $expr->getStartLine());

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
        foreach ($this->index->paths($short, $this->file) as $path) {
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
    /** Node-kind names PHP reserves, which the SDK spells with a trailing underscore. */
    /**
     * Node kinds the SDK renames because PHP will not let the case be referenced.
     *
     * Exactly one, checked against the enum rather than assumed from a list of reserved words: PHP allows a
     * reserved word after `::`, so `NodeKind::Foreach` is legal and is declared bare. `class` is the exception,
     * because `::class` yields the class-name string instead — which is why the SDK spells that one case `Class_`.
     *
     * This list used to hold twenty-six words. Nothing was wrong while `Class` was the only reserved kind any hook
     * targeted; the first other one, `Foreach`, emitted `NodeKind::Foreach_` and the enum has no such case.
     */
    /**
     * php-parser classes that stand for a family rather than a node.
     *
     * A rule returning one of these from `getNodeType()` is asking PHPStan for every node beneath it. Listed so
     * the refusal can say that, instead of reporting a missing hook mapping for something no single hook covers.
     *
     * @var list<class-string>
     */
    private const array MULTI_KIND_NODE_TYPES = [
        'PhpParser\Node\Expr',
        'PhpParser\Node',
        'PhpParser\Node\Stmt',
    ];

    private const array PHP_RESERVED_KINDS = ['class'];

    /** Node kinds that carry an argument list. */
    private const array ARGUMENT_LIST_KINDS = ['MethodCall', 'FunctionCall', 'StaticMethodCall', 'NullSafeMethodCall', 'Instantiation'];

    /** Hook kinds that are a class-like declaration, where the node under analysis is always named. */
    private const array CLASS_LIKE_HOOK_KINDS = ['Class', 'Interface', 'Trait', 'Enum'];

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

        [$good, $bad] = (new ExampleReader(self::$examplesDir))->forRule(
            $className,
            str_contains($this->renderAll(), 'support::file_ends_with('),
        );
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
    /**
     * The rule's message, and whether it is already an expression rather than a quoted literal.
     *
     * A missing message means nothing found the report; one that is neither a literal nor a `sprintf()` is a
     * shape the emitter has no recipe for. Both are refusals rather than a guessed string, because a plugin
     * reporting the wrong text is a plugin nobody can check against the original.
     *
     * @return array{string, bool}
     */
    private function reportableMessage(): array
    {
        if ($this->message === null) {
            throw new Refusal('could not find the reported message');
        }

        $isLiteral = str_starts_with($this->message, '"') && str_ends_with($this->message, '"');
        $isFormatted = str_starts_with($this->message, 'sprintf(') || $this->messageIsExpression;
        if (! $isLiteral && ! $isFormatted) {
            throw new Refusal('PHP target: message is neither a literal nor a sprintf(): ' . $this->message);
        }

        return [$this->message, $isFormatted];
    }

    /**
     * A plugin that is an after-analysis hook and nothing else.
     *
     * For a rule whose every check was handed to a whole-project pass: there is no node to dispatch on, so
     * registering a node hook would mean declaring targets the plugin never looks at. `getTargets()` and
     * `getRequirements()` still exist because the interface asks for them, empty, exactly as
     * {@see emitAggregate()} leaves them.
     */
    private function emitAfterOnly(string $className): string
    {
        $identifier = 'transpiled/' . str_replace('_', '-', $this->snake($className));
        $constructor = $this->emitConstructor();
        $passes = '';
        $runtime = [];
        foreach ($this->afterChecks as $pass) {
            $passes .= "        {$pass};\n";
            $runtime[explode('::', $pass)[0]] = true;
        }

        ksort($runtime);
        $imports = '';
        foreach (array_keys($runtime) as $class) {
            $imports .= "use Sandermuller\\PhpstanToMago\\Runtime\\{$class};\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.

namespace Transpiled;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
{$imports}
final class {$className} implements AfterAnalysisHook, Plugin
{
{$constructor}
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
        \$registry->registerAfterAnalysisHook(\$this);
    }

    /** @return list<never> */
    public function getTargets(): array
    {
        return [];
    }

    /** @return list<never> */
    public function getRequirements(): array
    {
        return [];
    }

    public function afterAnalysis(AfterAnalysisContext \$context): void
    {
{$passes}    }
}

PHP;
    }

    /**
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

        // Every check this rule has is a whole-project pass, so there is nothing for a node hook to do and no
        // message for it to report. The plugin is the after hook alone — the same shape an aggregate takes.
        if ($this->message === null && $this->afterChecks !== []) {
            return $this->emitAfterOnly($className);
        }

        [$reported, $isFormatted] = $this->reportableMessage();

        $body = $this->gate($hook) . $this->renderAll();

        // A rule that already reported inside a loop has nothing to report at the end. Emitting it
        // anyway fired on every declaration, and PHP leaves the loop variable set after the loop, so
        // the message even looked plausible.
        // The anchor applies here too. It did not, and that was the whole defect: an anchor read from a loop item
        // is a variable the emitted `foreach` binds, and this report sits after the loop — so a rule asking for a
        // member's line silently got the class's span instead, through a path that looked right. Refused rather
        // than substituted, for the same reason the comment above gives: PHP leaves the loop variable set, so the
        // wrong answer would look plausible.
        if ($this->anchorNeedsLoop && ! $this->reportedInline) {
            throw new Refusal(
                'a report anchored on a loop item but emitted after the loop, where the item is no longer bound',
            );
        }

        $trailingReport = $this->reportedInline ? '' : strtr(<<<'REPORT'
        $context->report(
            Level::Error,
            {CODE},
            Issue::new({MESSAGE}, {ANCHOR}, 'here'),
        );

REPORT, ['{ANCHOR}' => $this->anchor ?? $this->defaultAnchor()]);
        $message = $isFormatted ? $reported : $this->backend->bytes(substr($reported, 1, -1));
        // A rule that classifies what it found reports under a code decided at analysis time, so the code is
        // an expression there; quoting it would report under the source text of the interpolation.
        $code = $this->identifier ?? 'transpiled.' . $this->snake($className);
        $code = $this->identifierIsExpression ? $code : $this->backend->bytes($code);

        $trailingReport = str_replace(['{CODE}', '{MESSAGE}'], [$code, $message], $trailingReport);
        // `NodeKind::Class` does not reference the enum case: PHP special-cases `::class` and yields
        // the class-name string, so the worker rejects the target with a type error naming neither the
        // rule nor the kind. The SDK names that case `Class_`, the same trailing-underscore convention
        // this file already uses for Rust keywords, so reserved names take the suffix.
        // `NodeKind::Class` does not reference the enum case: PHP special-cases `::class` and yields
        // the class-name string, so the worker rejects the target with a type error naming neither the
        // rule nor the kind. The SDK names that case `Class_`, the same trailing-underscore convention
        // this file already uses for Rust keywords, so reserved names take the suffix.
        $kind = implode(', ', array_map(
            static fn (string $case): string => 'NodeKind::' . $case
                . (in_array(strtolower($case), self::PHP_RESERVED_KINDS, true) ? '_' : ''),
            $this->targetKinds($hook),
        ));
        $identifier = 'transpiled/' . str_replace('_', '-', $this->snake($className));

        // Requirements are opt-in per capability: a rule that reads a type without asking for it gets
        // null, which would silently turn every check on it into a pass.
        $requirements = 'FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText';
        if ($this->usesReceiverType) {
            $requirements .= ', FileAnalysisRequirement::ReceiverType';
        }

        if ($this->usesExpressionTypes) {
            $requirements .= ', FileAnalysisRequirement::ExpressionTypes';
        }

        $constructor = $this->emitConstructor();

        // A rule with a whole-project check is both hook kinds at once, in one plugin. The node hook keeps
        // per-node dispatch and the requirements above for the checks that are node-shaped; the pass runs
        // once over the finished analysis. Empty for every other rule, which is what keeps those files
        // byte-identical.
        $implements = 'Plugin, NodeAnalysisHook';
        $afterImports = '';
        $runtimeImports = '';
        foreach (array_keys($this->runtimeHelpers) as $helper) {
            // `Support` is in the template already, and PHP rejects a duplicate `use` outright — the plugin
            // would not compile, let alone report.
            if ($helper !== 'Support') {
                $runtimeImports .= "use Sandermuller\\PhpstanToMago\\Runtime\\{$helper};\n";
            }
        }

        $afterRegister = '';
        $afterMethod = '';
        if ($this->afterChecks !== []) {
            $implements = 'Plugin, NodeAnalysisHook, AfterAnalysisHook';
            $afterImports = "use Mago\Sdk\Analyzer\AfterAnalysisContext;\nuse Mago\Sdk\Analyzer\AfterAnalysisHook;\n";
            $afterRegister = "\n        \$registry->registerAfterAnalysisHook(\$this);";
            $passes = '';
            $runtime = [];
            foreach ($this->afterChecks as $pass) {
                $passes .= "        {$pass};\n";
                $runtime[explode('::', $pass)[0]] = true;
            }

            ksort($runtime);
            foreach (array_keys($runtime) as $class) {
                $runtimeImports .= "use Sandermuller\\PhpstanToMago\\Runtime\\{$class};\n";
            }

            $afterMethod = "\n    public function afterAnalysis(AfterAnalysisContext \$context): void\n    {\n{$passes}    }\n";
        }

        // One method per check, in the order the rule asks them. Empty for every rule that asks one, which is
        // what keeps those files byte-identical.
        $checkMethods = '';
        foreach ($this->checks as $check) {
            $checkMethods .= "\n    private function {$check['name']}({$check['signature']}): void\n"
                . "    {\n{$check['body']}    }\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

// GENERATED by phpstan-to-mago from {$className}. Do not edit by hand.

namespace Transpiled;

{$afterImports}use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;
{$runtimeImports}use Sandermuller\PhpstanToMago\Runtime\Support;

final class {$className} implements {$implements}
{
{$constructor}
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
        \$registry->registerNodeAnalysisHook(\$this);{$afterRegister}
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
{$afterMethod}{$checkMethods}
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
