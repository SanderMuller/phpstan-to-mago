# The baseline is debt, not a standard

`phpstan-baseline.neon` holds the errors this code arrived with when it moved out of research. It came down
from 559 by installing the Mago SDK so the runtime type-checks, replacing 92 calls to php-parser's deprecated
`getLine()`, typing the vocabulary tables and the descriptor shape everything flows through, extracting
`ExampleReader`, and splitting the worst predicate method. It now holds 34 entries covering 59 errors.

Prefer emptying it over adding to it. Check the current figure rather than quoting this one — a number in a
guideline goes stale, and the file is one command away.

## What remains is class complexity, and the refactor is three-quarters done

`Translator` scores 1792 against a limit of 80, `Runtime\Support` 447, and `Transpiler` 169. Each grows with
every rule shape the vocabulary learns, so a rising number there is the cost of coverage rather than a
regression — what matters is that no *new* entry appears. Splitting methods inside a class does not move its
number, because the class total is roughly the sum of its methods.

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
