# phpstan-to-mago

Transpile PHPStan rules into [Mago](https://github.com/carthage-software/mago) analyzer plugins.

A PHPStan rule reaches into thousands of classes (`Type`, `TypeCombinator`, `ReflectionProvider`) that Mago
does not expose to PHP, so you cannot hand it a rule at runtime. But a rule's *decisions* usually reduce to
guards over the syntax tree plus a few questions about the enclosing class, and that much translates. This
tool reads a rule's PHP source and writes a Mago SDK plugin.

```bash
composer require --dev sandermuller/phpstan-to-mago
vendor/bin/phpstan-to-mago --target=php --out=build src/Rules/ForbiddenStaticConstFetchRule.php
vendor/bin/phpstan-to-mago --survey vendor/hihaho/phpstan-rules/src
```

```
--target=php       a Mago SDK plugin, an ordinary composer library, the default
--target=analyzer  a Rust analyzer plugin, which has to be compiled into Mago
--target=linter    a Rust lint rule, which has to be compiled into Mago
--out=DIR          the root to write under, defaulting to the current directory
--examples=DIR     PHP files the linter target reads its good and bad examples from
--survey           report what each rule would need, writing nothing
--from-config=DIR  work on the rules a project's own PHPStan registers, not the ones its
                   packages ship. Takes a project directory or a config file
--unverified       also emit an aggregate rule whose numbers do not yet agree with the
                   original. Refused by default
```

Each target writes into its own subdirectory of `--out`: `generated-php/`, `generated/` for the analyzer,
`generated-lint/` for the linter. Every target except the linter also writes `generated/manifest.json`,
mapping each rule to its identifier, its message formats and its constructor defaults. A directory is walked
for rules, skipping traits and abstract bases; a file you name yourself is refused if it is not a rule.

```
  TARGET  php

  EMIT    ForbiddenStaticConstFetchRule

emitted: 1, refused: 0 (target: php)
```

Every count names its target, because a rule can render as Rust and be refused as PHP.

An emitted plugin has two dependencies: this package, for the `Support` runtime it calls, and
`carthage-software/mago`, for the SDK types it implements.

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

A Mago project that wants rules from the PHPStan ecosystem. Mago cannot run a PHPStan rule, so those packages
are simply unavailable to it. A transpiled rule is the same check, running natively.

Two workflows follow from that:

- **A package that transpiles completely.** A Mago-only project gets its checks with no PHPStan anywhere.
  `tomasvotruba/cognitive-complexity` and `phpstan/phpstan-deprecation-rules` are each one rule short of this.
- **A fast pre-filter, with PHPStan deferred.** Run the transpiled rules on save and on push, and the full
  PHPStan on merge or nightly. The inner loop stops paying for PHPStan.

It does not make an existing PHPStan run cheaper. Dropping rules from a config does not drop the parsing and
type inference underneath, and nobody has measured what it saves, so treat that as unknown rather than small.
Run both on every commit and you have added a tool, not replaced one.

The pre-filter also has a real cost. A Mago-clean commit can still fail the deferred PHPStan run, because a
refused rule still lives only in PHPStan. It filters; it does not gate.

## Running a generated plugin

Mago runs PHP extensions as worker processes. Write a worker that registers the generated plugins, then point
`mago.toml` at it:

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

A generated plugin lives in the `Transpiled` namespace and is enabled by default, so `analyzer.plugins` needs
no entry. `mago analyze` reports under both the plugin identifier and the rule's own PHPStan identifier:

```
src/Bad.php:9:16: error[transpiled/forbidden-static-const-fetch-rule/fixture.forbiddenStaticConstFetch]: Avoid static access of constants
```

## Configured rules

A rule that takes values through its constructor gets a constructor on the generated plugin, carrying the rule
package's own defaults. Those come from its `extension.neon`, found through `composer.json`'s
`extra.phpstan.includes`:

```php
public function __construct(
    public readonly array $namespaces = ['App', 'Tests'],
    public readonly int $limit = 3,
) {}
```

A rule taking a PHPStan service is refused, naming it, because no worker can supply a `ReflectionProvider`.

Nothing from a consuming project is baked in. Construct the plugin with no arguments and it behaves like
PHPStan at package defaults; to override, pass your own values in the worker, which `manifest.json` names. A
constructor that *derives* a value is carried too, on the PHP target only, so long as the derivation touches
only configured values, literals and pure array and string functions.

## It refuses rather than approximates

A rule using a construct outside the vocabulary is refused, naming the construct and its line:

```
  REFUSE  IllegalConstructorStaticCallRule: access path outside the vocabulary: ->getFunction() (line 46)
```

That is the design. A plausible-but-wrong rule is worse than no rule, because you would trust it. So
`emitted` on its own means nothing: the generator refuses what it cannot translate, and the backend refuses
any operand it could not render.

Some refusals are the right answer. A node hook receives inferred types only at the positions it asks for
through `FileAnalysisRequirement`, while a PHPStan rule can ask about any sub-expression.

## What it can translate

Seven rule packages, surveyed with the tool rather than from memory. Each is pinned rule by rule in
`tests/Fixtures/expected/census.md`, which a test regenerates, so upstream drift lands as a diff there instead
of a stale table here. That file also lists what each refused body `needs:`, not only the obstacle that
stopped it first.

The denominator is the *portable* rules each package registers. A rule it ships but wires nowhere cannot run
for anybody. Three rules across the seven are excluded as well: they report nothing a plugin could carry,
writing a build artefact or handing a synthesised node back to PHPStan's own analysis, so no vocabulary entry
reaches them and a package holding one can never read as full.

| package | portable | emit | refused | covered by the engine |
|:--|--:|--:|--:|--:|
| `symplify/phpstan-rules` | 89 | 38 | 50 | 1 |
| `hihaho/phpstan-rules` | 7 | 4 | 3 | 0 |
| `tomasvotruba/type-coverage` | 10 | 1 | 9 | 0 |
| `tomasvotruba/cognitive-complexity` | 3 | 2 | 1 | 0 |
| `phpstan/phpstan-strict-rules` | 45 | 12 | 33 | 0 |
| `phpstan/phpstan-phpunit` | 13 | 0 | 13 | 0 |
| `phpstan/phpstan-deprecation-rules` | 2 | 1 | 1 | 0 |

That is 58 of 169. No package is complete yet, which is the number that matters for the first workflow above.

The vocabulary covers guard chains, `foreach` with an inline report, `sprintf` messages, `instanceof`
narrowing into a binding, membership in a constant set, and comparisons on strings and integers. The larger
pieces:

- Helpers inlined from the rule, from a trait or from a parent class.
- The enclosing class: its hierarchy, its namespace, and its own methods with their visibility, attributes
  and docblocks.
- Reflection at the use site, from Mago's codebase metadata: resolve the class written at a call site, ask
  whether it is known, ask for a method's parameter at a position.
- Closures, with their declared parameters and the types written on them.
- A subtree search for every node of a given kind, with the count in the message.
- A producer handing a `{...}` record to a consumer: the producer's guards become the rule's guards.

`$obj?->m(..)` is a separate hook, because Mago makes it a separate node. Anything not covered is refused by
name.

## How far this is verified

Per-rule agreement is proven and gated. For each emitted rule, CI starts the real `mago` binary with a worker
registering only that rule, and compares its findings against PHPStan running the original over the same two
files, on line and message text. A rule that emits and reports nothing fails.

Corpus-scale agreement is not proven, and no number here claims it. [VERIFICATION.md](VERIFICATION.md) has
the differential runs over eight corpora, their traced gaps, and the six real defects they found. The largest
is a 9199-file Symfony application at **1901 agreeing, 0 original-only, 0 port-only**.

## Performance

The same 20 rules on both engines, so this measures what the port costs rather than whether to keep PHPStan.
Best of three, one machine, the same 1090 files and the same 64 findings:

| | wall | CPU |
|:--|--:|--:|
| mago, engine only | 0.10s | 0.59s |
| mago + the 20 transpiled rules | 0.25s | 1.14s |
| PHPStan, cold | 5.86s | 33.13s |
| PHPStan, warm result cache | 0.59s | 0.56s |

Against a **cold** run it is 23x faster on wall clock and 29x cheaper on CPU. Against a **warm** PHPStan it is
still 2.4x faster on wall clock but costs roughly twice the CPU, because `mago analyze` has no result cache.
Any claim that does not say which of the two it means is overstating the case.

## Contributing

`composer qa-check` runs the lot. Two invariants matter most, both in `CLAUDE.md`: the emitted output is the
contract, snapshotted per target, and anything the vocabulary does not cover is refused.

The Rust targets only run compiled inside Mago's own crate and cannot ship as a package. They are kept because
both share the whole body translation, so emitting all three is a useful check that a change there has not
altered behaviour.

## Requirements

PHP 8.4 for the transpiler. That is the floor the rule packages themselves set. Generated plugins run under
Mago 1.47.1 or later. 1.47.0 is skipped because its release carries no Linux binary, so the package installs and
then cannot run.

## Credits

- [Sander Muller](https://github.com/SanderMuller)

## License

MIT. See [LICENSE](LICENSE).
