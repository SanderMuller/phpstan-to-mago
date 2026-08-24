<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;

/**
 * Everything one rule's translation mutates, in one place.
 *
 * `Transpiler` does four jobs — orchestration, statement translation, expression translation, emission — and
 * they all read and write the same state: `$locals`, `$lines`, `$indent`, `$refinements`, `$nodeKind` and
 * sixty-five more. That shared state is why the four cannot be separated by moving methods around, and this
 * object is what they are separated *behind*.
 *
 * Plain public properties, deliberately. Helper inlining saves and restores whole groups of them around a
 * call — `$saved = $context->constants; try { .. } finally { $context->constants = $saved; }` — and that
 * push/pop idiom is load-bearing scoped state, not an accident. Accessors or immutability would have to
 * reproduce it, less clearly.
 *
 * One instance per rule file, like the `Transpiler` that owns it.
 *
 * @phpstan-import-type Descriptor from Transpiler
 * @phpstan-import-type Declaration from Transpiler
 * @phpstan-import-type RecordFields from Transpiler
 */
final class TranslationContext
{
    /**
     * @param Backend $backend renders {@see $lines} into the target language
     * @param SourceIndex $index resolves a class named in the rule to the file that declares it
     */
    public function __construct(
        public readonly Backend $backend,
        public readonly SourceIndex $index,
    ) {}

    /** @var list<Stm> emitted statements, in source order: guards and bindings interleave */
    public array $lines = [];

    /** Whether the body asks for the receiver's inferred type, which the PHP target must request. */
    public bool $usesReceiverType = false;

    /**
     * Whether the rule asks for the inferred type of a sub-expression.
     *
     * A separate requirement from `ReceiverType`, and a heavier one: it embeds every expression type in the
     * file, so it is requested only by a rule that asks for a position the ready-made ones do not cover.
     */
    public bool $usesExpressionTypes = false;

    /** The Rust expression producing the reported message, from the report site. */
    public ?string $message = null;

    /**
     * Whether the message is an expression the transpiler built rather than a literal or a `sprintf()`.
     *
     * An interpolated message becomes a concatenation, which is neither of the two shapes the emitter used to
     * accept. Recorded rather than sniffed out of the rendered text: `. ` appears inside message literals too.
     */
    public bool $messageIsExpression = false;

    /** PHPStan's `->identifier(..)`, which becomes the issue's code so the two tools agree on it. */
    public ?string $identifier = null;

    /**
     * Every identifier the rule reports under, in the order it takes them.
     *
     * `$identifier` holds the last one, which is what the trailing report uses. A merged rule reports under
     * one identifier per check, and a harness comparing the two tools on a single identifier would measure
     * one check and pass on the others' silence.
     *
     * @var list<string>
     */
    public array $identifiers = [];

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
    public array $afterChecks = [];

    /** @var array<string, string> the rule's own string constants, by name */
    public array $constants = [];

    /** @var array<string, list<string>> the rule's own list-of-string constants, by name */
    public array $arrayConstants = [];

    /**
     * The keys of the rule's own map constants, by name.
     *
     * `['dump' => true, 'dd' => true]` is a set spelled as keys, and `isset(self::X[$name])` asks whether a
     * name is in it. The values are always `true` in the corpus and carry nothing.
     *
     * @var array<string, list<string>>
     */
    public array $constantKeys = [];

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
    public array $literals = [];

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
    public array $caches = [];

    /**
     * Whether the identifier is an expression rather than a literal, so it is emitted unquoted.
     *
     * A rule that reports under a code decided at analysis time — `"...noDebugIn{$namespace}"` — has one
     * identifier expression, not several identifiers. Quoting it would report under the source text.
     */
    public bool $identifierIsExpression = false;

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
    public array $configured = [];

    /**
     * Constructor properties holding a PHPStan service, by property name, mapped to the service.
     *
     * Kept apart from the configured ones so a rule reading one is refused *by the name of the service*,
     * which says what would have to be translated for the rule to work, rather than as an unknown local.
     *
     * @var array<string, string>
     */
    public array $injected = [];

    /** Whether the rule reads a configured property, so the emitted plugin needs a constructor. */
    public bool $usesConfiguration = false;

    /**
     * Constructor properties computed in the body from configured values, constants or literals.
     *
     * Translatable in principle and not translated yet, so a rule reading one is refused as a derived
     * property rather than as an unknown local. Naming what it is keeps the refusal useful.
     *
     * @var array<string, string>
     */
    public array $derived = [];

    /**
     * Constructor derivations the generated plugin can carry verbatim, by property name.
     *
     * Only for the PHP target: the emitted plugin is PHP, so a derivation over configured values, literals
     * and pure functions is the same code. Rust has no equivalent, so the Rust targets refuse it.
     *
     * @var array<string, Expr>
     */
    public array $pure = [];

