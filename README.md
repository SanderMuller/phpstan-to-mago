# phpstan-to-mago

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/phpstan-to-mago.svg?style=flat-square)](https://packagist.org/packages/sandermuller/phpstan-to-mago)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/SanderMuller/phpstan-to-mago/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/phpstan-to-mago/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/phpstan-to-mago.svg?style=flat-square)](https://packagist.org/packages/sandermuller/phpstan-to-mago)
[![License](https://img.shields.io/packagist/l/sandermuller/phpstan-to-mago.svg?style=flat-square)](LICENSE)

You run [Mago](https://github.com/carthage-software/mago) and you still run PHPStan, because your team's
conventions exist only as PHPStan rules. This moves them: a rule's *decisions* usually reduce to guards over
the syntax tree plus a few questions about the enclosing class, and that much becomes a Mago plugin. The rule
object itself cannot travel — it reaches into classes Mago does not expose to PHP.

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

`--help` lists the rest. Each target writes its own subdirectory of `--out`, with a
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

- **Rules are the unit; a package is not.** `phpstan/phpstan-deprecation-rules` is the first with both its
  rules emitting, and is still not droppable: its neon also registers five `Restricted*UsageExtension`
  services, where "call to deprecated method" comes from, and none of those is a rule.
- As a pre-filter: transpiled rules on save and push, full PHPStan on merge or nightly.

It does not make an existing PHPStan run cheaper: dropping rules does not drop the parsing and type
inference underneath. And the pre-filter does not gate — a Mago-clean commit can still fail the deferred run,
because a refused rule reports nothing, and so does one that under-reports.

## Running a generated plugin

Mago runs PHP extensions as workers. Register the plugins in one, then point `mago.toml` at it:

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

Override in the worker, which `manifest.json` names. A rule taking a PHPStan *service* is refused — no
worker can supply one.

## Refusals

A construct outside the vocabulary is refused, naming it and its line:

```
  REFUSE  ClosureUsesThisRule: no mapping for ->static on a hook-node (line 26)
```

Read them next to the `emitted` count, never alone.

## What it can translate

Seven packages, pinned rule by rule in `tests/Fixtures/expected/census.md` and re-derived by a test.

| package | portable | emit | refused | covered by the engine |
|:--|--:|--:|--:|--:|
| `symplify/phpstan-rules` | 89 | 73 | 15 | 1 |
| `hihaho/phpstan-rules` | 8 | 7 | 1 | 0 |
| `tomasvotruba/type-coverage` | 5 | 5 | 0 | 0 |
| `tomasvotruba/cognitive-complexity` | 3 | 2 | 1 | 0 |
| `phpstan/phpstan-strict-rules` | 45 | 35 | 10 | 0 |
| `phpstan/phpstan-phpunit` | 13 | 5 | 8 | 0 |
| `phpstan/phpstan-deprecation-rules` | 2 | 2 | 0 | 0 |

`--status` counts 127 of 231 here and writes a page under `--out`; `spaze/phpstan-disallowed-calls` (38),
`composer/pcre` (2) and `larastan/larastan` (26) are in that denominator and emit nothing. Run it on your
own project.

<details>
<summary>What the vocabulary covers</summary>

Guard chains, `foreach` with an inline report, `sprintf` messages, `instanceof` narrowing, membership in a
constant set, comparisons on strings and integers, closures with their declared types, and a subtree search
with its count. Larger pieces:

- Helpers inlined from the rule, a trait or a parent class.
- The enclosing class: hierarchy, namespace, members with visibility, attributes and docblocks.
- Reflection at the use site, from Mago's codebase metadata.
- A producer handing a `{...}` record to a consumer, including one produced inside a loop.
- A collaborator that decides *and* builds the findings; only the reporting becomes a runtime pass.
- A collector-and-consumer pair, which becomes one whole-project pass with the *measurement* reimplemented —
  Mago has no collector. Five of `type-coverage`'s metrics are mapped this way.

</details>

An aggregate is mapped only once its numbers agree with the real rule on a real project, and carries its
measured bound: `run-coverage-corpus.php <project> --metric=<name>`.

## How far this is verified

Three things run, and each records rather than asserts:

| | |
|:--|:--|
| **per rule** | CI runs the real `mago` against real PHPStan over one example pair, comparing line and message. A rule that emits and reports nothing fails. |
| **per divergence** | each one found is pinned as a minimal case, so it survives the corpus moving on. [The record](tests/Fixtures/expected/divergences.md) goes red in either direction. |
| **per corpus** | `run-corpus-sweep.php` reads seven trees this package installs, so `composer install` reproduces it: **11327 agreeing against 31 divergences**, [each listed](tests/Fixtures/expected/corpus-sweep.md). |

Size is not what buys agreement: the two smallest trees carry most of the divergences and 1003 files of
PHPUnit carry none. [VERIFICATION.md](VERIFICATION.md) has every run, including the defects in this port the
differential caught first.

## Performance

`php tests/Support/run-benchmark.php <project>` runs both engines over your own code. Here, on
`vendor/nikic/php-parser/lib` — 270 files, 97 emitted rules, best of three on an idle machine:

| | wall | CPU |
|:--|--:|--:|
| mago, engine only | 4.77s | 4.10s |
| mago + a host with no plugins | 3.92s | 3.93s |
| mago + the 97 transpiled rules | 4.99s | 6.37s |
| PHPStan, cold result cache | 2.74s | 8.70s |
| PHPStan, warm result cache | 1.10s | 0.97s |

**The rules cost row three against row two**, not against row one: a host that starts and speaks the protocol
while registering nothing is what separates the host's own cost from the rules'. So the rules add **1.07s wall
and 2.44s CPU**, and the host itself is free — rows one and two are equal within their spreads, and their
ordering is noise rather than a result. The rules row has a 1.32s wall spread, so prefer its CPU figure.

**Not a wall-clock win on this corpus, and the reason is not the rules.** With `includes = []` the engine
alone runs in **0.17s** rather than 3.92s, so about 96% of the floor is mago indexing the include tree before
it analyses anything. Those includes are what let a rule reach a vendored parent — without them rules go
silently narrow — so the lever is their width: name the packages your rules need rather than all of `vendor`.
This project's own test suite went from 346s to 115s on exactly that change.

On CPU the port is already cheaper than a cold PHPStan and dearer than a warm one, because `mago analyze` has
no result cache. Measure your own.

## Requirements

PHP 8.4 for the transpiler, the floor the rule packages set. Generated plugins need Mago 1.47.6 or later:
that is where a compound assignment's operands started reporting their own type, and a plugin reading one is
silently wrong on anything earlier.

## Contributing

`composer qa-check` runs the lot. Two invariants matter most, both in `CLAUDE.md`: the emitted output is the
contract, and anything the vocabulary does not cover is refused.

## Credits

- [Sander Muller](https://github.com/SanderMuller)

## License

MIT. See [LICENSE](LICENSE).
