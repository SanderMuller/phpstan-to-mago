# Which rule shapes translate, and the one that blocks a real package

## Covered today

Guard chains, `foreach` with an inline report, `sprintf` messages, inlined helpers, string and integer
comparisons, `instanceof` narrowing into a binding, class-hierarchy questions about the enclosing class, the
declared namespace, membership in a constant set, the names a property declaration declares, and the
receiver's inferred type.

A helper is inlined from the rule, a trait or a parent class, and may answer a question, build the finding, or
forward to the helper that does. A loop inside a predicate helper becomes an "any of them" combinator. A rule
that classifies what it found reports under a code it computes. A configured value becomes a constructor
parameter carrying the rule package's own default, read from that package's `extension.neon`; a constructor
that derives one from configured values, literals and a closed set of pure functions is carried verbatim, and
only by the PHP target.

Surveyed with the tool: `symplify/phpstan-rules` 24 of 96, `hihaho/phpstan-rules` 3 of 20,
`tomasvotruba/type-coverage` 0 of 10, `tomasvotruba/cognitive-complexity` 0 of 3. Every emitted rule is gated
on actually running, per `docs/dogfooding.md`, which supersedes the frontier analysis below.

## The receiver type, now measured

`$scope->getType($receiver)->getObjectClassReflections()` with a `count() === 1` gate is how a rule asks for a
single concrete receiver. It maps onto the SDK's `ReceiverType` requirement, and `Type->atomicTypes` yields a
`NamedObjectType` whose `name` **keeps its casing** — unlike `ClassLikeMetadata->name`, which arrives
lowercased. So the class a receiver names is available, and `Support::methodExists()` and
`Support::parameterName()` take it directly.

Three things this only got right by probing:

- **`$obj?->m(..)` is its own node kind.** `NodeKind::NullSafeMethodCall`, and the `MethodCall` hook does not
  fire for it. That also disposes of a guard PHPStan needs and Mago does not: PHPStan dispatches a *synthetic*
  `MethodCall` for the non-null branch, which is why its rules check a `virtualNullsafeMethodCall` attribute to
  avoid reporting twice. There is no synthetic node here, so that operand folds to false — folded out of the
  disjunction it sits in, not treated as an unreachable guard, which would have taken the real question with it.
- **`TypeCombinator::removeNull()` is load-bearing, not cosmetic.** A `?Widget` receiver arrives as two atomics,
  a `NamedObjectType` and a `SimpleAtomicType` of kind `Null`. Ask "exactly one class" without dropping the null
  and the answer is no. Mutation-checked: with the strict helper, the nullsafe rule reports **nothing at all**
  and the method-call rule loses every nullable receiver. Both look like perfectly well-formed plugins.
- **The `count($classReflections) !== 1` guard is faithful but redundant here.** The helpers behind it are
  null-tolerant, so the method lookup one step later already rejects a union. Removing that guard leaves the
  example pairs green — recorded so nobody reads a union example as proving it.

Two variants of that family stay refused for a fact about the package rather than a gap here: `extension.neon`
registers only the constructor and nullsafe rules, leaving the static-call and method-call ones to
`CombinedStaticCallRule`/`CombinedMethodCallRule`, so their `$firstPartyNamespaces` has nothing behind it.

## The frontier, measured

Every refusal across the four packages, grouped. Re-run it rather than trusting this table — it is a snapshot,
and the point of it is that it beat a guess.

| rules | refusal |
|--:|:--|
| 4 | `guard body is neither return [] nor continue` |
| 3 | `instanceof Identifier` · `no iteration mapped for a subtree` · `list contains something other than a string literal` · `empty-array comparison against a expr` · unwired constructor parameter |

**The `->getParams()` cluster is closed.** It was nine rules, and eight of them were one line in one file:
`Symfony/NodeAnalyzer/SymfonyClosureDetector.php:15` is a shared detector that every
`Rules/Symfony/ConfigClosure/*` rule gates on:

