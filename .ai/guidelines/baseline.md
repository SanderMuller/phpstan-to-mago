# The baseline is debt, not a standard

`phpstan-baseline.neon` holds the errors this code arrived with when it moved out of research. It came down
from 559 by installing the Mago SDK so the runtime type-checks, replacing 92 calls to php-parser's deprecated
`getLine()`, typing the vocabulary tables and the descriptor shape everything flows through, extracting
`ExampleReader`, and splitting the worst predicate method. It now holds 31 entries covering 56 errors.

Prefer emptying it over adding to it. Check the current figure rather than quoting this one — a number in a
guideline goes stale, and the file is one command away.

## What remains is mostly class complexity, and needs a real refactor

`Transpiler` scores 1697 against a limit of 80, and `Runtime\Support` 370. Both grow with every rule shape
the vocabulary learns, so a rising number there is the cost of coverage rather than a regression — what matters
is that no *new* entry appears. Splitting methods inside either does not move that number, because the class
total is roughly the sum of its methods. Fixing `Transpiler`
properly means separating the four jobs it does — orchestration, statement translation, expression
translation, emission — which all share mutable state (`$locals`, `$lines`, `$indent`, `$refinements`,
`$nodeKind`). That is a deliberate refactor behind a shared context object, not something to bolt onto a
cleanup.
