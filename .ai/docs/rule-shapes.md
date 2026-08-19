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

`ForeachCeptionRule` emits and agrees; symplify 23 to 24. `AvoidFeatureSetAttributeInRectorRule` and
`NoOnlyNullReturnInRefactorRule` both moved to the same structural boundary, which is the next shape worth naming:
**field navigation is relative to the hook's node.** `Vocabulary::FIELDS` and `argListPath()` are keyed on the kind
the hook fired for, so a rule that asks a *found* node for its arguments or its `->expr` refuses with
`no argument list on a Class node`. Making navigation relative to an arbitrary node is one change touching a
much-used path, which is why it is its own pass and not a footnote to this one.

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

### Refusals that are correct forever

Two of the 20 are `FlagArgumentManifestCollector` and `WriteNamedArgumentManifestRule`: a collector and its
`CollectedDataNode` consumer, which aggregate across files. Mago's node hooks see one file at a time. An
earlier prototype confirmed the boundary precisely: rules rewritten against Mago's `SymbolReferences` and
`codex` mirrors matched PHPStan exactly, and the only gap left was arbitrary per-file facts.

Three more are ordinary vocabulary gaps: `Expr_Isset` in a condition, `Stmt_Foreach` inside an inlined
helper, and an unknown local `$this`.

## Do not raise the emit count by lowering the bar

Partial coverage plus named refusals is the honest, finished result. See `../guidelines/verification.md`.
