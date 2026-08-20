# How the transpiler works

A PHPStan rule receives `(Node, Scope)` and reaches into thousands of classes. Mago exposes none of that
object graph to PHP, so a rule cannot be handed to it at runtime. The runtime-shim alternative was closed
by measurement, not taste: `ClassReflection` is `final`, has 19 constructor dependencies, and 25 files
type-hint it. That is the argument *for* translating at build time.

What a rule's decisions usually reduce to is a chain of guards over the syntax tree plus a few questions
about the enclosing class. Those translate.

## Pieces

- **`Vocabulary`** — the tables (HOOKS, FIELDS, KIND_FIELDS, ITERABLES, NODE_PREDICATES, REFINEMENTS)
  mapping PHPStan constructs to Mago ones. An optional third element in an entry is the PHP navigation
  recipe.
- **`Stm`** — the statement IR, and **`Backend`** with `RustBackend` / `PhpBackend` behind it.
- **Descriptors** — `array{rust, kind, key?, php?, fields?, collector?}`. `operand()` renders for the
  current target and refuses when there is no `php` key.
- **`Hierarchy`** — resolves which class-like declares a `$this->` helper, across traits and parents.
- **`RulePaths`** / **`Options`** — turn a command line into rule files and a target.
- **Targets** — `php` (the product: a Mago SDK plugin, an ordinary composer library), plus `analyzer` and
  `linter`, which emit Rust and only run compiled into Mago's own crate.

## The two invariants

**1. The emitted output is the contract, not the source.** Both Rust targets and the PHP target have a
reviewed snapshot under `tests/Fixtures`. pint and rector have each rewritten `Transpiler.php` wholesale,
and the snapshots proved the output was untouched. If a snapshot changes, decide whether the new output is
right *before* updating it, and say why in the commit.

The same discipline covers the corpus as a whole. `tests/Fixtures/expected/census.md` records, for every
rule in every package this repository installs, whether it emits or which reason refuses it —
`TracksUpstreamDriftTest` regenerates it and compares. `composer.lock` is not committed, so that file is
where an upstream release announces itself: a rule added, a rule deleted, or a rule rewritten into or out of
a shape the vocabulary covers. It deliberately records no package versions and no line numbers, because an
alarm that fires on every routine bump is one nobody reads.

**2. Refuse rather than approximate.** A construct outside the vocabulary is refused by name and line, and
`PhpBackend::checked()` refuses any operand it was handed and could not render. Both are load-bearing.
Weakening the first produced files that did not parse. Weakening the second produced files that parsed
*while still containing Rust*, which is worse, because they load and misbehave.

A plausible-but-wrong rule is the failure mode to design against, because you would trust it. And some
refusals are simply the right answer: a node hook receives inferred types only at the positions it asks for
through `FileAnalysisRequirement`, so where the subject is not one of those positions, refusing is correct.

## The gate

`composer qa-check` runs rector, pint, PHPStan, the gitattributes validator and the suite. Beyond that,
the behaviour check that matters is emitting all three targets and comparing against the research tree:
**php 20/20, analyzer 23/23, linter 10/10**, with the analyzer and linter output byte-identical and the PHP
output differing only by the generator name and the runtime import.

Run it after any change to body translation. Both Rust targets share that code with PHP, which is the whole
reason they are kept.

**Upstream drift is watched separately**, by `.github/workflows/upstream-parity.yml`, nightly, in two legs:
the corpus as it resolves today, and the packages' `dev-main` branches as an early warning. Neither gates
anything; a failure upserts one tracking issue per leg, carrying the census diff and a table mapping the
failing test to the kind of drift it means. The dev-main leg is expected to be the redder of the two — a
broken upstream branch fails it, and that is information rather than a defect here. A new rule arriving as
`REFUSE` is not a regression either: it is upstream growing a shape this vocabulary has not covered yet,
which is a `rule-shapes.md` entry rather than a fix.

## Traps that have cost time

- **`NodeKind::Class` does not reference the enum case.** PHP special-cases `::class` and yields a string.
  Generated code failed loudly; hand-written helpers failed *silently*, leaving five of them quietly wrong.
  The SDK spells it `Class_`. And `NodeKind::Array_` does not exist, so the convention is not general.
- **Probe the CST, never assume it.** Operands are wrapped in category nodes (`Expression`, `Call`,
  `Access`), and `self` / `static` arrive as `Keyword`, not `Identifier`. An argument unwrapper that stopped
  two levels short worked for text predicates and silently never matched kind predicates, because every
  level carries the same text. A throwaway probe plugin settled it in minutes; reading the code did not,
  because the code read correctly at every line.
- **`mago analyze` exits non-zero when it finds issues.** The exit code says nothing about whether a plugin
  worked. Look for orchestrator errors on stderr instead.
- **A fatal or notice on worker stdout corrupts the frame protocol**, surfacing as "invalid extension frame
  magic", which points nowhere near the cause. Every worker this repository generates now opens with
  `ini_set('display_errors', 'stderr')`, which it needed: one deprecated function in a `--prefer-lowest`
  resolution turned 284 passing tests into 107 errors, and nothing in the failure named the cause. A
  consumer writing their own worker inherits the same hazard from their own vendor tree.
- **A count belongs to its target.** `--survey` honours whatever target is set, and a rule can render as
  Rust while failing to render as PHP. Two correct counts for two targets look like a bug in the tool
  unless the target is printed next to the number, which it now is.
- **Mago JSON `line` is 0-based**, and the location lives in `annotations[0].span`, not `location`.
