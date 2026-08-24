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
--unverified       also emit an aggregate rule whose numbers do not yet agree with the
                   original and whose gap has no named cause; refused by default, and the
                   refusal says by how much. Nothing is withheld today. Accepts the older
                   spelling --unverified-aggregates
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
value and which is a PHPStan service (`%noUnsafeRequestData.namespaces%` against `@reflectionProvider`),
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

Anything else in a derivation (a method call, a `new`, a function outside that set) is refused rather than
approximated, and only the PHP target carries a derivation at all.

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

Four rule packages, surveyed with the tool rather than from memory. All four are dev dependencies and pinned
rule by rule in `tests/Fixtures/expected/census.md`, which a test regenerates — so an upstream release that
adds or rewrites a rule shows up as a diff there rather than as a stale table here:

The denominator is what each package *registers*, which is the honest one: a rule a package ships but wires
nowhere cannot run for anybody, and counting it would understate every row.

| package | registers | emit | refused | covered by the engine |
|:--|--:|--:|--:|--:|
| `symplify/phpstan-rules` | 88 | 32 | 55 | 1 |
| `hihaho/phpstan-rules` | 9 | 4 | 5 | 0 |
| `tomasvotruba/type-coverage` | 10 | 1 | 9 | 0 |
| `tomasvotruba/cognitive-complexity` | 3 | 2 | 1 | 0 |

Every emitted rule is proven to *run*: the gate transpiles it, starts the real `mago` binary with a worker
registering only that rule, and compares the findings against PHPStan running the original over the same two
files, on line **and** message text. A rule that emits and reports nothing fails that gate, which is how
five rules were found to have been silently dead.

One rule is gated elsewhere and says so: `ParamTypeCoverageRule` is an aggregate over a whole project, so a
per-file pair is the wrong instrument for it. `AggregatesTypeCoverageTest` runs the real rule under real
PHPStan against the transpiler's own emission under real mago and compares by file, line, message and count.

The vocabulary covers guard chains, `foreach` with an inline report, `sprintf` messages, inlined helpers,
string and integer comparisons, `instanceof` narrowing into a binding, class-hierarchy questions about the
enclosing class, the declared namespace, membership in a constant set, and the receiver's inferred type.
Helpers are inlined from the rule, from a trait or from a parent class; they can answer a question, build the
finding, or forward to the helper that does. A rule that classifies what it found can report under a code it
computes. Configured values become constructor parameters carrying the rule package's own defaults. What is
not covered is refused by name.

Reflection is translated at the use site rather than passed through. A rule can resolve the class name written
at a call site, or read the class off the receiver's inferred type with `null` stripped first. From there it
can ask whether that class is known, ask for the method's parameter at a position, and put that parameter's
name in its message, all from Mago's codebase metadata, since there is no reflection object to hand a plugin.
`$obj?->m(..)` is a separate hook, because Mago makes it a separate node. A rule can also target a closure and
ask about its declared parameters and their written types, which is how the Symfony config-file rules
recognise a config file at all. A rule that loops a class-like's own methods reports one finding per method,
on the method's line, and can ask each one about its visibility, its attributes and its docblock. A rule can
search a subtree for every node of a given kind, count what it found, and put that count in its message. A
package that factors its detection into a producer handing back a `{...}` record and a consumer reading one
field out of it is followed through: the producer's guards become the rule's guards, and the record never
exists at analysis time.

### Where it does not agree yet

On `nikic/php-parser`'s 270 files of library source — a tree this repository installs, so the number can be
re-run — the differential is **1086 agreeing, 1 original-only, 34 port-only**. Reproduce with
`php tests/Support/run-corpus-differential.php . --paths=vendor/nikic/php-parser/lib`.

| identifier | agree | only-original | only-port |
|:--|--:|--:|--:|
| `complexity.functionLike` | 11 | 0 | 28 |
| `complexity.classLike` | 4 | 0 | 6 |
| `typeCoverage.paramTypeCoverage` | 1053 | 1 | 0 |
| `symplify.noDynamicName` | 13 | 0 | 0 |

All 34 are a configured threshold against a package default, and the numbers say so: this project's
`phpstan.neon.dist` sets `class: 80, function: 20`, and the package ships `class: 40, function: 9`. A generated
plugin deliberately carries its own package's defaults so that a generated project stands alone, so the port's
threshold is lower and it reports more. The same decision is why the aggregate's message differs at every site
it agrees on.

