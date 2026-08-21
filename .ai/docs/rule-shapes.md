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

**Five are done** — `ForbiddenMultipleClassLikeInOneFileRule`, `StringFileAbsolutePathExistsRule`,
`PreventParentMethodVisibilityOverrideRule`, `PublicStaticDataProviderRule` and `ForbiddenArrayMethodCallRule`
— and two more came free with the last one's mechanism: `NoPropertyNodeAssignRule` and `NoWithOnStubRule`,
both of which had been refused with the very claim the probe disproved. `symplify` goes from 24 emitted to 31.

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
| `RequireUniqueEnumConstantRule` | **now**: access path outside the vocabulary `->getConstants()` | Collaborator inlining is **built**, and it moved this refusal three levels deeper, which is how far it got: `$this->enumAnalyzer->detect()` now inlines, and so does the docblock question behind it (`parseNode()` maps to the declaration's docblock and `getTagsByName('@enum')` to a tag match — the parser itself cannot be inlined, since its own dependencies are PHPStan's `PhpDocParser` and `Lexer`, so the *question* is mapped instead of the collaborator). What blocks it now is the real blocker: `$scope->getType($const->value) instanceof ConstantStringType` is the inferred type of a class-constant initialiser, so this rule wants the **after-file hook** too. A syntactic read of the initialiser would be narrower — `'a' . 'b'` is a constant string and not a literal — which is the trade this project refuses. |
| ~~`PublicStaticDataProviderRule`~~ **done** | was: access path outside the vocabulary: `DataProviderMethodResolver::match()` | Five features. A static helper inlined as a *producer* (predicate position already worked). `preg_match()` with a named group, bound without emitting anything — each read runs the match again, which is free of consequence because a match is pure. A method looked up by a name computed at analysis time, under its own descriptor kind, because such a lookup can answer null and `instanceof ClassMethod` on it is the rule asking exactly that. Two messages and two identifiers from one rule, allowed to change once the previous has been reported. And a conditional report: `if (c) { $message = ..; $errors[] = ..; }` emits an `if` with a `report` inside rather than a guard. |

### Sub-expression types, and the correction of the correction

This section has now been wrong twice about the same thing, in the same comfortable direction. First it said a
node hook's `FileAnalysisRequirement` positions — target, receiver, arguments — were the whole story and that
arbitrary sub-expression types needed an upstream change. Then it said the answer was an **after-file hook**,
which sounded architectural and expensive.

Neither is right. `NodeAnalysisContext` already carries a `FileAnalysis`, so a **plain node hook** that
requests `FileAnalysisRequirement::ExpressionTypes` can call `$context->analysis->getExpressionType($node)` for
any sub-expression. Probed, with a throwaway plugin: element 0 of `[$this, 'handle']` answers `Fixture\Handler`
and element 1 answers `string`. No new hook shape, no `Support` refactor — one requirement and one helper.

Two things the probe settled that reading would have got wrong:

- **An array element is a grandchild.** `Array → ArrayElement → ValueArrayElement`, and both carry the same
  text and the same type. A kind predicate against the direct children matches nothing while a text predicate
  passes — the `Expression`/`Call`/`Access` trap again.
- **A literal's type renders as `string`.** `'one'` prints as `string`, so reading the rendering answers "not a
  constant" for every string there is. The literal is in the structure:
  `ScalarType{kind: String, refinement: StringType{literalValue: 'one'}}`. That is what makes PHPStan's
  `ConstantStringType` and its `->getValue()` translatable at all.

`ForbiddenArrayMethodCallRule` **emits** on that mechanism, and two rules refused with exactly the claim the
probe disproved came free with it: `NoPropertyNodeAssignRule` and `NoWithOnStubRule`.

`NoDynamicNameRule` still needs a hook with **several** `getTargets()` kinds and a dispatch on the node's
actual kind, where the emitter writes exactly one. The CST is kinder than PHPStan there: dynamic-ness is
structural (`$obj->$name()` selects through `ClassLikeMemberSelector → Variable`, `Foo::{$k}` through
`ClassLikeConstantSelector → ClassLikeMemberExpressionSelector`), so finding one needs no type question at all.
Its second branch reaches a type through an injected `CallableTypeAnalyzer`, so it is gated on collaborator
inlining — which is built — plus that multi-kind hook.

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

Three verdicts in this document turned out to be verdicts about *node* hooks mistaken for verdicts about the
SDK, each wrong in the same direction — blaming the engine for machinery we had not written, or for machinery
that turned out to need writing at all. What remains unbuilt is ours: an after-analysis hook fusing a collector
with its consumer, and multi-kind targets. Collaborator inlining and sub-expression types are built.

The single exception is `NoMissingVariableDimFetchRule`, and even there the reason is not a missing capability
but a missing *fact*: no general "might this be undefined here". Mago reports `undefined-variable` natively
anyway, so the rule has somewhere better to live than a port.

