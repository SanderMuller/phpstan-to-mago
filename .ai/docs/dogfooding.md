# Dogfooding on a real project

The procedure is the `differential-port-verification` skill. This is what the runs actually showed.

## Pick the consumer by what it depends on

Check `composer.json` before choosing a project. Of two candidate applications that both depend on
`hihaho/phpstan-rules`, only one also depended on `symplify/phpstan-rules` — the corpus the transpiler was
built against — which made it the only place a run could start without changing the transpiler first.

Nothing in the consuming repository needs to change. The generated plugins, `mago.toml` and the PHPStan
config all live outside it, and the mago binary plus the SDK come from this package's own `vendor/`.

## Results

| corpus | files | rules | findings | agreements |
|:--|--:|--:|--:|--:|
| vendored PHPStan extension packages | 1090 | 20 | 64 | 64 |
| an application's dependency tree | 7701 | 3 | 25 | 25 |
| that application's own source | 1899 | 3 | 0 | 0 (uninformative) |

Deterministic across 1, 2, 4 and 8 worker processes.

The third row is the lesson. An application that *enables* a rule is clean against it, so its own source
produces nothing from either tool, and agreement on zero is not evidence. Use a dependency tree, which
nobody wrote to satisfy these rules.

## Performance, stated honestly

Best of n on one machine, 7701 files, the same 25 findings. CPU is user+sys.

| | n | wall | CPU |
|:--|--:|--:|--:|
| mago, engine only | 3 | 2.41s | 6.30s |
| mago + 3 transpiled rules | 3 | 2.59s | 7.24s |
| PHPStan, cold | 3 | 45.21s | 305.29s |
| PHPStan, warm result cache | 2 | 5.43s | 5.17s |

- Against **cold** PHPStan: 17x faster on wall clock, 42x cheaper on CPU.
- Against **warm** PHPStan: 2.1x faster on wall clock but **1.4x more CPU**, because `mago analyze` has no
  result cache and redoes the whole job every run.
- The rules themselves cost +0.18s wall and +0.94s CPU over the engine alone.

Cheaper in CI, faster in an editor loop, not cheaper on CPU once PHPStan's cache is warm. Any single number
that does not say which baseline it means is overstating the case, and every earlier figure quoted for this
work (62x, 128x, 7.9x) was cold-only.

## Ruling out engine blindness

Mago skips the body of a class whose parent or interface it cannot resolve, and it reported 6395
`non-existent-class-like` findings on that corpus because it has no autoloader. A zero could therefore have
meant "never looked". Two files settled it — one plain class, one `extends` an unresolvable parent, each
with the same violation. Both were reported, so body-skipping does not reach syntax-level node hooks.

Do this check before trusting any zero.

## Configuration

Checked against the vendored SDK rather than assumed:

- **The SDK has no per-plugin settings.** `PluginDefinition` carries identifier, name, description, aliases
  and `defaultEnabled`. Analyzer configuration can enable or disable a plugin; it cannot pass it a value.
- **The extension host can.** `ExtensionHostConfiguration` exposes `command` (literal argv), `environment`,
  `working_directory` and `inherit_environment`. The worker process is ours, so a constructor-configured
  rule can take its values from the consumer's `mago.toml`. That keeps the generated file
  project-independent, unlike baking literals in at transpile time.

## The 2026-08-19 run, and why its numbers replace the ones above

The rows above were measured before `emitted` was gated on *running*. Five of the twenty rules in that run
could not fire at all — a node hook has no ancestors, so every hierarchy question asked from an expression
target answered "no" — so the 64 findings came from the fifteen that could. Nothing above is retracted; it is
just narrower than it reads.

### What ran

22 emitted rules from three packages in one worker, over 585 files of dependency-tree source (`symplify`,
`tomasvotruba`, `nikic`, `nette`, with test fixtures removed because PHPStan crashes on one of them). Three
runs, byte-identical findings each time.

| | mago | PHPStan | agreements |
|:--|--:|--:|--:|
| the five rules that fired | 214 | 19 | 17 |

### The disagreement is real and not yet separated

Two causes are mixed together, and the run does not distinguish them:

- **Mago has no autoloader.** The two tools do not see the same resolved classes on a vendor tree, so some of
  the gap is a difference in what each could look at rather than in what the rules decide.
- **At least one port is genuinely wider.** The `type-coverage` parameter aggregate is measured: PHPStan counts
  4057 parameters with 1994 typed (49.1 %) where the port counts 3079 with 2927 (95.0 %). Two known causes —
  the port counts only class methods where the collector targets every `FunctionLike`, and
  `ParameterMetadata->declaredType` is not php-parser's native `$param->type`.

The aggregate is therefore implemented, refused by default with those numbers as its reason, and available
behind `--unverified-aggregates`.

### What the positional-flag family added, and one thing no example can show

`PositionalFlagArgumentConstructorRule` is the first rule to reach Mago's codebase metadata for a *parameter*.
Three facts about that metadata were wrong when assumed and right only once measured: `ClassLikeMetadata->name`
is lowercased (`originalName` keeps the case), `ParameterMetadata->name` keeps the `$` sigil, and
`classLikeExists('')` aborts the whole analysis rather than answering false. The first of these made the rule
report nothing while every other guard passed — the exact failure mode this gate exists for.

One guard of the original cannot be exercised by any example, and that is a fact about PHP rather than about the
port. `lastBareFlagIndex()` sweeps every argument for a named or spread one *after* it has checked the last
argument for both. PHP rejects a positional argument after a named or unpacked one, so whenever an earlier
argument is named or spread the last one is too, and the earlier check has already bailed. Removing the sweep
leaves the pair green. Say so in the example file; do not let a reader infer the sweep is covered.

**Separating the two needs the control this document already prescribes** — two files with the same violation,
one resolvable and one not — applied per rule rather than to the corpus as a whole. That is the next
measurement.

### Per-rule agreement is a different, stronger claim, and it is gated

Every emitted rule has an example pair under `tests/Fixtures/examples`, and the gate runs each one through the
real mago binary in a worker registering **only that rule**, against PHPStan running the original over the same
files, comparing line *and* message text. A rule that emits and reports nothing fails. That is what caught the
five dead rules, and it is what `emitted` now means.

### Performance, as a data point rather than a claim

Same 585 files. mago with 22 rules: 1.37s wall, 1.42s CPU, best of three. PHPStan cold with 5 rules: 4.16s
wall, 17.50s CPU. Not like-for-like — different rule counts, and Mago resolves nothing it was not given — so it
is not the comparison the README's performance table makes.
