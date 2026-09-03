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

Every reachable emission path now has a snapshot behind it, and this is what each one costs to break —
measured by mutating one token in each and reading which tests fail:

| emission path                 | what catches a changed byte           |
|:--|:--|
| php node hook                 | 21 `TranspilesToPhpTest` snapshots    |
| php whole-project pass        | 1 `TranspilesToPhpTest` snapshot      |
| php aggregate template        | 1 `AggregatesTypeCoverageTest`        |
| analyzer node hook            | 3 `TranspilesToRustTest` snapshots    |
| linter rule                   | 3 `TranspilesToLintTest` snapshots    |
| analyzer whole-run hook       | nothing — no rule in the corpus reaches it |

**Emit all three targets anyway.** The snapshots read 22 of the 58 rules in `tests/Fixtures/Rules` on the php
target and 3 on each Rust one, while the corpus emits 138 php, 42 analyzer and 33 linter files: a change that
moves only a corpus rule's shape moves none of them. So before touching anything, emit every rule in the four
corpus packages plus `tests/Fixtures/Rules` for all three targets, keep the tree, and `diff -r` after every
step. Zero diff is the pass condition, apart from the `--out` path the `mago.toml.snippet` embeds.

Build the baseline by copying the changed sources aside and restoring them from HEAD, not with `git
worktree`: a worktree's `vendor` symlink autoloads this repository's `src`, so both runs read the same code
and the diff is empty for the wrong reason.

A step that changes any emitted byte, snapshot or census line is wrong. Revert the step; never update the
snapshot to match it.