    /** The Mago node kind the hook's `node` currently refers to, for FIELDS lookup. */
    public string $nodeKind = '';

    /** @var array<string, Descriptor> PHP local name -> descriptor */
    public array $locals = [];

    /** @var array<string, string> alias -> fully qualified class name, from the rule's `use` list */
    public array $useMap = [];

    public int $bindCounter = 0;

    /**
     * Set when the rule asks what the scope knew *before* this node — `hasVariableType()` and
     * friends. Such a rule has to run on the pre hook, or it sees the state the node just created.
     */
    public bool $readsPriorScope = false;

    /**
     * Constructor parameters the rule package's neon does not wire, by the rule that declares them.
     *
     * Not the same as an unconfigured *package*: the package can wire other rules and skip this one. Reading
     * such a property has to refuse by naming that, or it falls through to the generic path and refuses with
     * `unknown local $this`, which points at the receiver instead of at the missing wiring.
     *
     * @var array<string, string>
     */
    public array $unwired = [];

    /**
     * Where the emitted report points, when the rule moves it off the node the hook fired for.
     *
     * Null means the hook's own node, which is what almost every rule wants. A rule that loops a class-like's
     * members and reports per member does not: PHPStan's `->line($member->getLine())` is what puts each finding
     * on its own member, and without carrying that across, every finding in such a rule lands on the class.
     */
    public ?string $anchor = null;

    /**
     * Whether {@see $anchor} names something only a loop body has in scope.
     *
     * An anchor read from a loop item is a PHP variable the emitted `foreach` binds, so a report emitted after that
     * loop would name a variable that is not there — a wrong span at best, and nothing static would see it. Every
     * rule in the corpus reports inside the loop that anchored it; this is what keeps that true.
     */
    public bool $anchorNeedsLoop = false;

    /**
     * A local holding a built rule error whose report has not been emitted yet, inside a loop.
     *
     * Null everywhere else. The trailing report is right for a rule whose guards bail out of `analyze()`, and
     * wrong for one whose guards `continue` — there the report has to sit inside the loop, and the `return` that
     * follows the assignment is what says the rule stops at the first finding.
     */
    public ?string $pendingReport = null;

    /**
     * Integer class constants of the rule being translated, by name.
     *
     * A rule names its thresholds — `self::MAX_NESTED_FOREACHES` — and the number is what it compares against, so
     * it folds here rather than becoming something the generated plugin carries.
     *
     * @var array<string, int>
     */
    public array $intConstants = [];

    /**
     * Constructor properties holding a stateless subtree finder, by name.
     *
     * `NodeFinder` carries nothing, so a rule that injects one instead of constructing one is asking the same
     * question either way, and the property reads as the same handle `new NodeFinder()` produces.
     *
     * @var array<string, true>
     */
    public array $finders = [];

    /** True while translating a loop body, so `continue` and inline reports are legal. */
    public bool $inLoop = false;

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
    public int $loopDepth = 0;

    /** The loop depth when the innermost inlined helper was entered; see {@see $loopDepth}. */
    public int $helperLoopFloor = 0;

    /**
     * True while inlining a helper whose return value *is* the finding.
     *
     * Inside such a helper `return null` means "no finding", the same exit as a rule's `return []`, and a
     * returned `RuleErrorBuilder` chain is the report itself.
     */
    public bool $inErrorHelper = false;

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
    public ?array $recordFields = null;

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
    public array $reportConditions = [];

    /**
     * Array constants the generated plugin has to declare, because a carried derivation names them.
     *
     * The value expression rather than a resolved list: a lookup table is written `['dump' => true]`, whose
     * data is in the keys, and the derivation is copied verbatim — so the constant is printed verbatim too.
     *
     * @var array<string, Expr>
     */
    public array $carriedConstants = [];

    /** How many independent checks have reported at rule level; see {@see inlineErrorHelper()}. */
    public int $checksReported = 0;

    /**
     * Whether this rule is emitted as one public method per check.
     *
     * A merged rule asks several *independent* checks of the same node in one pass, for the dispatch
     * saving. Flattened into one body, the first check's guards become the rule's guards, so its "not my
     * case" exits the rule and every later check is unreachable. One method per check gives each check's
     * guards their own thing to return from.
     *
     * Decided before translation, and only for a rule that really asks two, so a rule with one check
     * emits exactly what it emits today.
     */
    public bool $checkMode = false;

    /**
     * The checks emitted so far, each already rendered.
     *
     * @var list<array{name: string, signature: string, body: string}>
     */
    public array $checks = [];

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
    public array $reportedErrors = [];

    /** Set once a report has been emitted inside the body; suppresses the trailing one. */
    public bool $reportedInline = false;

    /** Current emission indentation, which a loop body increases. */
    public int $indent = 8;

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
    public array $accumulatorSlots = [];

