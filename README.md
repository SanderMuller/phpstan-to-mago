# phpstan-to-mago

Transpile PHPStan rules into [Mago](https://github.com/carthage-software/mago) analyzer plugins.

A PHPStan rule receives `(Node, Scope)` and reaches into thousands of classes: `Type`, `TypeCombinator`,
`ReflectionProvider`, the accessory types. Mago exposes none of that object graph to PHP, so a rule cannot
simply be handed to it at runtime. What a rule's *decisions* usually reduce to, though, is a chain of
guards over the syntax tree plus a handful of questions about the enclosing class, and those translate.

This reads a rule's PHP source and writes a Mago SDK plugin.

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
--unverified-aggregates
                   also emit an aggregate rule whose numbers do not yet agree with the
                   original; refused by default, and the refusal says by how much
```

Each target writes into its own subdirectory of `--out`, so the command above writes
`build/generated-php/ForbiddenStaticConstFetchRule.php`. The analyzer target writes to `generated/` and the
linter target to `generated-lint/`. Every target except the linter also writes `generated/manifest.json`,
which maps each rule to its identifier and its message formats.

A path is either a rule file or a directory. A directory is walked for rules, and files that cannot be
rules (traits, abstract bases) are skipped rather than reported as refusals. A file named on the command
line is taken as a rule, and refused by name if it turns out not to be one.

```
  TARGET  php

  EMIT    ForbiddenStaticConstFetchRule

emitted: 1, refused: 0 (target: php)
```

Every count names its target, because a rule can render as Rust and be refused as PHP.

An emitted plugin has two dependencies: this package, for the `Support` runtime it calls, and
`carthage-software/mago`, for the SDK types it implements:

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

        if (! Support::isName(Support::classPart($context, $node))) {
            return;
        }
        // ...
    }
}
```

## Running a generated plugin

Mago runs PHP extensions as worker processes. Write a worker that registers the generated plugins, then
point `mago.toml` at it:

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

A generated plugin is in the `Transpiled` namespace and is enabled by default, so `analyzer.plugins` needs
no entry. `mago analyze` reports under both the plugin identifier and the rule's own PHPStan identifier:

```
src/Bad.php:9:16: error[transpiled/forbidden-static-const-fetch-rule/fixture.forbiddenStaticConstFetch]: Avoid static access of constants
```

## Configured rules

A rule that takes values through its constructor gets a constructor on the generated plugin, carrying the
rule package's own defaults:

```php
public function __construct(
    public readonly array $namespaces = ['App', 'Tests'],
    public readonly int $limit = 3,
) {}
```

Those defaults are read from the package's own `extension.neon`, found through `composer.json`'s
`extra.phpstan.includes`. `services: arguments:` is what says which constructor argument is a configured
value and which is a PHPStan service — `%noUnsafeRequestData.namespaces%` against `@reflectionProvider` —
and only the first can be carried. A rule taking a service is refused, naming the service, because no worker
can supply a `ReflectionProvider`.

Nothing from a consuming project is baked in, so the generated file stays project independent. A worker that
constructs the plugin with no arguments behaves like PHPStan at package defaults; to override, pass values in
the worker and let `mago.toml` supply them:

```php
$namespaces = getenv('APP_NAMESPACES');

analyzerPlugins: [new NoDebugInNamespaceRule(
    $namespaces === false ? [] : explode(',', $namespaces),
)],
```

```toml
[extension-hosts.transpiled.environment]
APP_NAMESPACES = "App,Tests"
```

A constructor that *derives* a value is carried too, as long as the derivation touches only configured
values, literals and a closed set of pure array and string functions. The generated plugin is PHP and the
rule's own parameter names are kept, so the derivation is copied rather than translated:

```php
private readonly array $lookup;

public function __construct(
    public readonly array $unsafeMethods = ['input', 'all'],
) {
    $this->lookup = array_fill_keys(array_map(strtolower(...), $unsafeMethods), true);
}
```

Anything else in a derivation — a method call, a `new`, a function outside that set — is refused rather
than approximated, and only the PHP target carries a derivation at all.

## It refuses rather than approximates

A rule using a construct outside the vocabulary is refused, naming the construct and its line:

```
  REFUSE  NoWithOnStubRule: the inferred type of var_method_call.object is not a position the SDK exposes
```

That is the design, not a limitation to work around. A plausible-but-wrong rule is worse than no rule,
because you would trust it. Two consequences worth knowing:

- **`emitted` on its own means nothing.** The generator refuses what it cannot translate, *and* the backend
  refuses any operand it was handed and could not render. Without the second check the tool once reported
  ten rules emitted where six did not parse and two parsed while still containing Rust.
- **Some refusals are the right answer.** A node hook receives inferred types only at positions it asks
  for through `FileAnalysisRequirement` (the target, the receiver, the arguments), while a PHPStan rule can
  ask about any sub-expression. Where the subject is not one of those positions, refusing is correct.

## What it can translate

Four rule packages, surveyed with the tool rather than from memory:

| package | rules | emit | refused |
|:--|--:|--:|--:|
| `symplify/phpstan-rules` | 96 | 24 | 72 |
| `hihaho/phpstan-rules` | 20 | 3 | 17 |
| `tomasvotruba/type-coverage` | 10 | 0 | 10 |
| `tomasvotruba/cognitive-complexity` | 3 | 0 | 3 |

Every emitted rule is proven to *run*: the gate transpiles it, starts the real `mago` binary with a worker
registering only that rule, and compares the findings against PHPStan running the original over the same two
files — on line **and** message text. A rule that emits and reports nothing fails that gate, which is how
five rules were found to have been silently dead.

The vocabulary covers guard chains, `foreach` with an inline report, `sprintf` messages, inlined helpers,
string and integer comparisons, `instanceof` narrowing into a binding, class-hierarchy questions about the
enclosing class, the declared namespace, membership in a constant set, and the receiver's inferred type.
Helpers are inlined from the rule, from a trait or from a parent class; they can answer a question, build the
finding, or forward to the helper that does. A rule that classifies what it found can report under a code it
computes. Configured values become constructor parameters carrying the rule package's own defaults. What is
not covered is refused by name.

Reflection is translated at the use site rather than passed through. A rule can resolve the class name written
at a call site — or read the class off the receiver's inferred type, with `null` stripped first — ask whether
that class is known, ask for the method's parameter at a position, and put that parameter's name in its message,
all from Mago's codebase metadata, since there is no reflection object to hand a plugin. `$obj?->m(..)` is a
separate hook, because Mago makes it a separate node. A rule can also target a closure and ask about its declared
parameters and their written types, which is how the Symfony config-file rules recognise a config file at all. A
rule that loops a class-like's own methods reports one finding per method, on the method's line, and can ask each
one about its visibility, its attributes and its docblock. A rule can search a subtree for every node of a given
kind, count what it found, and put that count in its message. A package that factors its detection into a
producer handing back a `{...}` record and a consumer reading one field out of it is followed through: the
producer's guards become the rule's guards, and the record never exists at analysis time.

### Where it does not agree yet

On 585 files of dependency-tree source the emitted rules report more than PHPStan does — 214 findings
against 19, with 17 agreeing. Two things are mixed together in that gap and have not been separated:
Mago analyses without an autoloader, so the two tools do not see the same resolved classes; and at least
one port is genuinely wider than its original.

The `type-coverage` parameter aggregate is measured and wrong: PHPStan counts 4057 parameters with 1994
typed where the port counts 3079 with 2927. It is implemented, refused by default, and its refusal quotes
those numbers. `--unverified-aggregates` emits it anyway for whoever works on it next.

That is the honest state: per-rule agreement on example pairs is proven and gated; corpus-scale agreement is
not, and no number here should be read as claiming it.

## Performance

The point of running rules on Mago is cost, so here it is honestly. Best of three, one machine, the same
1090 files, the same 20 rules and the same 64 findings:

| | wall | CPU |
|:--|--:|--:|
| mago, engine only | 0.10s | 0.59s |
| mago + the 20 transpiled rules | 0.25s | 1.14s |
| PHPStan, cold | 5.86s | 33.13s |
| PHPStan, warm result cache | 0.59s | 0.56s |

Against a **cold** run it is 23x faster on wall clock and 29x cheaper on CPU. Against a **warm** PHPStan it
is still 2.4x faster on wall clock but costs roughly twice the CPU, because `mago analyze` has no result
cache and does the whole job every time. So: much cheaper in CI, faster in an editor loop, not cheaper on
CPU once PHPStan's cache is warm. Any claim about this that does not say which of the two it means is
overstating the case.

## The Rust target

`--target=linter` and `--target=analyzer` emit Rust instead. Generated Rust only runs compiled
inside Mago's own crate, so it cannot ship as a package and is not what this tool is for. It is kept
because both targets share the whole body translation, which makes it a useful check that a change to that
has not altered behaviour.

## Contributing

`composer qa-check` runs the lot. Two invariants matter more than the rest, and both are in `CLAUDE.md`:
the emitted output is the contract and is snapshotted per target, and anything the vocabulary does not
cover is refused rather than approximated.

## Requirements

PHP 8.4 for the transpiler — the floor the rule packages themselves set, since `symplify/phpstan-rules` and
`tomasvotruba/type-coverage` both require it, and there is nothing to transpile without them. Generated
plugins target the Mago PHP SDK and run under Mago 1.47.1 or later. 1.47.0 is skipped because its release
carries no Linux binary, so the package installs and then cannot run.

## Credits

- [Sander Muller](https://github.com/SanderMuller)

## License

MIT. See [LICENSE](LICENSE).
