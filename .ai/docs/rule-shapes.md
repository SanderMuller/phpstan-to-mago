# Which rule shapes translate, and the one that blocks a real package

## Covered today

Guard chains, `foreach` with an inline report, `sprintf` messages, inlined private helpers used as
*predicates*, string and integer comparisons, `instanceof` narrowing into a binding, class-hierarchy
questions about the enclosing class, and the receiver's inferred type.

Measured on 23 rules from `symplify/phpstan-rules` and a few of our own: **20 emit, 3 refused.**

## The shape that blocks `hihaho/phpstan-rules`

That package is **0 of 20**, and 17 of the refusals are the same message, `assignment value outside the
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
| cross-file collector aggregation | nothing; correctly refused forever |

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
