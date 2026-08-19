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

### The receiver-typed rules, and the mutation that proved the difference

`hihaho/phpstan-rules` goes 2 of 20 to 3 with `PositionalFlagArgumentNullsafeMethodCallRule`, the first rule to
reach a class through a receiver's *inferred type* rather than a written name. Verified the same way as the
constructor rule: run against PHPStan over a pair holding a bare flag, a named flag, a spread, a non-bool, a
call past the end of the parameter list and a vendor-declared method — one finding each side, byte-identical.

The finding worth carrying forward is what a *passing* gate would not have shown. `TypeCombinator::removeNull()`
looked like a formality; a `?Widget` receiver turns out to carry a null atomic beside the object one, so the
"exactly one class" question answers no unless the null is dropped first. Mutation-checked by swapping the
null-dropping helper for the strict one: the nullsafe rule then reports **nothing at all**, and the method-call
shape loses every nullable receiver. Neither failure shows up in the emitted file, which parses, loads and calls
only helpers that exist.

That is the same class of defect as the five dead rules — and the reason a bad example has to contain the case
that discriminates, not merely a case the rule fires on.

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

## The 2026-08-20 run: a corpus differential, and the four ways the *harness* was wrong first

The runs above compared counts a rule at a time. This one compares **sites** — `(identifier, file, line)` — for
every emitted rule at once, against a consumer's own PHPStan with its baseline taken out, and it is persisted
as a harness rather than reconstructed each time:

```bash
php tests/Support/run-corpus-differential.php <consumer-root> [--paths=a,b] [--threads=N] [--sandbox=DIR]
```

It transpiles the packages **as that consumer has them installed**, writes one worker holding every plugin,
reads the consumer's own analysed paths and exclusions out of its `phpstan.neon`, and prints agree /
only-original / only-port per identifier. Nothing is copied: both tools read the consumer in place, so there
is no second corpus to drift, and no consumer source enters this repository.

### Results

Four packages as installed: 27 emitted, 102 refused, target `php` — the same 24/3/0/0 split the README
publishes, confirmed here against the installed versions rather than a checkout.

| corpus | files | identifiers that fired | agree | only-original | only-port |
|:--|--:|--:|--:|--:|--:|
| this repo's example pairs, as a control | 76 | 25 of 26 | 34 | 0 | 0 |
| a two-file probe for the 26th (`tests/Fixtures/probes`) | 2 | 1 | 2 | 0 | 0 |
| the consumer's first-party source | 2716 | 2 | 50 | 0 | 0 |
| four of its vendored dependency trees | 3103 | 10 | 328 | 0 | 0 |

Zero unexplained disagreements, in either direction, on 414 sites — and messages compared as text, not just
lines. Finding sets are identical at 1, 2, 4 and 8 threads on the first-party corpus, and at 1 and 8 on the
vendor corpus, which is the one where a plugin aggregating per-file state would have room to diverge.

Two things the run establishes about its own configuration, worth recording because both were assumptions
until it ran. `paths!:` **replaced** rather than merged: had it merged, PHPStan would also have analysed the
first-party tree during the vendor run and its exception findings would have shown as only-original — they
did not. And `ignoreErrors!: []` is correct but **unexercised on this consumer**: its baseline holds none of
the 26 identifiers, so nothing was actually suppressed there. The clause stays in the harness because the next
consumer will not be so convenient, but no claim here rests on it.

**The control run is what makes the zeros readable.** 24 of the 26 identifiers report nothing on the
first-party corpus, and on its own that is indistinguishable from 24 dead rules. It is a Laravel application,
and most of `symplify/phpstan-rules` asks Symfony, Doctrine and PHPUnit questions — so the control fires every
identifier over this repo's own example pairs first, in the same 27-plugin worker, and 25 of 26 agree exactly
there. The 26th is the `positionalFlagArgument` pair, silent on both sides because its configured first-party
namespaces do not match the examples' namespace; a two-file probe in a matching namespace fires both plugins
and agrees on both sites, kept under `tests/Fixtures/probes` so the next run does not have to invent it. The 27-plugin worker was worth proving separately: a node hook's ancestors had
already turned out to depend on what else shares the worker.

### Every disagreement the first run showed was the harness, not the port

In order, largest first. Each one reads as a port defect, and three of the four read as the port being
*narrower* — the direction that is hardest to notice and worst to ship.

1. **PHPStan analyses a trait once per using class** and names the file `Concerns/Shared.php (in context of
   class App\First)`. One trait used by dozens of classes arrived as **477 findings on 6 lines** where an engine
   that visits each file once reports 6. Stripped, and identical sites deduped, in `PhpstanReport`.
2. **Findings were keyed by base name**, which the per-rule gate can afford — its two sandboxes put the same
   example at different absolute paths — and a corpus cannot: `Handler.php` exists in several directories, so
   collapsing them makes one rule's finding look like another's. Both are now tested.
3. **Mago had no resolution context.** PHPStan has the consumer's autoloader; mago analysing only first-party
   paths cannot walk a class's ancestry into the framework. `13` of `31` exception findings went missing — every
   one a class extending a framework exception rather than `Exception`. `[source] includes` is the fix: scanned
   for symbols, never analysed or reported. It is the configuration that makes the comparison fair, and
   omitting it would have published "the port finds 18 of 31".
4. **The consumer's `excludePaths` were not applied to mago**, so the port reported two findings in a directory
   the original never opened. Read from its configuration now, and applied to the counted corpus too.

The lesson is the one the procedure already states, sharpened: a differential's first disagreements are
usually about *what each tool was asked to look at*. Attribute every one of them before touching a rule.

### What is still not covered

- The **type-coverage aggregate** stays refused by default and is not in these numbers.
- 24 of 26 identifiers have fired only on the control corpus. Their zeros on real code are "decided no" —
  proven by the control, not by silence — but no consumer here exercises the Symfony, Doctrine or PHPUnit
  families at scale, so agreement there rests on 34 example sites plus 328 vendor sites, not on production code.
- Performance is deliberately not quoted for this run. PHPStan's result cache was warm for some runs and cold
  for others, and a figure without its baseline overstates the case — see the table further up, which names
  both.