    /**
     * Accumulators that turned out to hold nodes rather than findings.
     *
     * Kept apart from `$locals` because a loop saves and restores those around its body, which is right for the
     * loop variable and wrong for this: the append happens inside the loop and the count is read after it, so a
     * promotion recorded in `$locals` would be discarded exactly where it is needed.
     *
     * @var array<string, true>
     */
    public array $listAccumulators = [];

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
    public array $listItemKinds = [];

    /** What the report's annotation points at; a loop reports per item, not per node. */
    public string $reportSpan = 'node.span()';

    /**
     * Whether the message and identifier last taken have been reported under.
     *
     * A rule reporting two different things about one subject takes a message, reports it, then takes another.
     * Without this the second take reads as an overwrite and is refused — and refusing it is still right when
     * no report came in between, because then the first message was never emitted anywhere.
     */
    public bool $reportTaken = false;

    /**
     * Constructor-injected objects whose class this package can read, by property name.
     *
     * A rule package puts a small analyzer on its own class and delegates to it — `$this->enumAnalyzer->
     * detect(..)`. That is the same inlining as a trait's or a parent's method, one indirection further out,
     * and without it every such helper is a vocabulary gap named after somebody's class.
     *
     * @var array<string, string>
     */
    public array $collaborators = [];

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
    public array $valueObjects = [];

    /**
     * Runtime helper classes the emitted plugin has to import, by class name.
     *
     * A plugin importing `Support` alone was enough while every helper lived there. A named stand-in for a
     * collaborator does not, and an import it never makes is a plugin that loads and then fails on the first
     * call — the failure mode `.ai/guidelines/verification.md` opens with.
     *
     * @var array<string, true>
     */
    public array $runtimeHelpers = [];

    /**
     * The node kinds this rule's hook registers, when it registers more than one.
     *
     * Empty for every hook that is one kind, which is all but the expression family. A branch testing
     * `instanceof MethodCall` needs to know whether that kind is among the targets: if it is, the test is a
     * node-kind test; if it is not, the branch never runs.
     *
     * @var list<string>
     */
    public array $hookKinds = [];

    /** The php-parser class the rule's `getNodeType()` names, which decides how many kinds the hook covers. */
    public string $nodeType = '';

    /** Set when the rule narrows `getOriginalNode()` to `Class_`, which the class hook guarantees. */
    public bool $narrowedToClass = false;

    /**
     * Why the guard being translated cannot hold in Mago's model, when that is known.
     *
     * A guard that translates to a constant used to be dropped with one generic comment, whatever the
     * reason. Three of those drops are sound by construction — the node the guard tests for never reaches
     * the hook — and were verified by putting the case in a rule's *good* example and watching the port
     * stay silent. Anything else translating to a constant is a hole, not a proof, so it is refused. This
     * field is what separates the two.
     */
    public ?string $unreachableGuard = null;

    /**
     * Where the enclosing class comes from in the current hook.
     *
     * A declaration hook fires *before* the analyser enters the class, so the block context has no
     * class yet — but the hook is handed the class's metadata. A call hook is the other way round.
     */
    public string $classFrom = 'scope';

    /** Set when the emitted body reads the metadata parameter, so it can be named rather than `_`. */
    public bool $usesMetadata = false;

    /** Set when the class being translated is a Collector rather than a Rule. */
    public bool $isCollector = false;

    /** The collector's own name, used as the key in the cross-file store. */
    public string $collectorName = '';

    /** Set once a collector has emitted its push, so no report is appended. */
    public bool $collected = false;

    /** The class-like whose `self::` constants are currently in scope, i.e. the one being inlined. */
    public ?ClassLike $currentClass = null;

    /**
     * The rule's own class, which `$this` means however deep an inline has gone.
     *
     * `$currentClass` is swapped to whichever trait or parent is being inlined, so a name is resolved against
     * that file's imports. But `$this` does not move: a method on one trait calling a method on a *sibling*
     * trait is ordinary PHP, and looking it up on the trait alone finds nothing — `DetectsInvadeUsage` calls
     * `namespaceStartsWith()`, which lives on `ChecksNamespace`, and both are used by the rule.
     */
    public ?ClassLike $ruleClass = null;

    /**
     * The rule's own import map, for the same reason.
     *
     * @var array<string, string>
     */
    public array $ruleUses = [];

    /**
     * The namespace the rule file declares, so a helper found in the rule's own class-like can be named
     * fully. A helper found through a trait or a parent carries its own, from {@see SourceIndex}.
     */
    public ?string $ruleNamespace = null;

    /**
     * What survey mode assumed on the way to its answer, so a refusal can say so.
     *
     * @var list<string>
     */
    public array $assumed = [];

    /**
     * How deep inlining currently is. Zero means the rule's own body, which several emission decisions read.
     */
    public int $inlineDepth = 0;

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
    public array $inlining = [];

    /** @var array<string, array<string, array{0: string, 1: string, 2?: string}>> expression key -> refined fields */
    public array $refinements = [];
}