```php
public static function detect(Closure $closure): bool
{
    if (count($closure->getParams()) !== 1) { return false; }
    $onlyParam = $closure->getParams()[0];
    if (! $onlyParam->type instanceof Name) { return false; }
    return $onlyParam->type->toString() === SymfonyClass::CONTAINER_CONFIGURATOR;
}
```

That is one gap behind ten rules, and it is done: `Closure::class` is a hook (PHP target only, like the nullsafe
one), `getParams()` navigates the `FunctionLikeParameterList`, and a parameter's written type reuses the existing
`hint`/`hint-option` machinery. Two things predicted about that work were wrong, and both are worth keeping:

- **The static call needed nothing.** `detect()` is `SymfonyClosureDetector::detect($node)`, and this was written
  up as genuinely new because helper inlining follows `$this->` calls — but `staticHelperPredicate()` already ends
  in "any other static helper whose source we can find is inlined", so it was already handled. Read the code
  before calling something new.
- **`hintIsName()` was wider than the question it answered.** It meant "present, and not a union or intersection",
  where php-parser's `$param->type instanceof Name` distinguishes a class-like from a builtin — `int` is an
  `Identifier` there, not a `Name`. Mago's discriminator is the `Hint`'s child kind, probed across ten written
  forms, with `self`/`static`/`parent` splitting off the `Keyword` row because php-parser counts those as names
  while `array` and `callable` are `Identifier`. Nothing emitted depended on the wider reading.

Unblocking the detector opened the gate for all ten rules, and nine then refused on their own bodies — which is
the expected shape of this kind of win, not a disappointment. `NoBundleResourceConfigRule` emits and agrees, and
the rest are now specific: `->findInstanceOf()` (a `NodeFinder` subtree search, 3 rules), `Expr_New`,
`Expr_ConstFetch`, `->resolveClassConstructorNamesToTypes()`.

**Two holes in the gates showed up while doing it, both now closed.** `Support::fileContains()` and
`fileStartsWith()` were mapped by the transpiler and never written, so a rule using
`str_contains($scope->getFile(), ..)` emitted a plugin that loaded and then killed the worker. The per-fixture
helper check could not see it because no fixture asked, and the fires-gate could not either because it flattened
examples into one directory while that rule's guard tests the file's *path*. So the helper check now runs over the
whole vendored corpus, and the gate copies examples keeping their directories.

## A class-like's own methods, and reporting once per member

The `->getMethods()` cluster is closed too, and it needed more than iteration. All five rules loop a class-like's
methods and report **per method**, and their predicates all read attributes or docblocks — so there was no
stopping point short of both. `SymfonyRequiredMethodAnalyzer::detect()` falls through from `#[Required]` to
`str_contains($docComment->getText(), '@required')`; shipping one without the other would silently miss half the
codebases the rule is for.

What that took, and what each piece cost:

- **Attributes are two levels.** php-parser gives a declaration attribute *groups*, each holding attributes, so
  asking "does it carry this one" is a nested `foreach`. The "any of them" combinator now nests rather than the
  two levels being flattened into a shape the source does not have. Attribute names resolve to their FQN, which
  is what a rule compares against.
- **Docblocks have no owner.** Mago hands comments back as file-level trivia carrying a span and a kind but no
  text, so both the text and the *association* are this package's arithmetic. The rule mirrors php-parser's:
  a docblock belongs to the declaration that follows it with nothing but whitespace in between. Mutation-checked
  — drop the adjacency test and a method with no docblock inherits its neighbour's, which reports it.
- **`isMagic()` is a list, not a prefix.** Seventeen exact names, copied from php-parser's `ClassMethod`. A `__`
  prefix test would catch `__myHelper`, which php-parser does not, and that error reports where the rule is silent.
- **The report has to move.** PHPStan's `->line($classMethod->getLine())` is what puts each finding on its own
  method; without carrying it across, every finding in a loop landed on the class. The emitted report now takes an
  anchor, defaulting to the hook's node.

