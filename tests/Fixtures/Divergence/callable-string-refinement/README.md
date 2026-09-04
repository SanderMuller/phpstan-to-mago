# callable-string refinement

**Regression guard.** Closed by `f62f331`; recorded so it cannot reopen silently.

`is_callable()` narrows a `string` to a `callable-string`, which mago models as a `ScalarType` of kind
`String` carrying `callable: true` on its `StringType` refinement. `Types::isCallableAtomic()` reached that
refinement and read only `literalValue` from it, never `callable`, so a guarded dynamic call was reported by
the port and exempted by PHPStan.

It went unnoticed because `ScalarType::__toString()` renders only the kind — a `callable-string` and a plain
`string` both print `string` — so the probe that measured it could not see the difference, and the divergence
was written up as a mago narrowing bug. It was neither: mago narrows correctly.

`unguarded()` is the control. Without it a case that stopped exercising the rule would record nothing on both
sides and read as agreement.