**Read the denominator before the agreement.** Of 49 identifiers under test, `php-parser` exercises **7** — 42
report nothing on either side, and a `0 0 0` row reads exactly like a clean agreement. Every Laravel- and
PHPUnit-shaped rule is in that 41, because a parser library contains nothing for them to find. The runner names
them now rather than leaving them in the total, so a reader can see that 1086 agreements come from seven rules
and choose a corpus that reaches the rest.

A second corpus, run for the same reason the first one is here — a green result on one tree says little.
`league/commonmark`'s 302 files: **34 agreeing, 1 original-only, 23 port-only**. The 23 are the same threshold
difference. The 1 is `ForbiddenArrayMethodCallRule` staying silent at `Environment.php:411`, where the original
reports, and that direction matters more: the port is *narrower* there.

Traced. The site is `[$normalizer, 'clearHistory']`, where `$normalizer` is reassigned and then narrowed by a
nested `instanceof UniqueSlugNormalizerInterface`. Instrumenting the emitted plugin in the differential's own
sandbox prints the type it gets:

    t0 = UniqueSlugNormalizer|UniqueSlugNormalizerInterface   soleObjectClass = NULL

Mago's narrowing keeps a **union of the class and the interface it implements**, where PHPStan resolves to one
type. `Support::soleObjectClass()` requires exactly one class — deliberately, because a rule naming a parameter
against one arbitrary member of a union would suggest a name the other does not have — so the port bails and
stays silent.

The obvious suspect was the interface-typed receiver, since the nine agreeing sites are class-typed, and a
control refutes it: `typeHasMethod()` answers yes for an interface-typed value and a class-typed one alike.

Not fixed, and the cost is why. A union whose every member is an ancestor of one particular member does collapse
to that member, and checking that needs `Codebase::getClassAncestors()` — which means threading a codebase
handle through `soleObjectClass()`, a public helper three emission sites call by that signature, and
regenerating every snapshot that holds it. For one site on one corpus, against an imprecision that is arguably
Mago's rather than the port's.

A third, `rector/rector`'s `src` — 490 files, chosen because these rules are written by the same author as
that codebase: **159 agreeing, 0 original-only, 81 port-only**, and again **7 of 49 identifiers exercised**. The
81 are the threshold difference. The `rector.*` identifiers stay silent even here, because Rector's `src` holds
the framework and its `AbstractRector` subclasses live under `rules/`.

That corpus arrived with one original-only finding, and tracing it found a real defect.
`ForbiddenArrayMethodCallRule` was silent on `\Closure::fromCallable([$rectorConfig, 'make'])` because
`Support::typeHasMethod()` asked the codebase for a method the class *declares* — so it answered no for every
method inherited from a parent. Measured on `RectorConfig::make()`, which comes from the container it extends:
`getMethod` null, `getDeclaringMethod` found, `methodExists` yes, hierarchy complete, four ancestors. It asks
`methodExists()` now, which is the hierarchy-inclusive question PHPStan's `hasMethod()->yes()` is.

The rule's example pair passed throughout, because `[$this, 'handle']` names a method written on the class
itself and the pair had no inherited method in it. It has one now.

The forty-ninth identifier is `phpParser.noLeadingBackslashInName`, and it is `0 0 0` on every corpus here.
That is the row shape this section warns about, so here is the control that separates "nothing to find" from
"never looked": no file in the whole installed tree writes `new Name('\..')`, `new FullyQualified('\..')` or
`new Relative('\..')` — the shape the rule forbids — including `nikic/php-parser` itself, whose classes the
rule names. The pair under `tests/Fixtures/examples` is where both tools do land on it, on the same two lines
with the same message.

*An earlier version of this paragraph said the node never reached the rule's hook.* That was wrong, and wrong
for an avoidable reason: the instrumentation I read it from had crashed part-way through the corpus, and I drew
a conclusion from a truncated log without checking the run had finished. The array reaches the hook, with two
elements, and both its types resolve.

### The Laravel corpora, and the 41 identifiers that had never fired

The three corpora above are libraries, and a library contains nothing for a Laravel- or PHPUnit-shaped rule to
find. That left most of the identifiers under test at `0 0 0` — the row shape that reads exactly like a clean
agreement. Two closed-source Laravel applications close most of that gap. Their numbers cannot be re-run by a
reader, which is the cost of using them, and they are quoted here for the one thing the public corpora cannot
say: whether these rules fire at all.

