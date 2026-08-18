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