Prefer "we have not built it" over "the SDK will not let us" unless a probe says otherwise — and prefer the
*cheapest* mechanism that a probe supports over the one that sounds architectural. This document has now been
wrong three times in the comfortable direction, and the third time the correction itself was too pessimistic:
what looked like an after-file hook plus a runtime refactor was one requirement and one helper on the hook we
already emit.

### What a type-asking rule costs on real code

`ForbiddenArrayMethodCallRule` agrees with PHPStan on 9 sites of a vendor corpus and misses 5, and the misses
are not a translation defect: mago keeps a null in the type where PHPStan narrows it away, and the rule's
`instanceof TypeWithClassName` correctly answers no to a nullable type on both sides. `docs/dogfooding.md` has
the probe. Expect the same shape from every rule that asks about a type — the port is exactly as wide as the
question it was given, and the engine gives a different answer.

### One caution about `ExpressionTypes`

It embeds every expression type in the file, and the requirement is per hook — so a node hook that does not ask
keeps paying nothing, which is why the emitter adds it only when a rule reaches for a position the ready-made
types do not cover. `ReceiverType` is still preferred where it applies. The per-file payload on a real corpus
is unmeasured; the three rules that ask for it are cheap ones, but a broad adoption should measure first.

## Moving `hihaho/phpstan-rules`, and the shape of what is left

That package went 3 → 5 emitted, and the count is the least useful part of the result.

**The gate covers it now**, which it could not before the package became a dev dependency, and that alone found
two silences in rules that had been counted emitted for weeks:

- `declaringClassOfMethod()` matched metadata method names case-sensitively, and metadata lowercases them. Both
  positional-flag rules went quiet for any method whose name is not one lowercase word. Every example method in
  this repository was `send`, `toggle` or `handle`, so `setEnabled` is the first name that could show it.
- The same helper then missed a method a **trait** provides: `Illuminate\Support\Collection` lists 115 methods
  and `dump` is not among them, because `EnumeratesValues` provides it. It reads
  `getDeclaringMethod()->identifier->class` now, which covers traits and is correctly cased — and maps a trait
  back to the class *using* it, because PHPStan flattens traits and the rule was written against PHPStan.

Two rules take a configured constructor value, so PHPStan refuses to construct them bare — which is why they
sat outside the gate. The transpiler hands back the values it read from the package's neon, and the gate
registers the original with the same ones: a rule whose two sides are configured differently is not a
comparison.

### The `Combined*` rules, and why one of them is refused rather than emitted

hihaho merges its rules into three `Combined*` classes to share node visits, and those are the ones its neon
registers. `CombinedFuncCallRule` **transpiles** — it took a dozen features, including a memoised pure helper
folding to the expression it memoises, `isSuperTypeOf` between two constructed `ObjectType`s becoming an
ancestry question, a class constant carried into the plugin so a copied derivation can name it, and a
derivation reading a property the same constructor derived earlier.

It was **refused** at first, by a check added for the purpose: flattening several independent checks makes the
first check's guards the rule's guards, so `dump()` not being a debug call exited before the `invade` check
ran. The emitted plugin reported only its first sub-rule and looked complete doing it.

### Per-check blocks

The rule is now emitted as **one private method per check**. The point of the method is its `return`: a guard
inside it declines *that* check, where the same guard in the rule body declines every check after it too. The
shared prologue stays in `analyze()` and passes whatever locals a check names as parameters, typed `mixed` —
the transpiler tracks a local's shape well enough to render it, not well enough to name a PHP type, and a
guessed type is a `TypeError` at analysis time rather than a refusal at transpile time.

Check mode is decided **before** translation, and only for a rule that really asks two checks, so every rule
that asks one emits exactly what it emitted before. That is what kept the reviewed snapshots byte-identical
through the change.

Three defects only this shape could expose, each of which had made a check silent:

- `$literals` — the transpile-time map of literal arguments — outlived the inline that bound it.
  `ChecksNamespace` binds `$namespace` to `'App'` for the singular check and iterates a configured list under
  the same name for the plural one, so the second check compared every item against the first check's literal.
  It is scoped like `$locals` now.