The first — 1860 files, all four rule packages installed and enforced — is **248 agreeing, 0 original-only, 54
port-only**, with the 54 the same configured-threshold-against-package-default difference as everywhere else.
It exercises nine identifiers, four of them for the first time: `symplify.noGlobalConst` (90 agreeing),
`symplify.requireExceptionNamespace` (111), `phpunit.noAssertFuncCallInTests` (26) and
`symplify.parentMethodVisibilityOverride` (8).

The second — 4228 files, the `hihaho` and coverage packages — is where the Laravel-shaped rules finally fire.
Four `hihaho.*` identifiers report, and all four agree exactly: `noEloquentWithProperty` 2, `noDebugIn` 2 (with
22 more the consumer silenced with `@phpstan-ignore`, which the harness counts separately), `noInvadeInAppCode`
2, and `noUnsafeRequestHelper` 1. Small numbers, and the point is not their size: these are the first findings
any of them have produced against code nobody wrote for them.

Across all four corpora **17 identifiers have now fired**, against 7 before.

That second run also arrived with **73 original-only** on `typeCoverage.paramTypeCoverage` — the direction that
matters, the port narrower than the rule. It is not a defect, and a control rather than a reading says so. The
consumer configures `param: 100`; the generated plugin carries the package's own default of `99`, deliberately,
so that a generated project stands alone. The application's coverage sits between the two, so PHPStan reports
and the port does not. Re-running the same plugin with `required: 100` gives **73 findings on exactly the same
73 sites** — no site in one set and not the other. The plausible reading was that the port misses untyped
closure and arrow-function parameters, since 72 of the 73 sites are closures; the control refutes it.

This run started at **203** port-only, of which 169 came from `NoDynamicNameRule` and were false positives.
`Support::isWrittenName()` descends into a name's first child, and a name written with a leading `\` arrives as
an `Identifier` whose child is a `FullyQualifiedIdentifier` — a kind the written-name list did not hold. So
`\count(..)` read as a *dynamic* name, and every `\`-prefixed global in a library became a finding. A bare
`count(..)` answered correctly all along, which is why the rule's example pair passed: it had no function call
in it at all. It has three now — bare, leading-backslash and namespace-qualified — and removing the fix fails
the gate.

An earlier figure here read "585 files of dependency-tree source, 214 findings against 19, with 17 agreeing".
That corpus was a consumer's vendor tree nobody else has, and the run predates the discovery that
`laravel/pao` was rewriting PHPStan's output for every one of these harnesses. Replaced rather than re-quoted:
a headline resting on an instrument since fixed, over a corpus nobody can obtain, is worth less than a smaller
number anyone can check.

The `type-coverage` parameter aggregate is measured and *bounded*. It was refused by default while the gap
had no named cause; every part of that gap now traces to one cause the port cannot reproduce, so it is emitted
with the bound stated in the generated file. On two Laravel consumers it over-counts by 81 of 13694 and by 37
of 11428, and that residue is `ClassReflection::hasMethod()` answered by PHPStan
reflection extensions — larastan's factory and auth extensions, plus three classes that ship inside
`phpstan.phar`. A Mago plugin has no equivalent.

It can under-count too, by a separate cause found on this repository's own vendor tree: a class declared twice
in one file behind a version guard is counted by PHPStan and by neither body here, which is -7 on
`nikic/php-parser`. Named because an earlier version of this paragraph said the port never under-counts, which
was a claim about two corpora rather than a property. `php tests/Support/run-coverage-corpus.php <consumer-root>`
reproduces the numbers and fails when a corpus run leaves the bound; one control isolates the mechanism in
CI.

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

PHP 8.4 for the transpiler. That is the floor the rule packages themselves set: `symplify/phpstan-rules` and
`tomasvotruba/type-coverage` both require it, and there is nothing to transpile without them. Generated
plugins target the Mago PHP SDK and run under Mago 1.47.1 or later. 1.47.0 is skipped because its release
carries no Linux binary, so the package installs and then cannot run.

## Credits

- [Sander Muller](https://github.com/SanderMuller)

## License

MIT. See [LICENSE](LICENSE).