**Two bugs surfaced doing it, both older than this work.**

`resolve()` mapped any variable named `node` to the hook's node *before* consulting locals, so an inlined helper
whose parameter is called `$node` — `hasRouteAnnotationOrAttribute(ClassLike|ClassMethod $node)` is one — silently
read the wrong subtree. No shipped snapshot changed when it was fixed, which is the only reason nothing was wrong
in the field.

And `$error = RuleErrorBuilder::…; return [$error];` deferred its report to the end of `analyze()`. Correct for a
rule whose guards bail out of the function; wrong inside a loop, where the guards `continue` and the trailing
report then fires whatever they decided. It reported every method of every class. Now the report is emitted at the
`return` inside the loop, which is also what distinguishes "report the first and stop" from "collect them all".

`NoRequiredOutsideClassRule` and `RequiredOnlyInAbstractRule` emit and agree; symplify 21 to 23. The other three
stand on their own bodies: `Strings::match()` with captures feeding the message, a `DataProviderMethodResolver`,
and `new AttributeFinder()`.

## Searching a subtree

The `Expr_New` cluster was `new NodeFinder()` — php-parser's subtree search — and it splits in two. `findInstanceOf`
and `findFirstInstanceOf` name a node class; `find` and `findFirst` take a **closure filter**, usually one that
mutates a variable captured by reference. The first pair is built; the second is the boundary, and about eight call
sites sit behind it.

Three things the search needed:

- **A refuse-by-default class-to-kind table**, not a derived mapping. `ClassLike` is *abstract* in php-parser — it
  means class, interface, trait or enum, four Mago kinds — and the hook table is no substitute, since a hook's kind
  and a search's kind coincide for `Foreach` and diverge for `New_`, which hooks through an expression adapter and
  searches as `Instantiation`.
- **The starting node counts.** php-parser's traverser visits the nodes it is handed, so `findInstanceOf($node, ..)`
  inside a `foreach` finds that `foreach`. Which makes `$node->stmts` — what the rules write — the thing that
  excludes it, and the exclusion belongs there rather than in the search. Skipping the root inside the search would
  give the same answer for every rule in the corpus and the wrong one for the first rule that passes a node;
  mutation-checked, and with the root included the body navigation is what discriminates.
- **`NodeFinder` is stateless**, so a rule that injects one or assigns one in its constructor holds the same handle
  `new NodeFinder()` produces. Without recognising that, three rules refused with *"`$nodeFinder` is computed in the
  constructor and the package wires no configured values"* — pointing at the package's neon for something a neon
  has no business wiring.

**One bug this surfaced was live for every reserved-word hook.** The emitted target came from a list of twenty-six
PHP reserved words, on the assumption that the SDK suffixes such cases the way it spells `Class_`. It does not: PHP
allows a reserved word after `::`, so every other case is declared bare, and `Class` is special only because
`::class` yields a string. `Foreach` was the first other reserved kind any hook targeted, and it emitted
`NodeKind::Foreach_`, which the enum has no case for — a plugin that dies on load. The list now holds `class`, and
the corpus gate reads the enum's own case names rather than trusting a convention.

`ForeachCeptionRule` emits and agrees; symplify 23 to 24.

## Asking something of a node that is not the hook's

Twenty-two of the hundred-odd refusals are one shape: a question asked of a *found* node, a loop item, or a
navigated-to part rather than of the node the hook fired for. It has four faces —
`instanceof X on a expr`, `empty-array comparison against a expr`, `no iteration mapped for a subtree`, and
`no argument list on a Class node` — and the root of the last two is that **field navigation is keyed on the hook's
kind**. `Vocabulary::FIELDS` and `argListPath()` both look up `$this->nodeKind`, so a rule asking a found node for
its arguments gets a refusal naming the *class* it is inside.

A pass at the first face moved **no rules**, and that is worth recording rather than dressing up. What it did do:

