# phpstan-to-mago

Transpile PHPStan rules into [Mago](https://github.com/carthage-software/mago) analyzer plugins.

A PHPStan rule reaches into thousands of classes Mago does not expose to PHP, so you cannot hand Mago a rule
at runtime. A rule's *decisions*, though, usually reduce to guards over the syntax tree plus a few questions
about the enclosing class, and that much translates.

```bash
composer require --dev sandermuller/phpstan-to-mago
vendor/bin/phpstan-to-mago --target=php --out=build src/Rules/ForbiddenStaticConstFetchRule.php
vendor/bin/phpstan-to-mago --survey vendor/hihaho/phpstan-rules/src
```

```
--target=php|analyzer|linter  a Mago SDK plugin (default), or Rust to compile into Mago
--out=DIR                     where to write, defaulting to the current directory
--survey                      report what each rule would need, writing nothing
--from-config=DIR             the rules a project's PHPStan registers, not the ones its
                              packages ship
```

`--help` lists the rest.

Each target writes into its own subdirectory of `--out`, plus a `generated/manifest.json` naming each rule's
identifier, message formats and constructor defaults. Counts name their target, because a rule can render as
Rust and be refused as PHP. A plugin depends on this package and on `carthage-software/mago`:

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

A transpiled rule runs the same check natively:

- A package that transpiles completely needs no PHPStan at all. `tomasvotruba/cognitive-complexity` and
  `phpstan/phpstan-deprecation-rules` are each one rule short of that.
- As a pre-filter: transpiled rules on save and on push, full PHPStan on merge or nightly.

It does not make an existing PHPStan run cheaper, because dropping rules does not drop the parsing and type
inference underneath.

The pre-filter does not gate. A Mago-clean commit can still fail the deferred PHPStan run, since a refused
rule lives only in PHPStan, and both failures look the same as a passing run: a refused rule reports nothing,
and so does a translated rule that under-reports.

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

Generated plugins live in the `Transpiled` namespace and are enabled by default, so `analyzer.plugins` needs
no entry.

## Configured rules

A rule taking constructor values gets them on the generated plugin, at the package's own defaults:

```php
public function __construct(
    public readonly array $namespaces = ['App', 'Tests'],
    public readonly int $limit = 3,
) {}
```

Nothing from a consuming project is baked in. Override in the worker, which `manifest.json` names. A rule
taking a PHPStan service is refused, naming it, because no worker can supply a `ReflectionProvider`.

## Refusals

A rule using a construct outside the vocabulary is refused, naming the construct and its line:

```
  REFUSE  ClosureUsesThisRule: no mapping for ->static on a hook-node (line 26)
```

Read the refusals next to the `emitted` count, never the count alone. Two checks produce them: the generator
refuses a construct it cannot translate, and the backend refuses an operand it could not render. `--unverified`
opts out of one refusal, for an aggregate whose numbers do not yet agree, and nothing sits behind it today.

## What it can translate

Seven packages, pinned rule by rule in `tests/Fixtures/expected/census.md`, which a test regenerates, so
upstream drift shows up as a diff there instead of a stale table here.

| package | portable | emit | refused | covered by the engine |
|:--|--:|--:|--:|--:|
| `symplify/phpstan-rules` | 89 | 56 | 32 | 1 |
| `hihaho/phpstan-rules` | 7 | 6 | 1 | 0 |
| `tomasvotruba/type-coverage` | 10 | 5 | 5 | 0 |
| `tomasvotruba/cognitive-complexity` | 3 | 2 | 1 | 0 |
| `phpstan/phpstan-strict-rules` | 45 | 22 | 23 | 0 |
| `phpstan/phpstan-phpunit` | 13 | 4 | 9 | 0 |
| `phpstan/phpstan-deprecation-rules` | 2 | 1 | 1 | 0 |

That is 96 of the 169 portable rules: the ones each package registers, minus three that report nothing a
plugin could carry. `--status` counts whatever *your* project installed instead.

<details>
<summary>What the vocabulary covers</summary>

Guard chains, `foreach` with an inline report, `sprintf` messages, `instanceof` narrowing, membership in a
constant set, comparisons on strings and integers, closures with their declared types, and a subtree search
with its count. The larger pieces:

- Helpers inlined from the rule, a trait or a parent class.
- The enclosing class: hierarchy, namespace, its methods with visibility, attributes and docblocks, and the
  mixed member list a rule walks to ask each member what it is.
- Reflection at the use site, from Mago's codebase metadata.
- A producer handing a `{...}` record to a consumer, including one produced inside a loop.
- A collaborator that decides *and* builds the findings. The guards still come from the rule; only the
  reporting becomes a runtime pass.
- A collector-and-consumer pair. Mago has no collector, so the pair becomes one whole-project pass and the
  *measurement* is reimplemented. Five of `type-coverage`'s metrics are mapped this way.

`$obj?->m(..)` and `$obj->m(...)` are separate hooks, because Mago makes each a separate node.

</details>

An aggregate is mapped only once its numbers agree with the real rule on a real project, and it carries the
bound it was measured at. Reproduce any with
`tests/Support/run-coverage-corpus.php <project> --metric=<name>`.

## How far this is verified

Per-rule agreement is gated: for each emitted rule CI runs the real `mago` binary against real PHPStan over
the same two files and compares line and message. A rule that emits and reports nothing fails.

Corpus-scale agreement is not proven. [VERIFICATION.md](VERIFICATION.md) has the differential runs, their
traced gaps, and the defects they found. The largest run is a 9199-file Symfony application, at 1901
agreeing, 0 original-only, 0 port-only.

## Performance

The same 20 rules on both engines, so this measures what the port costs. Best of three, one machine, the same
1090 files and 64 findings:

| | wall | CPU |
|:--|--:|--:|
| mago, engine only | 0.10s | 0.59s |
| mago + the 20 transpiled rules | 0.25s | 1.14s |
| PHPStan, cold | 5.86s | 33.13s |
| PHPStan, warm result cache | 0.59s | 0.56s |

Against a cold run that is 23x faster on wall clock and 29x cheaper on CPU. Against a warm PHPStan it is 2.4x
faster on wall clock but costs roughly twice the CPU, because `mago analyze` has no result cache. Quote a
speedup with the baseline it came from.

## Requirements

PHP 8.4 for the transpiler, the floor the rule packages themselves set. Generated plugins run under Mago
1.47.1 or later. Skip 1.47.0: its release carries no Linux binary, so it installs and then cannot run.

## Contributing

`composer qa-check` runs the lot. Two invariants matter most, both in `CLAUDE.md`: the emitted output is the
contract, snapshotted per target, and anything the vocabulary does not cover is refused.

## Credits

- [Sander Muller](https://github.com/SanderMuller)

## License

MIT. See [LICENSE](LICENSE).