- `Support::nameEquals()` compared a name as written, and Mago keeps the leading `\` where php-parser drops
  it. The port was silent on exactly the fully-qualified `\Livewire\invade` the rule exists to catch.
- `hasFunction()` was handed the name *node* where the helper takes text. That is a `TypeError` in the worker,
  which surfaces as an orchestrator protocol error naming neither the rule nor the argument.

And one in the gate itself, which is the more useful lesson: it filtered both tools on **one** identifier, the
last one the rule took. A merged rule reports under one identifier per check, so the gate measured one check
and read the other two's silence as agreement — it passed while two of three checks did nothing. It compares
every identifier the rule takes now. A harness that looks at one of several outputs is a harness that agrees
on zero without saying so.

`CombinedMethodCallRule` now refuses on that further-out thing rather than on anything before it: one of its
sub-rules resolves where `rules()` is *declared*, asks for that class's file, and parses it. Everything up to
that point translates — `hasMethod` and the declaring-class read on the enclosing declaration, a method name a
loop bound from a constant list, the caches around both lookups.

The refusal names the file read rather than the accessor, because the accessor is not the obstacle — and nor is
the SDK. That read is **reachable, and measured** (`internal/probe-declaring-file-body.php`):
`Codebase::getDeclaringMethod()` resolves the inheritance and names the declaring file,
`AfterAnalysisContext->analysis->files` finds that file's analysis, and `getSourceFile()` hands over its tree.
The probe reads all three keys out of a parent class in another file. No parser in the plugin.

What cannot do it is a **node** hook. `FileAnalysis::getSourceFile()` and `getNodeSourceFile()` both take no
argument and answer about the one file the hook was given. So the obstacle is the hook kind.

Which makes this a trade rather than a blocker, and the trade is the reason it stays refused: a merged rule
bundles sub-rules that are node-shaped and translate today, and re-homing the whole rule to a whole-project
hook to serve one of them gives up the per-node dispatch and the inferred types the rest depend on — each
would have to be rewritten as a whole-file walk. The transpiler would also have to pick the hook from what a
rule needs rather than from its `getNodeType()`, which is the same architectural change the collector-shaped
rules want, and theirs to land with. Untested: whether whole-project CST access is affordable on a real
corpus. A merged rule is only portable if every sub-rule is.
`CombinedStaticCallRule` stops earlier, on a cache declared part-way through a helper — see below.

## A cache is invisible to the answer, but only where it wraps the question

A helper that memoises a pure question emits the question and drops the cache. Two spellings are in the
corpus, and both are recognised as a *whole body* rather than statement by statement, because `static $cache`
on its own says nothing about whether dropping it is sound:

```php
static $cache = []; if (! array_key_exists($k, $cache)) { $cache[$k] = <expr>; } return $cache[$k];
static $cache = []; $k = ..; if (array_key_exists($k, $cache)) { return $cache[$k]; }
                    return $cache[$k] = <expr>;