- **`$node->name instanceof Identifier` on a class-like is settled by construction**, the same fact that makes
  `isAnonymous()` unreachable — Mago gives an anonymous class its own node kind. Three rules now refuse on what
  they *actually* need next (`getParams()` of a found method, `->getMethod()`, `namespacedName`) instead of on a
  missing node predicate.
- **A class-like's `->name` navigates to the written short name**, which is what `$node->name->toString()` gives a
  rule testing a prefix or a suffix — not the namespaced name.
- **Negating a proven constant folds.** A predicate settled by construction is `true`, and the guard around it is
  `! true`; unfolded, that emitted `if (!(true))` — a guard that can never hold, sitting under whatever comment
  happened to precede it. Every emitted plugin in the corpus had one. They now carry a stated reason instead, and
  a guard that folds the *other* way — one that always exits, so the rule could never report — is refused.

That last change surfaced two predicates returning a bare `true` with no reason recorded, which the
dropped-guard gate then rejected: it demands that a drop be *proved*, not asserted. Both are the same fact as an
already-proven one, so they share its wording, and `RequiredOnlyInAbstractRule`'s good example now carries the
proof — an interface and a trait each declaring what the rule reports on a class. The guard that would have
filtered them is dropped, so if the hook fired for either, the port would report.

### The structural half, done

Field navigation is now relative to the node being asked rather than to the hook's kind. Three parts:

- **Every PHP template in `FIELDS` navigates from `{base}`**, not from a hardcoded `$node`. The same field means the
  same thing wherever the node came from — a rule that finds a method call in a subtree asks it for its arguments
  exactly as a rule hooked on one does. Behaviour-preserving: the snapshots are byte-identical, because the hook's
  own path substitutes `$node`.
- **A descriptor carries `as`** when its node kind is known from where it came. What a subtree search was asked for
  is what it found, so every found node knows its kind, and a loop over them passes that to each item. Only when
  the search names one kind: `ClassLike` covers four, and a node that could be any of them has no single set of
  fields.
- **`getArgs()` takes its receiver**, through the direct path and through the `$args[0]` binding path, which was
  reading the hook's argument list whatever the rule wrote.

That took the shape's refusals from 22 to 14 and **moved no rules**, which is the second pass in a row to move
none. `AvoidFeatureSetAttributeInRectorRule` and `NoOnlyNullReturnInRefactorRule` both now refuse deep in their own
bodies rather than on navigation, which is the point — but a frontier that reads better is not a rule that runs, and
two passes of that is a signal to stop and get the work reviewed rather than start a third.

What surfaced on the way: the fold added in the previous commit exposed a third predicate returning a bare `true`
with no reason — the enclosing-class test inside a declaration hook, which cannot be false there. Three more rules
now refuse with `guard translates to a constant with no reason it cannot hold`, each of which needs a *proved*
reason before it can be dropped, and that gate is what stops the drops being asserted instead.

