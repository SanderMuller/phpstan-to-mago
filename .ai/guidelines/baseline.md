# The baseline is debt, not a standard

`phpstan-baseline.neon` holds the errors this code arrived with when it moved out of research. It came down
from 559 by installing the Mago SDK so the runtime type-checks, replacing 92 calls to php-parser's deprecated
`getLine()`, typing the vocabulary tables and the descriptor shape everything flows through, extracting
`ExampleReader`, splitting the worst predicate method, and splitting the runtime into twelve classes. It now
holds 33 entries covering 58 errors.

Prefer emptying it over adding to it. Check the current figure rather than quoting this one — a number in a
guideline goes stale, and the file is one command away.

## What remains is two classes, and only one of them can be split further

`Translator` scores 1827 against a limit of 80 and `Transpiler` 169. Those are the only two. Each grows with
every rule shape the vocabulary learns, so a rising number there is the cost of coverage rather than a
regression — what matters is that no *new* entry appears. Splitting methods inside a class does not move its
number, because the class total is roughly the sum of its methods.

### The runtime is out of the baseline, and how it got there transfers

`Runtime\Support` was 448. It is now a facade of one-line delegations over eleven classes — `Tree` for the
navigation primitives, then `Calls`, `Declares`, `Members`, `Names`, `Inheritance`, `Attributes`,
`Constants`, `Hints`, `Text`, `Types` and `Reflect` — and every one is under the limit.

Three things made that work, and they apply to the next split as much as they did to this one:

- **A facade keeps a shipped API still.** Every emitted plugin writes `use ...\Runtime\Support;` and calls
  `Support::x()`. Delegations cost nothing — a class of them scores 0 against a limit of 1, measured before
  the design depended on it — so the split changed no emitted byte.
- **Group by the call graph, not by what the docblocks are about.** Each group is the transitive closure of
  a seed, so it cannot call out of itself. Where two groups genuinely share something it moved to `Tree`
  rather than being duplicated.
- **A static bag splits and a stateful class does not.** `Support` had no shared state, so a moved method
  took its complexity with it. `Transpiler` did, which is why splitting it conserved the sum.

The four jobs `Transpiler` used to do are now three classes. `TranslationContext` holds the mutable state they
share (`$locals`, `$lines`, `$indent`, `$refinements`, `$nodeKind` and sixty-five more), `Emitter` turns
finished state into a file, and `Translator` does the translating. `Transpiler` orchestrates. Splitting a
class over the limit into two leaves two over it: 1961 became 169 plus 1792, and the sum is conserved by
construction.

### The fourth boundary is a design change, not a move

Statement translation and expression translation cannot be separated by extraction. Inlining a helper
translates statements from inside expression resolution, and a loop body translates expressions from inside
statement translation, so the transitive closure seeded from either entry point is the same 203 methods.
Separating them means changing how helpers are inlined. Do not attempt it as a refactor.

### Verify a refactor byte-for-byte, across all three targets

The test suite runs the PHP target only, so the analyzer and linter branches have no check in it. Before
touching anything, emit every rule in the four corpus packages plus `tests/Fixtures/Rules` for all three
targets, keep the tree, and `diff -r` after every step. Zero diff is the pass condition. A one-token change
to `$reportSpan` alters five `.rs` files and nothing the suite sees.

A step that changes any emitted byte, snapshot or census line is wrong. Revert the step; never update the
snapshot to match it.