```

The second spelling binds the key between the declaration and the cache logic. That binding goes away with
the cache, so it is accepted only when the memoised expression does not read it — otherwise the expression
would lose a value the recogniser silently dropped.

A cache declared **part-way** through a longer body is refused, and the refusal says so rather than naming the
token. `DetectsFacadeAlias` fills its cache with runtime reflection, so whether dropping it changes the answer
depends on what fills it — which is exactly what the whole-body form settles and the statement-position form
does not.

## An inverted loop is the same question with a different answer

`foreach (..) { if (..) { return true; } } return false;` is "any of them matches". So is
`foreach (..) { if (..) { return false; } } return true;` — what differs is the answer, not the question. The
loop's own polarity is read from the return it carries, and every return inside one loop has to agree: two
polarities in one loop is a different shape, and folding it into one "any of them" would answer the opposite
question for half the items.

`NoEloquentWithPropertyRule::isEagerLoadingDefault()` is the inverted form — an explicit `$with = []` restates
Eloquent's own default and is skipped. Assuming the usual polarity made the port report it, which its good
example now catches.

## The class-declaration hook is wider than one node kind

PHPStan's `InClassNode` fires for an **enum, a class and an interface** — controlled with a rule that reports
unconditionally, so this is measured rather than read off the source. Mago's `Class` hook fires for the class
alone. A rule that asks about every class-like therefore needs all three targets, and one that narrows to
`Class_` itself must keep the single target: that narrowing is folded away as always holding *because* the hook
is class-only, so widening the targets without dropping the fold reports on exactly what the rule excludes.

So the PHP target **registers all three, always, and asks the rule's own class test at runtime**. Nothing is
decided, which is what makes it right.

Two attempts came before that, and both were wrong in the same direction. First a syntactic pre-pass over the
rule's source; then the flag the `instanceof Class_` fold sets during translation. The trap is that neither the
presence of the predicate nor its translation proves the *rule* is class-only:

```php
if ($reflection->isClass() && $somethingElse) { return []; }   // still reports on enums and interfaces
if (! $reflection->isClass()) { return [$error]; }             // reports *only* on them
```

Folding `isClass()` to "always true" is sound only where the plugin visits classes alone — and dropping the
enum and interface targets on the strength of that fold is what made the port go silent on exactly the
declarations such a rule is about. `CompoundClassGuardRule` is the fixture for it: an enum both tools report,
which the port misses the moment the fold comes back.

The Rust target keeps the fold. Its class hook fires for classes alone, so the predicate really is always true
there, and a rule that does not narrow is refused outright rather than emitted.

`TraitRequiresInterfaceRule` is what makes this provable: the pairs it exists for in a real project are enum
concerns, and its example pair reports on an enum and a class. Forced back to one target, the port misses the
enum PHPStan finds.

Not every rule on that hook needs the breadth. `PublicStaticDataProviderRule` restricts itself *semantically* —
`isTestClass()` asks `$classReflection->is(TestCase::class)`, and neither an enum nor an interface can extend a
class — so its class-only port was never narrower. A syntactic gate would have refused it wrongly, which is why
there is no such gate.

## Dropping a rewriting means reproducing what it collapsed

`TraitRequiresInterfaceRule`'s constructor keys its map by each configured name's *declared* spelling. Dropping
that pass and carrying the configured map is right for what the rule matches — Mago lowercases the names it
holds, so folding case at the use site asks the same question — but it is not right for what the map *holds*:
two configured keys naming one trait in different cases became a single pair, and carrying the map as written
kept both, so the case-insensitive match found both and reported the same finding twice. The port was wider.

So the alias goes through `Support::foldedKeys()`, collapsing case-colliding keys at the point the
canonicalisation used to happen. `CollapsesConfiguredKeysTest` covers it.

One divergence remains and is not fixable here: where a configured name is written in a case other than its
declaration's, PHPStan's message prints the declared spelling and the port prints the configured one. Mago's
class store answers nothing for a trait name, so there is no declared spelling to recover at the use site. The
findings agree on file and line; only the message text differs, and only for a mis-cased configuration. That is
why the fires-gate carries a correctly-cased pair and the collapse is covered by a unit test instead.

## A configured value the package leaves empty

A package may ship a parameter empty and expect each project to fill it. The emitted plugin carries the package
default and exposes it as a constructor parameter, so a consumer's worker passes its own — verified with a
control: the same plugin over the same file reports nothing on the default and reports on the supplied value.

That leaves the gate, which registers both sides with the package's values: for an empty parameter both tools
would be silent, and agreement on nothing is the one result the gate must never accept. So the values are
supplied by the gate itself, to *both* sides, and the pair proves the rule fires when configured. See
`FiresGate::CONFIGURED`.

## One rule, several node kinds

A rule can return an *abstract* php-parser class from `getNodeType()` and branch on the concrete ones.
`NoDynamicNameRule` says so itself — `return Expr::class` carries the comment "trick to allow multiple node
types" — and its body then handles `ClassConstFetch`/`StaticPropertyFetch` in one branch and
`MethodCall`/`StaticCall`/`FuncCall`/`PropertyFetch` in another.

It emits. `Vocabulary::HOOK_KINDS` maps such a class to the kinds it covers, the plugin registers one target
each, and every `instanceof` becomes a node-kind test. No rebinding of `$this->nodeKind` was needed after all —
the fear was that `$node->name` means a different child per kind, and it does, but a *family field* answers for
all of them: `Support::namePart()` reads the selector under five kinds and the called expression under
`FunctionCall`.

Each branch becomes its own method, the same per-check machinery a merged rule uses. A branch here is
`if (<this is my case>) { <guards> return [$error]; }` and its guards decline that case rather than the rule —
which is the whole point, since a rule over six kinds has one branch per family.

Two things had to be probed rather than reasoned:

- **A written name is structural, not textual.** A static property's written name *is* `$prop`, so a leading
  `$` proves nothing. Probed: written names hold a `LocalIdentifier`, a written static property a
  `DirectVariable`, a written function name an `Identifier`; computed ones hold `NestedVariable`, `Variable` or
  `ClassLikeMemberExpressionSelector`.
- **The two spellings need different helpers.** `$node->class instanceof Expr` asks about a part that arrives
  unwrapped, so a written one is a name node. `$node->name instanceof Expr` asks about a *selector*, which is
  never a name node — asking `isName()` of one answers false for every call there is, and the guard would then
  report on all of them. Caught by reading the emitted output; the example pair catches it too, which is what
  `EveryExpressionRule`'s snapshot and pair are for.

Every expression kind is registered, not only the ones a rule's branches name: PHPStan really does visit them
all, and a branch declining a kind is the rule's own business.

The class-declaration hook wanted a version of this and got a different answer — register every class-like kind
and ask the rule's own class test at runtime. The difference is that those kinds answer `->name` identically,
which is what `FIELD_GROUPS` records.

Also worth knowing: `--survey` assumes a hook exists in order to see what a body would need, so a survey
refusal for such a rule names something from the body while a real run refuses on the hook. Read the real run
before quoting a reason.

## Do not raise the emit count by lowering the bar

Partial coverage plus named refusals is the honest, finished result. See `../guidelines/verification.md`.