Two new clusters, both further along than navigation: `instanceof ConstantStringType on a type` (3 rules, which
needs the SDK's `ArgumentTypes`) and `no iteration mapped for a subtree` (3).

### The report anchor had two emission paths and only one carried it

`->line($member->getLine())` moves a finding onto the member a rule is talking about, and the emitted anchor is the
variable the generated `foreach` binds. There are **two** places a report is emitted — `reportNode()` for a report
inside a loop, and a template at the end of `emitPhp()` for the trailing one — and only the first was wired. So a
rule whose report is trailing silently got the class's span instead of the member's, through a path that reads
correctly. PHP leaves a loop variable set after the loop, which is why the wrong answer would have looked plausible;
the comment already above that template records the same hazard for the message.

Both rules in the corpus report *inside* the loop that anchored them, so nothing there exercised it. It took a
deliberately-wrong fixture — `AnchorEscapesLoopRule`, in the same spirit as `MissingHelperRule` — to reach the case,
and writing that fixture is what found the defect rather than confirming the guard. The trailing path now carries
the anchor, and an anchor read from a loop item is *refused* there rather than substituted, because the item is not
bound any more.

### What this replaced, and why the note stays

The previous version of this section named `CombinedMethodCallRule`'s **accumulated findings** as the next shape,
because that was the refusal most recently read. It is real — see below — but it is four rules where the closure
detector is eight behind a single line. The reason the wrong one looked biggest is that the largest cluster,
seventeen rules, all refused with `access path outside the vocabulary: Expr_MethodCall`: php-parser's node class,
the same string for every method call there is. Naming the member in that refusal split it into `->getParams()`,
`->getMethods()` and `->getConstantStrings()`, and changed the answer.

Which is the lesson this document already records twice: a refusal that does not name the construct makes the
frontier unreadable, and reading it wrong costs more than the message costs to fix.

## Accumulated findings, still a real shape

`CombinedMethodCallRule` **accumulates** findings.

```php
$errors = [];
$flagError = $this->positionalFlagErrorForMethodCall($node, $scope, $this->firstPartyNamespaces);
if ($flagError instanceof IdentifierRuleError) {
    $errors[] = $flagError;
}
// ... a second, independent check appends to $errors too
return $errors;
```

Every emitted plugin so far has one report site behind one chain of guards, because every rule so far reports at
most once per node. This one reports zero, one or two findings from a single node, from independent checks. That
needs a second emission shape — one guarded report site per check, sharing the node — rather than another
vocabulary entry. Four rules, so behind the closure detector rather than ahead of it.

`CombinedStaticCallRule` is blocked by something else entirely, and traced rather than
guessed from its refusal: `DetectsFacadeAlias` memoises in a `static $cache = []` because it needs *runtime*
reflection — Laravel registers facade aliases lazily through an autoloader that PHPStan's `ReflectionProvider`
never invokes. A plugin cannot do that at all, so the honest outcome there is a refusal naming the runtime
reflection, not a vocabulary entry for `static`. `CombinedFuncCallRule` is blocked by a constructor derivation
that reaches outside the pure set.

## The shape that blocks `hihaho/phpstan-rules`

That package was **0 of 20** when this was written and is now 3 of 20 — `NoDebugInNamespaceRule` emits and
reports under two computed codes, and both `PositionalFlagArgumentConstructorRule` and
`PositionalFlagArgumentNullsafeMethodCallRule` agree with the original on line and message over pairs holding a
bare flag, a named flag, a spread and a vendor-declared method. `unknown local $this` was the first blocker for five rules and is now the
first blocker for none. What follows is the analysis as it stood; the current frontier is in the spec.

At the time, 17 of the refusals were the same message, `assignment value outside the
vocabulary`, on a line like this:

```php
public function processNode(Node $node, Scope $scope): array
{
    $error = $this->funcDebugError($node->name->name, $scope);

    return $error instanceof IdentifierRuleError ? [$error] : [];
}
```

The rule is a shim. A helper decides *and* builds the message, returning `?IdentifierRuleError`, and the
rule turns a non-null result into a finding.

### What the blocker is not

The helpers live in traits (12 rules) and parent classes (3), so "the transpiler only inlines helpers
declared in the same class body" looks like the whole story. **It is not**, and the difference was settled
by experiment rather than by reading: a probe rule with the identical shape and the helper declared *in the
same class* is refused with the identical message on the identical line.

Cross-class resolution is therefore **necessary but not sufficient**. `Hierarchy` implements it, and it
changes nothing on its own — the survey stays at 0 of 20 — because the refusal happens earlier, in the
assignment handler, before any helper is looked up.

Recording this because the plausible reading cost real time. The refusal *reason* names the assignment; the
callee's location is a fact about those rules that is true and irrelevant.

### What the blocker actually was, and the fix

`inlineMethod()` produces one *expression*, through `translateMethodAsPredicate()`. That fits a helper
answering yes or no inside a condition. It does not fit a helper whose return value **is the finding**.

Fixed in `0d56d18` by inlining such a helper in **statement position**, which needs no new emitted shape
because the shape was already right — a rule is a chain of guards followed by one report:

| in the helper | becomes |
|:--|:--|
| `return null` | the same exit as a rule's `return []` |
| `return RuleErrorBuilder::message(...)->build()` | the rule's message |
| `if (COND) { return <error>; }` | a condition to report under |

Report conditions are collected and emitted as a single guard on their disjunction, so the report the rule
already appends stays the only one. The forwarding `return $error instanceof ... ? [$error] : []` needs no
translation: by then the guards are emitted and the message is taken.

**The bug worth remembering:** the first version treated the helper's trailing `return null` as a bail, which
put an unconditional `return;` in front of the report. The plugin loaded, ran, and silently found nothing.
Emitting proved nothing; running it did. The test counts exits rather than asserting on text.

### The frontier now

The package still emits nothing, but every refusal has moved past the assignment and names something real:

| refusal | what it needs |
|:--|:--|
| `Expr_Isset` in a condition | a vocabulary entry |
| `Stmt_Foreach` inside an inlined helper | loop translation in helper bodies |
| `unknown local $this` | constructor-injected properties as helper arguments, i.e. configuration |
| more than one distinct identifier in one rule | a plugin reporting under several codes |
| cross-file collector aggregation | **this line was wrong.** An `AfterAnalysisHook` runs once per run, sees every file through `ProjectAnalysis`, can `report()`, and reaches per-file syntax through `FileAnalysis::getSourceFile()`. The premise, that a hook sees one file at a time, holds for a node hook only. What actually blocks the collector rules is agreement: the parameter aggregate is implemented and measured at 3079/2927 against PHPStan's 4057/1994, so it is refused with those numbers rather than emitted |

The configuration one is the interesting entry: those rules take `$this->firstPartyNamespaces` and pass it
to the helper, which is the same problem `docs/dogfooding.md` describes at the config level.

### Refusals, and which of them are permanent

Two of the 20 are `FlagArgumentManifestCollector` and `WriteNamedArgumentManifestRule`: a collector and its
`CollectedDataNode` consumer, which aggregate across files. **This was filed here as correct-forever, and that
was wrong** — the same mistake as the sub-expression claim below, and in the same direction. A *node* hook sees
one file at a time; an `AfterAnalysisHook` does not. Its `AfterAnalysisContext->analysis` is a
`ProjectAnalysis` whose `files` is a list of `FileAnalysis`, each yielding a working CST, and it can `report()`.
Reading a fact out of every file in one pass and reporting once is what a collector plus its consumer *is*.

`Runtime\TypeCoverage` in this repository already does it — `declares()` walks `$context->analysis->files` and
reads each `getSourceFile()` — so the mechanism is not merely available, it is in use next to the paragraph
that called it impossible.

What is genuinely constrained is narrower: an after-analysis hook hands over **every file's CST, not the
arbitrary data a PHPStan collector computed**. So a pair cannot map to two hooks; the transpiler has to *fuse*
them into one after-analysis hook that recomputes the measurement. That is what `AggregateRule` does for
`type-coverage`, and why `Vocabulary::AGGREGATES` names each measurement by hand: which fact a collector
contributes is the one thing its body does not tell you. Architecture, not a wall.

Two cautions before building on it, both because the probes behind this were toy-sized: holding a
whole-project CST is untested at corpus scale, and `AfterAnalysisHook` declares no `getRequirements()`, so how
per-file data is provisioned at scale is unmeasured.

Three refusals are ordinary vocabulary gaps: `Expr_Isset` in a condition, `Stmt_Foreach` inside an inlined
helper, and an unknown local `$this`.

## The eight rules a real consumer enables and we refuse

Measured, not chosen: `../hihaho` enables eleven `symplify/phpstan-rules` rules and we emit three
(`NoArrayMapWithArrayCallableRule`, `NoGlobalConstRule`, `UppercaseConstantRule`). These are the other eight,
with the refusal traced to what is missing. Every SDK claim below was probed — `mago cst` for node shapes,
reflection over the SDK classes for metadata — not read off a name.

**Seven of the eight are reachable**, which would move a consumer from 3 of 11 to 10 of 11. The eighth is a
correct refusal. An earlier version of this section said five and blamed the SDK for two of the rest; that was
wrong, and the correction is below.

### Buildable with what the SDK already exposes

| rule | refusal | what it needs |
|:--|:--|:--|
| ~~`StringFileAbsolutePathExistsRule`~~ **done** | was: no hook mapping for `BinaryOp\Concat` | `NodeKind::Binary` plus a **hook gate**: php-parser has a node class per operator, Mago has one kind with the operator as a child, so the hook fires for `+` and `===` too and the emitted body checks `.` before anything else. That gate is what the good example's `__DIR__ === '/nothing-here.php'` case tests. Also added: `__DIR__` and string-literal kind predicates, `->value` on a literal, `dirname($scope->getFile())`, and `file_exists()`. The message carries the path, and Mago's `source->path` is workspace-relative where PHPStan's is absolute — the gate compares message text, which is the only reason that surfaced. |
| ~~`ForbiddenMultipleClassLikeInOneFileRule`~~ **done** | was: no hook mapping for `PHPStan\Node\FileNode` | `NodeKind::Program` is the CST root and therefore the per-file node. Took three pieces: that hook, a list a rule builds (`$x[] = $node` then `count($x)`), and folding `name instanceof Identifier` with a proof. The example pair caught what reading would not have — PHPStan anchors a file-node finding on the first statement, `Program` starts at byte zero, and `Support::fileAnchor()` closes the gap by skipping the opening tag php-parser does not model as a statement. |
| ~~`PreventParentMethodVisibilityOverrideRule`~~ **done** | was: access path outside the vocabulary: `->getParentClassesNames()` | All of it was present under other names — `parentClasses`, `classLikeExists`, `getDeclaringMethod`, and `FunctionLikeMetadata->visibility` for the parent's. Four features came with it: a condition bound to a name (`$isProtected = ! a && ! b`), a helper that picks between written words folded into nested ternaries, visibility of a *reflection* as against a *declaration*, and `Support::asPart()` for handing the hook's own node to helpers that navigate a part. The example uses a **private** parent method widened to public, because that is the case PHP allows and the rule catches — narrowing a public method is a fatal error and could not be a fixture. |

### Buildable behind one contained new feature

Both need the same generalisation: **inlining a helper that lives on a collaborator** rather than on the rule,
a trait, or a parent class. The machinery for inlining exists; what it does not do is follow a
constructor-injected object or a static call into another class.

| rule | refusal | what it needs |
|:--|:--|:--|
| `RequireUniqueEnumConstantRule` | method call outside the vocabulary `->detect()` | `EnumAnalyzer::detect()` is three questions we can each already ask — an `@enum` docblock annotation, descent from `MyCLabs\Enum\Enum`, or `\Enum\` in the class name — reached through an injected collaborator that itself injects a doc parser. Also needs collection vocabulary the corpus has not forced yet: duplicate detection over constant values, and `implode` inside the message. |
| `PublicStaticDataProviderRule` | access path outside the vocabulary: `DataProviderMethodResolver::match()` | A static call on a helper class, whose body is `preg_match('/@dataProvider\s+(?<method_name>\w+)/', ...)` over a docblock — the named-capture shape already on this list. Two further gaps sit behind it: looking a method up by a name computed at analysis time, and **two different messages from one rule**, where the transpiler tracks one (`Transpiler::$message` is a single `?string`). |

### An after-file hook, which is where sub-expression types live

**Correcting this doc's own earlier claim**, which said a node hook's `FileAnalysisRequirement` positions —
target, receiver, arguments — were the whole story and that arbitrary sub-expression types needed an upstream
change. They do not. `FileAnalysisRequirement::ExpressionTypes` exists, and an `AfterFileAnalysisHook`
receives a `FileAnalysis` carrying `getExpressionType(Node|Span): ?Type`,
`getMultipleExpressionTypes(array)` and `getAllExpressionTypes()`. Verified by reflection over the vendored
SDK, and by a probe that asked per array element of `[$this, 'handle']` and got `Handler` for element 0 —
which is exactly the question `ForbiddenArrayMethodCallRule` asks.

So the work is ours, not upstream's: emit an **after-file** hook for a rule whose question is not answerable
at a node position, and look the type up by node.

| rule | refusal | what it needs |
|:--|:--|:--|
| `ForbiddenArrayMethodCallRule` | no hook mapping for `Expr\Array_` | An after-file hook requesting `ExpressionTypes`, then `getExpressionType()` on the first array element, plus `Codebase` for `hasMethod`. Two CST facts to write it against: an element is wrapped in an `ArrayElement` category node with `ValueArrayElement` beneath it — a kind predicate against the direct children matches nothing, while a text predicate would pass, which is the failure mode this project already has scar tissue about — and the type is available at both levels. |
| `NoDynamicNameRule` | no hook mapping for `PhpParser\Node\Expr` | Also needs a hook with **several** `getTargets()` kinds and a dispatch on the node's actual kind, where the emitter writes exactly one. The CST is kinder than PHPStan here: dynamic-ness is structural (`$obj->$name()` selects through `ClassLikeMemberSelector → Variable`, `Foo::{$k}` through `ClassLikeConstantSelector → ClassLikeMemberExpressionSelector`), so finding one needs no type question. Both branches then ask for a sub-expression type, and the second reaches it through an injected `CallableTypeAnalyzer` — so this one is gated on collaborator inlining too, not on the SDK. |

### Still refused, and this one is the right answer

`NoMissingVariableDimFetchRule` emits for the Rust targets, which have scope information, and refuses for PHP
because `Support` has no `variable_is_undefined`. **Not** because the SDK exposes no flow facts — it does:
`Type->flags` is a public `TypeFlags` carrying `possiblyUndefined` and `possiblyUndefinedFromTry`. Measured,
the flag only tracks one case:

| variable | inferred type | `possiblyUndefined` |
|:--|:--|--:|
| never assigned | `mixed` | `false` |
| assigned in one branch of an `if` | `mixed` | `false` |
| assigned inside a `try` | `int` | **`true`** |

So the SDK answers "might this be undefined because a `try` did not complete", and the rule asks "might this
be undefined here". There is nothing to build against without approximating, and approximating is the one
thing this project refuses.

### Nothing left here is an SDK boundary

Both "correct forever" verdicts in this document turned out to be verdicts about *node* hooks mistaken for
verdicts about the SDK, and both were wrong in the same direction — blaming the engine for machinery we have
not written. What remains unbuilt is ours: an after-file hook for sub-expression types, an after-analysis hook
fusing a collector with its consumer, collaborator inlining, multi-kind targets.

The single exception is `NoMissingVariableDimFetchRule`, and even there the reason is not a missing capability
but a missing *fact*: no general "might this be undefined here". Mago reports `undefined-variable` natively
anyway, so the rule has somewhere better to live than a port.

Prefer "we have not built it" over "the SDK will not let us" unless a probe says otherwise. This document has
been wrong twice in the comfortable direction.

### One caution before adopting `ExpressionTypes` broadly

It embeds every expression type in the file, and the requirement is per hook — so a node hook that does not
ask keeps paying nothing. Measure the per-file payload on a real corpus before reaching for it in a rule that
does not need it.

## Do not raise the emit count by lowering the bar

Partial coverage plus named refusals is the honest, finished result. See `../guidelines/verification.md`.
