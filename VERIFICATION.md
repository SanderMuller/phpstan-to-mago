# Verification

The README states what this tool emits. This file states how much of that is proven, and where the port
and the original rule still disagree. Two kinds of evidence live here: a per-rule gate that runs in CI, and
differential runs over code nobody wrote for this project.

## The per-rule gate

Every emitted rule is proven to *run*. The gate transpiles it, starts the real `mago` binary with a worker
registering only that rule, and compares the findings against PHPStan running the original over the same two
files, on line **and** message text. A rule that emits and reports nothing fails that gate, which is how
five rules were found to have been silently dead.

One rule is gated elsewhere and says so. `ParamTypeCoverageRule` is an aggregate over a whole project, so a
per-file pair is the wrong instrument for it. `AggregatesTypeCoverageTest` runs the real rule under real
PHPStan against the transpiler's own emission under real mago and compares by file, line, message and count.

## Corpus differentials


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

Two more corpora were run for the `rector.*` identifiers, which stay silent even on Rector's own `src`
because its `AbstractRector` subclasses live under `rules/`. That directory — 801 files — is **260 agreeing,
0 original-only, 269 port-only**, and 200 files of third-party Rector rules (`driftingly/rector-laravel` and
one closed-source package) are **70 / 0 / 68**. Neither fires a `rector.*` identifier, and both port-only
figures are the threshold difference.

A control rather than a shrug, because "no Rector rule violates a rule about Rector rules" is exactly the
story that would be comfortable and wrong. Parsing all 1001 files finds **649 `refactor()` methods and not one
whose every `return` is `null`** — the shape `rector.noOnlyNullReturnInRefactor` forbids — so there is nothing
to find. Both rules have example pairs under `tests/Fixtures/examples` where the gate makes them fire, so the
other half, that the port never looked, is ruled out separately.

A sixth corpus was attempted and **discarded**: `phpstan-src`'s rule tests printed
`agree 0, only-original 0, only-port 313`, which reads like a catastrophic divergence. PHPStan had reported
three `phpstan.parse` errors — those fixture files are invalid PHP on purpose — and analysed nothing, while
the port has its own parser and analysed them anyway. `PhpstanReport` refuses such a report now, naming the
files: a file the original could not parse is a file no rule ran in, and every port finding there is a phantom.
Removing the guard fails its test, and the run above refuses instead of printing counts.

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

