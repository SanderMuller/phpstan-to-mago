# phpstan-to-mago

You run [Mago](https://github.com/carthage-software/mago) and you still run PHPStan, because your team's
conventions exist only as PHPStan rules. This moves them: a rule's *decisions* usually reduce to guards over
the syntax tree plus a few questions about the enclosing class, and that much translates into a Mago plugin.
The rule object itself cannot travel — it reaches into thousands of classes Mago does not expose to PHP.

```bash
composer require --dev sandermuller/phpstan-to-mago
vendor/bin/phpstan-to-mago --target=php --out=build src/Rules/ForbiddenStaticConstFetchRule.php
vendor/bin/phpstan-to-mago --survey vendor/hihaho/phpstan-rules/src
```

| flag | effect |
|:--|:--|
| `--target=php\|analyzer\|linter` | a Mago plugin (default), or Rust for a fork of Mago itself |
| `--survey` | report what each rule would need, writing nothing |
| `--from-config=DIR` | the rules a project registers, not the ones its packages ship |

`--help` lists the rest. Each target writes into its own subdirectory of `--out`, with a
`generated/manifest.json` naming each rule's identifier, messages and defaults.

**Only the `php` target installs.** It emits a worker plus the `mago.toml` snippet registering it, against
Mago's supported plugin API. The two Rust targets emit source for Mago's own bundled-plugin registry, which
has no registration path from outside Mago's tree. Every count here is the `php` target — a rule can render
as Rust and be refused as PHP.

A plugin depends on this package and on `carthage-software/mago`:

```php
final class ForbiddenStaticConstFetchRule implements Plugin, NodeAnalysisHook
{
    public function getTargets(): array
    {
        return [NodeKind::ClassConstantAccess];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $node = $context->node;

        if (!(Support::isName(Support::classPart($context, $node)))) {
            return;
        }
        // ...
    }
}
```

## What this is for

- A package that transpiles completely needs no PHPStan at all. `tomasvotruba/cognitive-complexity` and
  `phpstan/phpstan-deprecation-rules` are each one rule short.
- As a pre-filter: transpiled rules on save and push, full PHPStan on merge or nightly.

It does not make an existing PHPStan run cheaper: dropping rules does not drop the parsing and type inference
underneath. And the pre-filter does not gate — a Mago-clean commit can still fail the deferred run, because a
refused rule reports nothing and so does a translated rule that under-reports.

## Running a generated plugin

Mago runs PHP extensions as worker processes. Register the plugins in a worker and point `mago.toml` at it:

```php
<?php // worker.php

declare(strict_types=1);

use Mago\Sdk\Extension;
use Mago\Sdk\Worker;
use Transpiled\ForbiddenStaticConstFetchRule;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/build/generated-php/ForbiddenStaticConstFetchRule.php';

(new Worker(new Extension(
    identifier: 'acme/transpiled',
    name: 'Transpiled PHPStan rules',
    version: '0.0.0',
    analyzerPlugins: [new ForbiddenStaticConstFetchRule()],
)))->run();
```

```toml
[extension-hosts.transpiled]
command = ["php", "worker.php"]
```

Generated plugins live in the `Transpiled` namespace and are on by default, so `analyzer.plugins` needs no
entry.

## Configured rules

A rule taking constructor values gets them on the generated plugin, at the package's own defaults:

```php
public function __construct(
    public readonly array $namespaces = ['App', 'Tests'],
    public readonly int $limit = 3,
) {}
```

Nothing from a consuming project is baked in; override in the worker, which `manifest.json` names. A rule
taking a PHPStan service is refused by name: no worker can supply a `ReflectionProvider`.

## Refusals

A rule using a construct outside the vocabulary is refused, naming the construct and its line:

```
  REFUSE  ClosureUsesThisRule: no mapping for ->static on a hook-node (line 26)
```

Read the refusals next to the `emitted` count, never alone. Two checks produce them: the generator refuses a
construct it cannot translate, the backend an operand it could not render.

## What it can translate

Seven packages, pinned rule by rule in `tests/Fixtures/expected/census.md`, which a test regenerates, so
upstream drift shows up there as a diff rather than here as a stale table.

| package | portable | emit | refused | covered by the engine |
|:--|--:|--:|--:|--:|
| `symplify/phpstan-rules` | 89 | 59 | 29 | 1 |
| `hihaho/phpstan-rules` | 7 | 6 | 1 | 0 |
| `tomasvotruba/type-coverage` | 10 | 5 | 5 | 0 |
| `tomasvotruba/cognitive-complexity` | 3 | 2 | 1 | 0 |
| `phpstan/phpstan-strict-rules` | 45 | 22 | 23 | 0 |
| `phpstan/phpstan-phpunit` | 13 | 4 | 9 | 0 |
| `phpstan/phpstan-deprecation-rules` | 2 | 1 | 1 | 0 |

`--status` counts 99 of 209 here. The table is the seven packages that emit anything;
`spaze/phpstan-disallowed-calls` (38) and `composer/pcre` (2) are in the denominator and not the table. Run
it on your own project for its figure.

<details>
<summary>What the vocabulary covers</summary>

Guard chains, `foreach` with an inline report, `sprintf` messages, `instanceof` narrowing, membership in a
constant set, comparisons on strings and integers, closures with their declared types, and a subtree search
with its count. Larger pieces:

- Helpers inlined from the rule, a trait or a parent class.
- The enclosing class: hierarchy, namespace, methods with visibility, attributes and docblocks, and the mixed
  member list a rule walks to ask each member what it is.
- Reflection at the use site, from Mago's codebase metadata.
- A producer handing a `{...}` record to a consumer, including one produced inside a loop.
- A collaborator that decides *and* builds the findings; only the reporting becomes a runtime pass.
- A collector-and-consumer pair. Mago has no collector, so the pair becomes one whole-project pass with the
  *measurement* reimplemented. Five of `type-coverage`'s metrics are mapped this way.

</details>

An aggregate is mapped only once its numbers agree with the real rule on a real project, and carries the
bound it was measured at: `tests/Support/run-coverage-corpus.php <project> --metric=<name>`.

## How far this is verified

Per-rule agreement is gated: for each emitted rule CI runs the real `mago` binary against real PHPStan over
the same two files and compares line and message. A rule that emits and reports nothing fails.

Corpus-scale agreement is not proven. Four vendor trees read 11744 agreeing against 29 divergences, each with
a written cause; [VERIFICATION.md](VERIFICATION.md) has the runs and the eight defects they found.

## Performance

`php tests/Support/run-benchmark.php <project>` runs both engines over your own code. Here, on
`vendor/nikic/php-parser/lib` — 270 files, 80 emitted rules, best of three:

| | wall | CPU |
|:--|--:|--:|
| mago, engine only | 3.84s | 3.76s |
| mago + the 80 transpiled rules | 5.79s | 7.17s |
| PHPStan, cold result cache | 2.69s | 9.35s |
| PHPStan, warm result cache | 0.74s | 0.71s |

The rules add **1.95s wall and 3.41s CPU** — the marginal cost, which no total gives. Five whole-codebase
aggregates are 1.21s of that wall; the other 75 rules cost 0.70s together. The engine baseline tracks the
resolution set, so it moves with your `includes` rather than your sources.

**This is not a speed win on this corpus**: 2.2x slower than a cold PHPStan on wall clock, 1.3x cheaper on
CPU, slower on both than a warm one, because `mago analyze` has no result cache. Measure your own.

## Requirements

PHP 8.4 for the transpiler, the floor the rule packages themselves set. Generated plugins run under Mago
1.47.1 or later. Skip 1.47.0: its release carries no Linux binary, so it installs and then cannot run.

## Contributing

`composer qa-check` runs the lot. Two invariants matter most, both in `CLAUDE.md`: the emitted output is the
contract, and anything the vocabulary does not cover is refused.

## Credits

- [Sander Muller](https://github.com/SanderMuller)

## License

MIT. See [LICENSE](LICENSE).
