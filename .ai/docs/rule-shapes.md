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

Surveyed with the tool: `symplify/phpstan-rules` 20 of 96, `hihaho/phpstan-rules` 3 of 20,
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

## The next shape, named rather than guessed

`CombinedMethodCallRule` is now refused one line further along than it was, and the new refusal names something
genuinely different: it **accumulates** findings.

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
vocabulary entry. `CombinedStaticCallRule` is blocked by something else entirely, and traced rather than
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
