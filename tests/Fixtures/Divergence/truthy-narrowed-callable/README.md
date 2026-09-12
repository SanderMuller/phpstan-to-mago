# Truthy-narrowed callable

**Records agreement, and is a failed minimisation of something else. Both facts matter.**

## What it pins

A docblock-only `callable|null`, narrowed by truthiness and called with a spread:

    return $callback ? $callback(...func_get_args()) : true;

Both engines decline. That is a real recorded fact and it goes red if either changes its mind.

## What it does NOT stand for

This was written to minimise `Illuminate/Support/Testing/Fakes/QueueFake.php` — recorded as `:214` in one
corpus run and `:167` in another, with the cause *"mago infers more than PHPStan"*, meaning PHPStan reported
and the port was silent.

**The minimisation does not reproduce that.** Reduced to the construct above, the two engines agree. So
whatever produces the original divergence is not the truthiness narrowing, the docblock-only type, or the
spread call — it is something the reduction drops: the enclosing closure passed to `assertPushed()`, the
surrounding class, or the Laravel version the run used, which was never captured.

It is kept rather than deleted because a failed minimisation is worth recording — the next person to reduce
`QueueFake` should start after this shape, not at it. The original stays in `VERIFICATION.md` as an
unreproduced finding, and no case claims to cover it.

## Why it shares a rule on purpose

It names `NoDynamicNameRule`, as `callable-string-refinement` does. That pair is what proves the runner
transpiles and registers a rule once however many cases name it — before that fix, two cases naming one rule
wrote two plugin files both declaring `\Transpiled\NoDynamicNameRule` and the worker fatalled on the second
`require`.

## The control took two attempts

`plain()` was first written taking a `callable`, which the rule exempts — so the case recorded silence on both
sides and read as agreement. `test_no_case_records_silence_on_both_sides` caught it by name. The control is a
plain `string` now, which both engines report.
