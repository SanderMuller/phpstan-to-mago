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

### What the blocker actually is

`inlineMethod()` produces one *expression*, through `translateMethodAsPredicate()`. That fits a helper that
answers yes or no inside a condition. It does not fit a helper whose return value **is the finding**, where
`return null` means "no finding" and `return RuleErrorBuilder::message(...)->build()` means "report this".

Supporting it means inlining a helper in **statement position**: its guards become early returns, and its
error-returning path becomes the report the rule would have emitted. The reporting machinery already exists
— emitted rules report — so the missing piece is inlining into statements rather than into an expression.

That is the single change that moves this package off zero. It is a real build, not a vocabulary entry.

### Refusals that are correct forever

Two of the 20 are `FlagArgumentManifestCollector` and `WriteNamedArgumentManifestRule`: a collector and its
`CollectedDataNode` consumer, which aggregate across files. Mago's node hooks see one file at a time. An
earlier prototype confirmed the boundary precisely: rules rewritten against Mago's `SymbolReferences` and
`codex` mirrors matched PHPStan exactly, and the only gap left was arbitrary per-file facts.

Three more are ordinary vocabulary gaps: `Expr_Isset` in a condition, `Stmt_Foreach` inside an inlined
helper, and an unknown local `$this`.

## Do not raise the emit count by lowering the bar

Partial coverage plus named refusals is the honest, finished result. See `../guidelines/verification.md`.
