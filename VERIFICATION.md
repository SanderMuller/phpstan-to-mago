# Verification

The README states what this tool emits. This file states how much of that is proven, and where the port
and the original rule still disagree. Two kinds of evidence live here: a per-rule gate that runs in CI, and
differential runs over code nobody wrote for this project.

**It is append-only, so a later entry beats an earlier one and nothing above is edited to match.** That is
right for a measurement — a run happened, and its numbers stay what they were — and it expires anything
written about what does *not* exist yet. "Not built", "left open", "the instrument for this is X" are claims
with a shelf life, and the sentence does not change when someone builds it.

Grep accordingly: find the phrase, then check whether a later section revisits it. Two claims here were read
as current after being superseded, and one of them was relayed to the user twice as an open decision that had
been closed the same day it was written. Where a later entry supersedes an earlier one, the earlier one now
carries a pointer — add one when you supersede something, because the reader who needs it is grepping rather
than reading forward.

## The per-rule gate

Every emitted rule is proven to *run*. The gate transpiles it, starts the real `mago` binary with a worker
registering only that rule, and compares the findings against PHPStan running the original over the same two
files, on line **and** message text. A rule that emits and reports nothing fails that gate, which is how
five rules were found to have been silently dead.

One rule is gated elsewhere and says so. `ParamTypeCoverageRule` is an aggregate over a whole project, so a
per-file pair is the wrong instrument for it. `AggregatesTypeCoverageTest` runs the real rule under real
PHPStan against the transpiler's own emission under real mago and compares by file, line, message and count.

## Sizing the type renderer

27 rule classes across the installed packages interpolate a rendered type into their message, and Mago's
`Type::__toString()` disagrees with PHPStan's `describe(VerbosityLevel::typeOnly())` on four measured shapes.
Before building a renderer over `Type::$atomicTypes`, the question is how much the difference is worth and
how many atomic kinds one would have to know. `tests/Support/run-render-census.php` answers both by counting,
over a real corpus, every type those rules read from — conditions, arithmetic operands, receivers.

On Shopware's 9199 files:

| | |
|:--|--:|
| types observed | 243822 |
| rendered differently by `Type::__toString()` | **22868 (9.38 %)** |
| — a generic, rendered without its parameters | 14003 |
| — an intersection, rendered as its first member only | 6395 |
| — a nullable scalar, members reversed | 2595 |
| — a literal `true`, rendered as `bool` | **0** |
| distinct atomic kinds reached | **24** |

Three things that decide the design. The error rate is **one type in eleven**, not a rounding error, so
shipping `__toString()` would be visibly wrong across those 27 rules. The kind count is 24 rather than the
fifty-odd the SDK declares, and six of them cover 97 % — `NamedObjectType` alone is 174405 — so a fallback
for the unmapped tail is a footnote rather than the main path. And the literal-bool divergence, one of the
four, **never occurs** at these positions.

The intersections are not exotic either: 4561 of the 6415 are `Foo&PHPUnit\Framework\MockObject\MockObject`
from `createMock()`, which is what a test suite looks like.

A fatal in the probe worker is worth knowing about, because it does not look like one. An early version read
a property some atomic class does not have, the worker died after 23 calls, and the output read as "the hook
barely fires" — 23 calls where a later run counted 223571. The runner refuses an empty result for that reason.

## Sizing the trinary

PHPStan's type queries answer three ways — yes, no, maybe — and the port has one boolean. Whether that
matters is two separate questions, and both are measurable from the render census's 243822 inferred types.

**`->no()` collapsed to `! ->yes()` is wrong, and `Maybe` is reachable.** 4.23 % of those types would make an
`isNull()` a `Maybe` and 0.88 % an `isBoolean()` — partly this and partly not, where PHPStan answers neither
yes nor no. So `->no()` is refused by name rather than emitted. It costs nothing today: of the 93 trinary
tails in the seven installed packages **86 are `->yes()`, six `->no()`, one `->maybe()`**, and no *emitting*
rule uses a `->no()`. The exposure is entirely latent, which is the point of refusing it.

**`->yes()` is narrower than PHPStan, and rarely.** The port answers a type query through
`soleObjectClass()`, which will not reduce a union — so `isInstanceOf(..)->yes()` on a union of two
subclasses is `yes` in PHPStan and false here. Narrower rather than wider, which is the safe direction, and
the frequency is **0.31 %**: 750 of the 243822 types are a union of two or more named objects. 69.3 % are a
single named object, which reduces, and a further 1.47 % are one object plus `null`.

That 0.31 % is corroborated by the one original-only finding on `league/commonmark`, which is exactly this
shape — `UniqueSlugNormalizer|UniqueSlugNormalizerInterface` — and is one site in a 302-file corpus.

Modelling the trinary properly is therefore a correctness guard rather than a coverage unlock. It blocks
nothing today, and the two divergences it would close are one that is refused and one that is 0.31 % and in
the safe direction.

## Corpus differentials


On `nikic/php-parser`'s 270 files of library source — a tree this repository installs, so the number can be
re-run — the differential is **1248 agreeing, 1 original-only, 409 port-only**. Reproduce with
`php tests/Support/run-corpus-differential.php . --paths=vendor/nikic/php-parser/lib`.

| identifier | agree | only-original | only-port |
|:--|--:|--:|--:|
| `typeCoverage.paramTypeCoverage` | 1053 | 1 | 0 |
| `typeCoverage.returnTypeCoverage` | 120 | 0 | 0 |
| `typeCoverage.constantTypeCoverage` | 0 | 0 | 375 |
| `complexity.functionLike` | 11 | 0 | 28 |
| `complexity.classLike` | 4 | 0 | 6 |
| `symplify.noDynamicName` | 13 | 0 | 0 |
| `symplify.explicitAbstractPrefixName` | 19 | 0 | 0 |
| `typeCoverage.propertyTypeCoverage` | 8 | 0 | 0 |
| `symplify.requiredInterfaceContractNamespace` | 8 | 0 | 0 |
| `symplify.explicitInterfaceSuffixName` | 7 | 0 | 0 |
| `symplify.forbiddenStaticClassConstFetch` | 2 | 0 | 0 |
| `symplify.requireExceptionNamespace` | 2 | 0 | 0 |
| `symplify.multipleClassLikeInFile` | 1 | 0 | 0 |

All 409 are a configured threshold against a package default, and the configurations say so. This project's
`phpstan.neon.dist` sets `class: 80, function: 20` where the package ships `class: 40, function: 9`, and it
sets `constant: 0` — which switches the constant metric off for the original — where the package ships
`constant_type: 99`. A generated plugin deliberately carries its own package's defaults so that a generated
project stands alone, so the port's threshold is lower or present and it reports more. The same decision is why
the aggregate's message differs at every site it agrees on.

This table was 1086 / 1 / 34 over 49 identifiers when it was first written, and the corpus has gained emitting
rules since. The number moved because more rules run, not because the port drifted: every row added is a `0 0`
row or the constant metric this section now names.

**Read the denominator before the agreement.** Of 73 identifiers under test, `php-parser` exercises **13** — 60
report nothing on either side, and a `0 0 0` row reads exactly like a clean agreement. Every Laravel- and
PHPUnit-shaped rule is in that 60, because a parser library contains nothing for them to find. The runner names
them now rather than leaving them in the total, so a reader can see which rules the agreements come from and
choose a corpus that reaches the rest.

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

### Shopware, and the two families that had never fired at all

A Symfony 7.3 application, 9199 files, carrying `symplify/phpstan-rules`, `phpstan/phpstan-strict-rules`,
`phpstan/phpstan-symfony`, `phpstan/phpstan-phpunit`, `phpstan/phpstan-deprecation-rules` and
`rector/type-perfect`. It is **895 agreeing, 0 original-only, 0 port-only** — exact agreement, and with almost
nothing to discount, because it does not install the cognitive-complexity package that produces the
threshold difference everywhere else.

Since re-measured at **1901 agreeing, 0 original-only, 0 port-only**, after hooking the three constructs
strict-rules forbids outright: `empty.notAllowed` alone agrees on 1006 sites, exactly. `backtick.notAllowed`
and `variable.dynamicName` emit and report nothing here, which is what a codebase that already forbids them
looks like.

Five identifiers fire here for the first time, including the two families that had never produced a finding
anywhere: `phpunit.noMockObjectAndRealObjectProperty` (648 agreeing), `symplify.multipleClassLikeInFile`
(140), `symplify.forbiddenStaticClassConstFetch` (54), `symfony.singleArgEventDispatch` (31),
`symfony.noFindTaggedServiceIdsCall` (16), `symplify.foreachCeption` (3) and `constructor.call` (1). That
takes the identifiers that have ever fired to **22**.

The run arrived at **893 / 2 / 1**, and both disagreements were real defects rather than imprecision. The
fixes are described where the runs found them, and the numbers above are the re-run afterwards.

*This corpus is on the author's machine and not one a reader can obtain.* It is quoted for the one thing the
public corpora cannot answer — whether the Symfony- and PHPUnit-shaped rules fire at all — and the earlier
figures were re-run alongside it to show nothing else moved: `rector/rector/src` stays at 159 / 0 / 81 and
`nikic/php-parser` at 1086 / 1 / 34.

#### The two defects it found

**Silent.** `symplify.forbiddenArrayMethodCall` missed both `array($this, 'loadClass')` in a vendored
`ClassLoader`. `[..]` and `array(..)` are one node to php-parser and two kinds to Mago, and the plugin for
`Array_` registered `NodeKind::Array` alone. It registers `LegacyArray` too now — probed first, because a
second kind only helps if the body reads it the same way: a `LegacyArray` carries the same `ArrayElement`
children, and `isArray()` already answered true for it. The identifier goes from 0 agreeing to 2.

**Too loud.** `symfony.singleArgEventDispatch` reported `$this->dispatch($nested, $name)` in a class
implementing `EventDispatcherInterface`, where PHPStan is silent. The rule guards with
`! $callerType instanceof ObjectType`, and `$this` is a `ThisType` — read in `phpstan-src` rather than
assumed: `ThisType extends StaticType`, and `StaticType implements TypeWithClassName` without extending
`ObjectType`. Mago marks the distinction on the atomic, measured as `isThis: true, static: true` for `$this`
and false for an ordinary receiver, so `typeIsNamedObject()` reads it. Nine rules across the packages carry
that guard and every one of them was wider than its original on a `$this->` receiver.

One thing the first fix nearly lost. Pint's `array_syntax` rewrote the new `array($this, 'handle')` in the
example pair into `[..]`, and the suite stayed green — the case simply stopped being exercised. The file is
in pint's `notPath` now.

#### And a third, in the walk rather than in a rule

`phpat/phpat` is installed in that application and contributed **nothing** — not a refusal, not a zero, no
line at all. Three readings were wrong, each hiding the next.

Its rules are two lines: `extends ShouldNotDepend implements Rule`, plus a `use` for an extractor, declaring
neither of the methods a rule needs. `RulePaths` walked a directory looking for a class that *declares*
`getNodeType()`, so it found none of them. It accepts a concrete class implementing PHPStan's `Rule` now,
resolved through the file's imports rather than matched on the short name.

The count that walk should reach is **all but five**, and getting there took two passes. Five files named
`*Rule.php` in phpat's `src` are the test DSL rather than rules — the interfaces `PHPat\Test\Rule` and
`PHPat\Test\Builder\Rule`, the attribute `PHPat\Test\Attributes\TestRule`, and `DeclarationRule` and
`RelationRule`, which implement phpat's own `Rule` and not PHPStan's. The import-resolved test excludes those
last two by name, and the other three are not concrete classes at all.

The version has to be named, because it moves: **0.11.10** has 64 such files and 59 rules, **0.12.0** has 66
and 61. Two sessions quoted 59 and 61 at each other as though one had to be wrong, and both were reading a
different install. A count belongs to its configuration, and this one had been written down without it.

That left two short on either version, and the gap was invisible until a peer session tested the claim
instead of reading it.
`HasOnlyOnePublicMethodRule` and `HasOnlyOnePublicMethodNamedRule` name PHPStan nowhere in their own files:
no `implements` clause, no `getNodeType()`. The interface arrives through `Assertion`, three levels up, which
`extends PHPStan\Rules\Rule`; the node type is declared in the extractor trait the class `use`s. Both tests
answered false and the two produced no line at all — the same silent zero, one package deeper. `RulePaths`
now asks `Hierarchy` whether anything in the hierarchy declares `getNodeType()`, in PHP's own order, and the
walk picks 59 on 0.11.10 and 61 on 0.12.0.

Behind that, both required methods were read off the rule's own class alone, so every one refused as though
it had no node type. They resolve through the hierarchy walker now — which the `findNodeType()` docblock
already claimed and did not do.

Behind *that*, the import map. A name in an inherited body resolves through the base's imports, not the
rule's, and reading it the other way is silent: a fixture's `instanceof Identifier` resolved to a class in
the rule's own namespace that exists nowhere, and the refusal blamed the member selector instead.

They now refuse inside their own bodies. Surveyed on 0.12.0: 46 of the 61 on
`extractNodeClassNames() is read as a producer but hands back nothing`, and 15 on `array_filter()` —
phpat's assertion engine, which is the real obstacle. The two that took the longest to reach refuse on
`array_filter()` with the others. Nothing emits, and that is not the point: a package installed and never
read is not a measurement.

#### The collision that could not fire, and now cannot

Reading that survey found something else. An output file is named for the rule's class short name, and so
are the manifest key and the linter's module. phpat names one class per namespace — `ShouldBeAbstract\
AbstractRule` and `ShouldNotBeAbstract\AbstractRule`, and 23 more pairs — so on 0.12.0, 25 output names are
claimed by 55 of the 61 rules. Every write would succeed and the last would win. The only visible trace was
the same name printed twice in a survey.

Nothing has ever been overwritten. The seven packages this repository installs collide zero times, and every
phpat rule refuses before emission, so the run that would have done the damage never reached a write. That
is luck rather than design, and the artefact at risk is the manifest the corpus differential reads: a
finding credited to whichever rule sorted last is a wrong attribution, which is worse than a lost file
because it is one you would trust.

`Cli` refuses both rules of a colliding pair now, naming both paths, in survey mode as well as emitting —
a survey that counts a rule the emitting run refuses is the disagreement the target banner exists to
prevent. The check runs *after* translating, not before: checking first was tried and buried 55 of phpat's
61 refusals behind a collision none of them would have reached, throwing away the one thing a survey
produces — a guard that masks the diagnosis is its own silent zero, and it is the harder kind to see,
because the output looks like a refusal rather than like nothing. Renaming on collision was the other option and was rejected: it would make a rule's output name
depend on which siblings it was emitted beside. Mutation-checked, and the mutant prints the bug itself —
`EMIT NamedConstantRule` twice, `emitted: 2`, one file on disk.

#### A zero that came from measuring the wrong configuration

The boolean-condition family was the first thing to reach a corpus through a *ported* PHPStan helper rather
than a translated one, and its first two differential runs both reported **only-port 0**. Both were wrong.

An emitted plugin carries a container parameter as a constructor parameter at the package default, and the
differential constructed every plugin with no arguments. So the port ran at PHPStan's defaults against
projects that do not use them — hihaho at `checkUnionTypes: false` against a level-7 project where it is
true. That makes the port *over-silent*, and an over-silent port cannot produce a false positive. The zero
was structural.

With the consumer's own values the same corpus reads **agree 488, only-original 122, only-port 42**.

Two things about how that was found are worth more than the number. The first fix pointed the parameter
read at the differential's sandbox config, which does not exist yet when the worker is written; it failed,
fell back to package defaults, and the run reported figures *identical to the previous run*. Identical
figures were the only reason it was caught — a silent fallback and a flag that changes nothing look exactly
alike. A run now prints why it fell back, and immediately earned it by naming a consumer whose own config
includes a neon file missing from its vendor directory.

The second is that `dump-parameters --json` emits the whole container, including the process environment,
and on a real project that document does not decode: braces balance, the bytes are valid UTF-8, there are
no control characters, and `json_decode` still answers `Syntax error`. Six named booleans do not need the
other 90kB to parse, so they are read by name from the text.

#### Where a faithful port and a real engine still disagree

The 42 are not a translation defect, and the difference between saying so and proving it is a control.

Read from mago's own output at one site rather than inferred from the totals: `! app()->environment('testing',
'local')` is `bool|string` to mago and `bool` to PHPStan, which carries the framework extension that types
that method by argument count. The port reports a union that is genuinely not a boolean; the two engines
disagree about the type, not about the rule.

The first control was a corpus without those stubs: Shopware, Symfony rather than Laravel, 9199 files,
reading **agree 2671, only-original 441, only-port 15** — 0.56% against hihaho's 7.9%. That comparison is
**confounded and does not support the conclusion**, which a peer session caught. hihaho runs level 7 and
Shopware level 8, so the two corpora differ in `checkNullables` as well as in framework, and nullable
shapes are the dominant condition type on hihaho: of 273 non-boolean conditions, `bool|null` is the largest
bucket and around 79 are `SomeModel|null`. At level 7 PHPStan strips null and stays silent; at level 8 it
reports those itself. The gap could have been either cause.

The experiment that separates them varies one thing: the same corpus, the same code, `checkNullables`
forced true on **both** sides. That needed a `--parameter=` override, because changing one side alone
measures the port against a configuration the original is not running.

    hihaho, checkNullables false -> true
    agree 488 -> 622    only-original 122 -> 138    only-port 42 -> 42

The flag moves agreements and under-reports and does not touch the false positives. Nullability is not the
driver.

What the 42 are was then read off mago's own output at every one of them rather than sampled: **33 are
`bool|string` and 9 are `bool|int|float|string|array|null`**. Two shapes, 42 of 42, both Laravel accessors
whose declared union PHPStan narrows through a larastan extension and mago takes at face value. The peer's
count of five `environment()` calls in conditions was right and bounded the wrong thing — the mechanism is
the class of extension-narrowed accessor, not that one method.

#### A trait method is one declaration to mago and one finding per using class to PHPStan

Thirty-two emitted plugins register `NodeKind::Method`. Every one of them disagrees with PHPStan on a
method declared in a trait, and nothing in the suite says so, because no example pair holds one.

Measured on one file holding a class, an abstract class with a concrete and an abstract method, an
interface, an enum, and a trait used by two classes. Both engines were asked the same question — for every
method, which class encloses it — by a rule and a plugin written for that alone.

    PHPStan            mago
    PlainClass::inClass                     PlainClass::inClass
    AbstractClass::inAbstract               AbstractClass::inAbstract
    AbstractClass::abstractMethod           AbstractClass::abstractMethod
    AnInterface::inInterface                AnInterface::inInterface
    AnEnum::inEnum                          AnEnum::inEnum
    UsesTheTrait::inTrait                   ATrait::inTrait
    AlsoUsesIt::inTrait

Five of the six agree, including the abstract declaration with no body. The trait is the whole difference:
PHPStan visits the method once per using class and answers `getClassReflection()` with the *using* class;
mago's member hook fires once at the declaration and the enclosing class is the trait.

So a plugin on that hook under-reports by one finding per extra user, over-reports for a trait nobody uses,
and answers a question about the wrong class wherever the rule gates on the enclosing one — "is this a
`TestCase`" is asked of the trait.

A control separates the node from the hook. `InClassMethodNode` is the virtual node four refused rules
register for, and it was the suspect. A plain `Stmt\ClassMethod` rule — the node type this transpiler
*already* maps to that hook — fires **identically**: twice for the trait method, never for the trait. The
divergence is the hook, not the node, and it is already shipped rather than waiting on a new row.

Counted rather than described: of the emitted PHP plugins, **32 register `NodeKind::Method`** — 18 from the
rule packages and 14 fixtures. Not all reach it through `ClassMethod`; `FunctionLike` and the `Expr` family
register it alongside other kinds. Every one of them is on the hook this measures.

That also sizes the `InClassMethodNode` cluster honestly. The missing row is not what stops those four:
mapping it to the member hook would be exactly as faithful as `ClassMethod` is, which is to say faithful
everywhere except traits. Two of the four hand every finding to an injected helper and are out on their own
terms; the other two are reflection and subtree work, not a table row.

The ceiling, on real code: **35%**.

Both probes were pointed at `laravel/framework`'s `Illuminate/Database/Eloquent` — 109 files, 30 traits —
and each logged one line per firing.

    mago      1395 firings    484 named by a trait   (34.7%)
    PHPStan   1374 firings      0 named by a trait

PHPStan attributes 442 of them to `Illuminate\Database\Eloquent\Model`, the class that uses the traits.
Mago names the trait.

The totals are within 1.5% of each other, and on this tree **no method fires twice on either side**. So what
this measures is attribution, not count: every trait here is used by one analysed class, so the missing
findings a widely-used trait would cause do not appear. A trait used by two analysed classes is the case
that costs findings, and this tree does not hold one.

Attribution alone is enough to matter. A rule that gates on the enclosing class — "does this extend
`AbstractController`", "is this a data fixture" — is asked about the trait, which extends nothing and
implements nothing, so the guard declines and the rule goes silent inside every trait.

Counted in the emitted plugins rather than estimated: **7 of the 18** corpus rules on this hook read the
enclosing class — `NoDoubleConsecutiveTestMockRule`, `NoGetInCommandRule`, `NoGetDoctrineInControllerRule`,
`NoGetInControllerRule`, `NoOnlyNullReturnInRefactorRule`, `NoRouteTrailingSlashPathRule` and
`NoRepositoryCallInDataFixtureRule`. Those seven are silent in a trait whose using class the guard would
have accepted.

##### What closing it would take, and what it would cost

Two things had to be measured before the fix could be designed, and a peer session named both while leaving
both open. They are answered here.

**The position matches.** PHPStan reports a trait-method finding at the *trait's own* declaration line, not
at the using class:

    AT TraitDivergence\PlainClass::inClass           Subjects.php:15
    AT TraitDivergence\AnEnum::inEnum                Subjects.php:35
    AT TraitDivergence\AlsoUsesIt::inTrait           Subjects.php (in context of class ...\AlsoUsesIt):41
    AT TraitDivergence\UsesTheTrait::inTrait         Subjects.php (in context of class ...\UsesTheTrait):41

Both trait findings land on line 41, which is where mago already reports. The file carries an
`(in context of class X)` annotation and the line does not move. So a plugin that reported once per using
class *at the declaration* would agree on position, and the only remaining difference is the count and that
annotation — which matters, because the alternative was a systematic position divergence on every trait
finding.

**The index is cheaper than the host that would build it.** Mago has no reverse index — `$children` is null
for a trait — but `getClassLikeNames()` with `getMultipleClasses()` reading `usedTraits` builds one. Measured
on Shopware's `src`, 6023 files and 6686 class-likes: `getClassLikeNames()` 5.5 ms, the whole index 144 ms,
finding 8578 trait-use edges over 60 distinct traits.

Against a run rather than against nothing, three runs each, spread under 0.05 s and the machine
uncontended:

    plain, no extension host    0.63 s wall   1.98 s CPU
    host that does nothing      0.75 s wall   2.48 s CPU
    host that builds the index  0.84 s wall   2.58 s CPU

The index adds 0.09 s wall and 0.10 s CPU. Starting the host it runs in costs more than that — 0.12 s wall
and 0.50 s CPU — so on this corpus the reverse index is not what a consumer would notice.

**What is not expressible.** "Which class is this method analysed in" has no answer in mago, and a peer
probe is what settled it: the body is analysed once, at the declaration, so there is no per-using-class visit
and no such class to name. That is a model difference rather than a missing accessor. The fix therefore has
to be "evaluate the rule's class guard once per using class and report once per user that fails", not "ask
which class we are in" — and for the seven rules that read the enclosing class, that guard reads class
metadata, which the index provides.

##### Half of it closed, and which half

`Declares::enclosingClassIs()` now falls through to the trait's users: when the enclosing class-like is a
trait, the guard is asked of each class that uses it and answers true if any satisfies it. The index is the
one measured above, built lazily, so a run whose rules never reach a trait never pays for it.

Counted on the controller fixture, both engines run for real:

    before   PHPStan 3   port 1   the port silent in the trait
    after    PHPStan 3   port 2   the port reports the trait route once

So the rule no longer goes quiet. What is left is multiplicity: PHPStan reports the trait route once per
using controller and the port reports it once. That is under-reporting, the safe direction, and it cannot be
closed from here — answering the guard differently cannot produce a second report. It needs the emitted body
to loop over the users, which is a code-generation change.

**"The common case is one user" is false, and I published it before measuring it.** The distribution, from
the same index on two real trees:

    Shopware src        6686 class-likes   60 traits used    9 with one user   51 with two or more
    laravel/Illuminate  2287 class-likes  153 traits used   65 with one user   88 with two or more

Shopware's tail is long: one trait has 1185 users, four more are above 1000, and 113 methods sit in
multi-user traits. Laravel has 933 such methods and a trait with 270 users. So the exact case is the
minority on both — 15% on Shopware, 42% on Laravel — and the remaining gap is the majority of trait methods
rather than an edge.

That inverts the sizing and raises a question the number does not answer. Agreement here means emitting one
finding *per using class at one span* — 1185 identical lines for a violation in Shopware's most-used trait,
because that is what PHPStan does. Closing the gap and producing usable output may not be the same goal, and
whoever builds the per-user loop should decide that first rather than discover it at the end. The
measurement says the work is worth doing; it does not say the destination is right.

Two things this cost, both worth recording. The first version indexed by the key `getMultipleClasses()`
returns rather than by `$metadata->name`; the key is an int, `strcasecmp()` refused it, and the *whole
worker* failed — so the plugin reported nothing at all, including the class-declared route it had always
caught. A change meant to close a gap made the rule strictly worse, and the count assertion is what said so
in one line. The second is that the same call answers null for a name the codebase lists and cannot resolve,
which is ordinary on a real tree.

##### Where the using class goes, priced

PHPStan carries the distinguishing context in the *file* field — `Subjects.php (in context of class X):41` —
and a plugin cannot set that. So a port reporting N times at one span has to put the class somewhere else or
emit N identical lines. Three places, and each was measured rather than argued.

`CorpusDifferential::compare()` compares the message text at **every agreeing site**, not only where a site
disagrees. So message text is free for *agreement* and is not free for `differingMessages`: naming the class
in the message puts every trait finding into that diagnostic permanently, because PHPStan's copy of the same
text lives in a field the port cannot write. This corrects a reading recorded here as "costs no agreement",
which was true and incomplete — a peer session caught it.

The annotation label is a third place. Measured on two reports at one span:

    compact listing   src/One.php:4:19: error[...]: same message      <- twice, identical
    JSON              message "same message"
                      annotations[0].message "in context of class Dup\UserOne"
                                             "in context of class Dup\UserTwo"

So the annotation distinguishes the two findings in JSON and costs nothing in the comparison — and
`mago analyze`'s compact listing prints the message only, so it buys the human reader nothing in the output
they actually look at. The differential already reads `annotations[0].span` for the file and line, so the
label is there for it to use if it ever should.

    identical messages     differingMessages clean, reader sees N identical lines
    class in the message   reader can tell them apart, differingMessages carries every trait site forever
    class in the annotation differingMessages clean, distinguishable in JSON, invisible in the listing

The message is still the right place — an output nobody can diagnose is worse than a diagnostic with a known
pattern in it, and a filter is easier to add than context that was never emitted. But it is a priced choice:
whatever builds this owes `differingMessages` a filter on the day it ships, not on the day someone notices
that diagnostic is always full.

#### `spaze/phpstan-disallowed-calls`: 38 rules, and the answer is neither one cluster nor many

A peer session's status page walks `vendor/` rather than a curated list and found two rule packages the
census never covered. The larger is `spaze/phpstan-disallowed-calls`: 38 registered rules, none running.
Surveyed rather than estimated, and the reasons group three ways:

- **20 rules — one hook row each, for twenty different node types.** `Stmt\Echo_`, `Stmt\Goto_`,
  `Stmt\Global_`, `Expr\Eval_`, `Expr\Include_`, `Expr\Print_`, `Expr\Isset_`, `Expr\Match_` and so on,
  one rule apiece. Twenty rows is twenty rows; nothing about them shares a capability.
- **9 rules — `could not find the reported message`.** Every one is a shim whose whole body is
  `return $this->disallowedKeywordRuleErrors->get($node, $scope, 'if', $this->disallowedKeywords, ...)`.
- **3 rules — the same shape through a different builder.**

**The nine are the finding, and they change what the package is.** `DisallowedKeywordRuleErrors::get()`
loops over `$disallowedKeywords` — a list of value objects a *consumer* configures — and reports only where
one matches. At package defaults it reports nothing. The same holds for the calls and constants rules: this
package exists to let a project declare what it forbids, so its rules have no behaviour of their own.

**Two facts, and running them together is the mistake this file warns about.** The 38 refusals are honest
*translation* refusals: twenty want a hook row, nine cannot find a message to report. They are real and would
still be real if the package shipped defaults. What the survey adds is a *feasibility* fact sitting behind
them — translate all 38 and a default install still reports nothing, because the behaviour is the
consumer's configuration. Both are true and they are different denominators. This section first said "38
rules refusing is not a vocabulary backlog", which asserts the second by denying the first; a peer session
caught it.

So covering the package usefully means carrying a consumer's configuration into the emitted plugin, which is
the `--from-config` question, *and* the twenty rows are still twenty rows. Sizing it either as 38 rules of
missing capability or as nothing at all would be wrong.

`composer/pcre` is the other package the walk found: 2 registered rules, not surveyed here.

#### What sits behind statement iteration, sized by attempting it

`Members::statementsOf()` shipped and moved three rules past `no iteration mapped for ->stmts` in one step.
Three further capabilities were built on top of it, measured, and taken back out: they moved two refusals
deeper and emitted nothing.

**What they were, and that they are right.** Mago wraps twice — a body's children are `Statement` nodes
whose own child is the concrete kind, and a class-like's are `ClassLikeMember` the same way. Probed on a
closure and a class body together:

    Statement        -> ExpressionStatement | If | Foreach
    ClassLikeMember  -> ClassLikeConstant   | Method

So `$stmt instanceof Stmt\Expression` is a question about the *child*, and asking it of the wrapper is false
for every statement there is. `$stmt->expr` is two levels down for the same reason. Both are one table row
and one helper each, and both worked.

**Why they came out anyway.** The rules behind them are not close.
`TaggedIteratorOverRepeatedServiceCallRule` reaches
`RepeatedServiceAdderCallNameFinder::find()`, which searches a subtree for method calls, reads a string
argument out of each, inspects a one-item array literal, and then folds the names through
`array_count_values()` and reports only where a count reaches three. A count-by-key fold with a threshold is
not a row. `NoProtectedClassStmtRule` moved one row further and is registered nowhere, so it cannot move
coverage at all.

That leaves the ratio the decision turns on: three capabilities, two truer refusals, one of them on a rule
no package registers, and nothing emitted. `statementsOf()` earned its place by moving three rules at once;
these did not, and unexercised vocabulary stops describing what the tool does.

The table row is written down here so the next attempt starts with the probe rather than repeating it.

#### The second deprecation rule, sized by attempting it

`FetchingDeprecatedConstRule` emits; `CallWithDeprecatedIniOptionRule` does not, and the attempt was
reverted rather than left half-built. What it found is worth more than the code was.

**Its `try`/`catch` is not an obstacle.** The rule wraps `getFunction()` only to swallow
`FunctionNotFoundException`, and its own comment says why — "other rules will notify if the function is not
found". The catch returns `[]`, which is the bail an emitted binding already makes when its helper answers
null. A `try` holding one statement whose catches all `return []` needs no statement kind; it needs its body
translated. That is four lines and it worked.

**`PhpVersion::getVersionId()` is a trap, and the peer's note pointed straight at it.** Their check said to
read `PHPVersion::$id`, a public readonly int. The two engines do not encode a version the same way:

    PHPStan   getVersionId()   major * 10000 + minor * 100 + patch      8.3.0 -> 80300
    mago      fromParts()      (major << 16) | (minor << 8) | patch     8.3.0 -> 525056

A rule comparing against a table of PHPStan-shaped ids — this one holds twenty — would find every entry
smaller than the version and report every deprecated option whatever the project runs. Over-reporting, from
two numbers that look like the same kind of thing. Read out of `fromParts()`; `major()`, `minor()` and
`patch()` are the accessors that make the conversion exact.

**What still blocks it, after four capabilities were added and taken back out:** `$node->getArgs()[0]->value`
inside `$scope->getType(..)` refuses on the index, and `self::DEPRECATED_OPTIONS[$key]` is a string-to-*int*
map read by a key known only at analysis time — the vocabulary carries key *sets* and lists of strings, not
maps with values. Both are real features rather than rows.

So the package is 1 of 2, and the second rule is not one obstacle away. It was reverted for the reason the
arithmetic family was: vocabulary nothing exercises stops describing what the tool does.

#### The arithmetic family, built and then withdrawn

The six `OperandsInArithmetic*` rules were ported far enough to emit and then reverted. The machinery worked;
the evidence did not support shipping it.

What it took: an `instanceof` dispatch recogniser (php-parser has a class per operator, mago has one `Binary`
kind with the operator in a child, and `left`/`right` and `var`/`expr` are the same two child positions in
mago, so the whole prologue collapses to the bindings any one branch writes), targets narrowed to the kinds
the dispatch names rather than the six an `Expr` hook carries, and `isValidForArithmeticOperation` ported
against a table measured on real PHPStan first -- fourteen operand shapes at three flag settings, all 42
cells reproduced.

That table is worth keeping even though the port is not. A plain `string`, an `array` and an `object` operand
are **silent** in every configuration, because `$type->toNumber()` errors for them and the helper returns
early, deferring to what PHPStan core already reports on the same line. And `int|string` is silent where
`bool|int` reports: one error-producing member takes the whole union out through that early exit, while two
cleanly-converting non-numerics fall through to the criteria check. Implementing "not numeric, so report"
would have fired on every string division PHPStan passes over.

Two measurements stopped it.

**The compound-assignment half cannot be read.** At `$x /= $e`, mago records the *left* operand's own type
and the *right* operand's **coerced** type -- `bool` reads `int|float` under `/=` and `int` under `*=`. The
rule reports on both operands, so half of every compound assignment would be silently missed. The narrow
form of this claim took two sessions to reach: seven access routes were enumerated against the right operand
and one against the left, and only the union of the two answers the question. Neither "enumerate the access
routes" nor "enumerate the positions" alone would have caught it; the rule defines which positions matter.

**And on real code it found nothing.** Across Shopware's 9199 files and hihaho's 2926, the division rule
produced **zero agreements and four findings PHPStan does not make**. All four are the same shape:
`$criteria->getLimit() ? … / $criteria->getLimit()`, a repeated method call under a truthiness guard.
Measured, and it is not a mago defect either: mago narrows a repeated call only when the method is annotated
`@pure`, and refuses to otherwise, which is the sounder position of the two -- an unannotated method may
return something else the second time. Controlled to the annotation: the same method with `@pure` narrows to
`int`, without it stays `null|int`.

So the rule is arguably correct and its only real-code output is four findings that PHPStan declines for a
reason mago deliberately rejects. That is not enough to ship. It is reverted rather than kept behind a flag,
because unexercised vocabulary is how a table stops describing what the tool does.

#### And then closed, by giving mago the plugin

The section above proves what the 42 *are*. What it does not settle is whether they are a property of the
port, and they are not.

`--extension-host=` registers an extra analyzer extension on the mago side, so both engines can carry
comparable plugins. With a `MethodReturnTypeProvider` for `Application::environment()` -- fifteen lines, the
same extension point larastan uses, built by a peer session -- one binary difference and nothing else
changed:

    without the provider   agree 488   only-original 122   only-port 42
    with the provider      agree 487   only-original 123   only-port  9

Thirty-three of the port's false positives were the missing plugin. The peer predicted "about 9" before the
run and named the survivors as the `config()` shape it had deliberately not built, which is the strongest
single result in this file: a number stated in advance and landed on.

So "the port over-reports on framework code" was the wrong reading of a right measurement. The differential
was comparing PHPStan-with-larastan against mago-with-nothing, and an only-port rate measured across that
asymmetry describes the ecosystems rather than the translation. The same applies to Shopware and
`phpstan-symfony`, where **8 of 15** are `$container->getParameter()` -- enumerated, not sampled, after an
earlier sample of three had put all 15 in that bucket and been wrong.

The other 7 on Shopware are not the plugin gap, and they are two further mechanisms rather than one.

Two are implicit `mixed` to PHPStan, which `passesAsBoolean` passes by design, so mago is the more precise
of the two and the port reports a real violation the original declines -- an only-port finding where the
port is right.

The remaining **5 are benevolent unions**, and finding that took three wrong answers first. The rule does
run in the sandbox, the site anchors correctly, the consumer's baseline is silent, and a clean reproduction
of `string|false` *does* report -- so it was none of those. What separates the reporting case from the
silent one is a single pair of parentheses in PHPStan's own rendering:

    (string|false)   benevolent, from UploadedFile::getRealPath()   -> silent
     string|false    ordinary, from an @return                      -> reported

Controlled side by side in one file, same expression shape, same run. PHPStan filters a benevolent union's
failing members when `checkBenevolentUnionTypes` is false, keeps the `false` member because it *is* a
boolean, and says nothing. Mago cannot represent benevolence, so the port is strict there.

That corrects this file. Benevolent handling was accepted as never reaching `passesAsBoolean`, on a
measurement of zero benevolent operands **in condition position** -- and the position was wrong. The `!`
operand is where this family does most of its work: 224 of PHPStan's own 353 failures on the Shopware slice
are negations. A census of `if` conditions finds zero because the type there is already `bool`.

Third time a confident reading has come apart on the position rather than the mechanism, counting both
sessions. The standing check that survives all three: **enumerate the positions the rule reads**, because
the rule decides which positions matter, and a route enumerated against the wrong one is a confirmation of
nothing.

Corroborated from the other side, after the peer session's own count was corrected: a grep for these
accessors inside a condition had read `5`, because `[^)]*` stops at the first `)` and every real instance
of the idiom has a nested call before the accessor — `! app()->environment('production', 'local')` cannot
match it. Allowing nested parentheses gives 15 `environment(` and 19 `config(`, the same order as the 42
enumerated here and the same two shapes. The counts are line-based and overlap where one condition uses
both, so they corroborate rather than reconcile.

Two cheaper controls were tried first and neither settled anything. `package-boost-php` has the rule
package installed but is nine files, and neither side reported anything; agreement on nothing proves
nothing. And `rector-src` does not ship the package at all, which the differential refused rather than
comparing an empty set.

#### The guess that measurement rejected

Between those two runs, `never` looked like mago's answer where PHPStan says `ErrorType`, so passing on it
should have removed the false positives. It removed none of them, and turned two agreements into
under-reports. The inference came from two message mismatches rather than from the failing sites; the sites
it silenced were not the sites it was aimed at. Reverted.

Four conclusions from this one instrument were wrong on first pass — the zero, "the flags change nothing",
`never`, and the nine-file control. Each was corrected by running one more command rather than by thinking
harder about the last output. The one inference that survived came from pointing the tool at a single site
and reading what it said there.

#### And the same shape at home

Chasing that one exposed it in this repository. The census said it spoke for "the rule packages this
repository installs" and named four, while `composer.json` required seven:
`phpstan/phpstan-strict-rules`, `phpstan/phpstan-phpunit` and `phpstan/phpstan-deprecation-rules` ship 58
rules between them and it spoke for none. The denominator was the number someone remembered to list. All
seven are in it now — **43 of 171** — and the fires-gate, whose own comment says an emitted rule outside its
corpora is the silence it exists to remove, gained the one strict-rules rule that emits.

Adding it showed the gate built its rule list in *survey* mode, which assumes a hook exists, so three rules
that cannot run at all came back "emitted" and were asked for example pairs. Emit mode now, which is what
its docblock already claimed.

The new pair then found a defect on its first run. `IllegalConstructorMethodCallRule` writes
`->toLowerString() !== '__construct'` and the port was silent on `$subject->__CONSTRUCT()`. Every arm of
`nameEquals()` folds case except the one that mattered — `selectorIs()` compares a member selector as
written, deliberately — and the comment above the call claimed the helpers already folded. A folded
comparison against a selector goes through `nameIs()` over its text now.

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


#### The unwired-configuration cluster, sized by wiring it

Nine rules refuse on a constructor parameter the shipping package never wires, eight of them on nothing
else. That is the largest single-need cluster in the census that is not the withdrawn arithmetic family, so
the question is what supplying the wiring would actually unlock.

Measured rather than reasoned. `hihaho/phpstan-rules` was copied into a scratch tree, its six unregistered
rules were added to the copy's `extension.neon` as ordinary `services:` entries pointing at the parameter
paths the package already declares, and the survey was run over the copy before and after.

| | emitted | refused |
|:--|--:|--:|
| package as shipped | 8 | 12 |
| the same package with the six rules wired | **12** | 8 |

Four clear on the wiring alone — `PositionalFlagArgumentStaticCallRule`, `NoUnsafeRequestDataRule`,
`NoUnsafeRequestHelperRule` and `UnvalidatedFormRequestFieldRule`. The last of those also takes PHPStan's
`Parser` service, which the census could not see behind the configuration refusal and which turned out not
to block it.

Two do not, and both were already counted elsewhere:

- `PositionalFlagArgumentMethodCallRule` lands on `flagRecord()` **inside a loop** — the same refusal, at the
  same line 131, that already blocks its registered twin `PositionalFlagArgumentNullsafeMethodCallRule` and
  `CombinedMethodCallRule`. Predicted before the run by reading `DetectsPositionalFlagArgument`: both go
  through `agreedFlagSite()`, whose `foreach` holds the call, while `flagSiteForNew()` and
  `flagSiteForStaticCall()` call it directly — which is exactly why `PositionalFlagArgumentConstructorRule`
  already emits.
- `NoUnsafeRequestFacadeRule` lands on `expected a string literal` at line 40, a new obstacle the
  configuration refusal was hiding.

So the cluster is worth four rules, not eight. The census `needs:` line was a lower bound in both directions
here: it over-counted two rules whose real blocker sits behind it, and under-counted the `Parser` service
that turned out to be free.

The nine also do not divide the way "unregistered means unimportant" suggests. These are not dead twins: the
registered enforcement for three of them is `CombinedMethodCallRule` and `CombinedStaticCallRule`, and both
are themselves refused. A consumer who registers the standalone rules instead would get plugins where the
combined ones cannot be ported at all.

Where the wiring would come from is not the package. `PackageConfiguration` reads the rule package's own
neon on purpose, so a generated project stands alone, and for a rule the package registers nowhere there is
nothing there to read. The consumer's container is the only place the values exist — and
`resources/registered-rules.php` already runs inside it and already holds the constructed rule objects, so
the configured values are readable off the instances rather than parsed out of a neon. That is the same
"ask the container" argument `RegisteredRules` is built on.

Not built. It changes what a coverage figure counts — a rule that emits only under a consumer's
configuration is not the same outcome as one that emits from the package alone — and that denominator is a
decision rather than a measurement.

> **Superseded.** `039c5f0`, the next commit, built it: `Transpiler::takeConsumerValue()` reads the values
> off the project named by `--from-config`. See *"'Not built' was built the same day"* below.

#### A record that leaves the loop that produced it, and the two divergences the examples found

`CombinedMethodCallRule` and `PositionalFlagArgumentNullsafeMethodCallRule` emit. Coverage 59 of 169
portable to 61.

Both refused on the same line of `DetectsPositionalFlagArgument`: `agreedFlagSite()` assigns a produced
record inside a `foreach` and reads it after. A record is ordinarily a transpile-time map of field to
expression, folded into whatever consumes it — exact, shorter, and unable to leave the loop, because every
expression reads the item the emitted `foreach` binds. Each field is now a real local instead: declared
before the loop, assigned inside it, copied into the accumulator, read after. The counter reached the same
wall and answered it the same way.

Four things had to be true at once, and each one was a separate change:

- **The producer's `return null` bails rather than continuing.** Inside a caller's loop a helper's null
  ordinarily ends the iteration. Here the caller answers it with `return null` from the accumulator, which
  declines the rule — so `continue` would go on to the next class where PHPStan stops. Read from the
  caller's source by `nullRecordDeclines()` rather than assumed, because the other shape is legal and means
  the opposite.
- **The consumer's null branch comes back.** `$site === null ? null : build($site['x'])` drops its null
  branch for a folded record, because a navigation resolves to something wherever it is read. A
  materialised record can be absent — every field is null when the loop assigned nothing — so the branch is
  emitted again, and only for a materialised one.
- **The accumulator is declared before the loop from the producer's returned literal.** Inlining the
  producer at that point would resolve expressions over a loop item that is not bound yet; only the field
  *names* are needed, and they are written out in the source.
- **`$site = $record` copies rather than aliases.** Aliasing makes the two names one set of locals, and the
  agreement check then compares a value with itself and never holds.

**Two divergences, both found by an example rather than by reading.** Neither is in the fold.

The first is silence. `Support::objectClasses()` answered the empty list for a nullable receiver, because
the strict reading refuses any atomic that is not a named object and `?Widget` carries a null one beside
it. `TypeCombinator::removeNull(..)->getObjectClassReflections()` is one class to PHPStan and was nothing
here. The single-class rendering had stripped null since it was written; the list rendering beside it had
not, and no rule had iterated it on a nullable receiver until now. The plugin emitted, parsed, loaded, ran
and reported nothing — the failure the fires gate exists for, and the one no static check sees.

The second is an over-report, and it needed an example nobody had written. Mago models an intersection as
one atomic with the other members hanging off it, so `A&B` answered `A` and a rule asking each declarer of
a method about it saw one declarer. It could never find two disagreeing, so it reported where PHPStan
declines. `Type::__toString()` collapses intersections the same way, which was measured here months ago;
this is the same fact reached through a different door, and the earlier measurement did not stop it.

The example that found it is `GoodDisagreement.php`: a receiver typed `(DimmableOne&DimmableTwo)|null`
whose two interfaces name the flag parameter `enabled` and `active`. A single-class receiver runs the loop
once and proves nothing about the fold. Mutation-checked: making the agreement comparison compare a value
with itself makes that example report `active:` where PHPStan is silent.

**Byte-for-byte.** Both Rust targets are identical to the baseline across the four corpus packages and
`tests/Fixtures/Rules`, and every plugin that emitted before still emits the same bytes. The PHP target
gains three files — the two rules and the `FoldsARecordRule` fixture that was written to prove the refusal
and now proves the fold.

## The status page has no equivalent of any of this

The gate above, the emitted-output snapshots and the census all watch the same artefact: the PHP this tool
writes for mago to run. The tool also writes a page — `--status` emits HTML and markdown describing what a
project's installed rules do — and none of the three checks reaches it.

That is not a hypothesis. The page's copy button called `navigator.clipboard.writeText` unguarded.
`navigator.clipboard` is undefined outside a secure context, and the page is served from a local dev host
over plain http as often as not, so every click threw `Cannot read properties of undefined (reading
'writeText')`. It shipped, and a person opening the page with a console found it.

Each existing check would have passed it, for a different reason:

- **The per-rule gate** runs emitted plugins under mago. The page is not a plugin and mago never sees it.
- **The emitted-output snapshots** compare bytes the transpiler writes for a rule. The page is written by
  `StatusPage`, which no snapshot covers, and a byte-identical run across all three targets says nothing
  about it.
- **The census** records a verdict per rule. It has no opinion about a file that describes verdicts.

The unit tests around the page assert the counts agree between renderers, that reasons are present, and that
HTML is escaped. All of that was true while the button threw. What none of them execute is the page's
JavaScript in a browser, which is the only place that defect exists.

Worth stating rather than fixing quietly, because it is the third instance in a week of one shape: the tool
grows an artefact, and every check already here is aimed somewhere else.

- `run-tests` filters on `**.php`, `phpunit.xml`, `composer.json`, `composer.lock` and its own workflow file.
  A markdown-only commit runs nothing, and the absent run reads as a failure until someone opens the
  workflow. Anything committed and compared that is not one of those extensions sits outside the alarm.
- The census version block was committed, compared, and outside what the alarm watched — first silencing it,
  then firing it every night on the word `dev-main`, until `withoutVersions()` made it recorded rather than
  asserted.
- The page is the same thing again, and it had no check to sit outside of.

The distinction from `PhpBackend::checked()` is worth keeping straight, because the fix differs. That gap was
one artefact with the wrong property measured: the emitted PHP was watched throughout, and what was counted
was that a file appeared rather than that it contained PHP. Its lesson is *inspect the artefact, not the
count*. This one's lesson is *enumerate your artefacts* — a better assertion about `StatusPage` still leaves
nothing running the page's JavaScript.

The emitted-plugin discipline is three checks aimed at one artefact, and every artefact this tool grows after
that starts with zero.

The fires-gate exists because "it emitted" was not a result. "The page rendered" is the same claim held to a
lower standard, and nothing here holds it to any.

#### Reading a rule's configuration off the project that registered it

Nine rules refuse on a constructor parameter no package neon wires. Wiring them in a scratch copy measured
the ceiling at four; this is where those values come from, and it changes no coverage figure — the rules are
all in the `(the package registers it nowhere)` bucket, which sits beside the 169 rather than inside it.
**61 of 169 stays 61 of 169.** The gain is on `--from-config`, where the denominator is what the project
registered and one of these rules counts like any other.

The values could not come from the package: it names these rules nowhere, so there is nothing to read. They
could not come from a consuming project either, under the rule `PackageConfiguration` states — the package's
own neon is the source, so a generated plugin stands alone and two projects cannot generate two different
plugins from one rule and both call it the port. `--from-config` is the case that rule does not cover: the
project *is* the subject, and it is already the denominator.

So the source is the project's container, which `resources/registered-rules.php` already runs inside. It
holds the constructed rule objects, so the values are read off the instances rather than parsed out of a
neon — the same argument `RegisteredRules` is built on, one level further in. Interpolation, `includes:` and
conditional tags are settled by letting PHPStan do them.

Two shapes, and the second is why reading the object beats reading the config:

- A promoted parameter is held by a property of its own name, and reads back directly.
- A parameter that is *not* promoted leaves no property. `NoUnsafeRequestDataRule` takes `array
  $unsafeMethods` and keeps only `array_fill_keys(array_map(strtolower(...), $unsafeMethods), true)`. There
  is nothing to read the argument from and nothing to derive it from on this side either — but the container
  already ran the derivation, so the computed table reads back instead. The recipe is not carryable and the
  answer is.

**A defect the map found.** `Emitter::phpDefault()` rendered every array as a list. Every configured default
before this one came from a package's `parameters:` and was a list, so it had never been handed a keyed
array — and a lookup table rendered as `[true, true]` instead of `['vardump' => true, 'ray' => true]` is
valid PHP, loads, and answers false to every membership test it exists to answer. Mutation-checked: forcing
the list branch makes the emitted constructor carry `[true, true]` and the assertion fails on exactly that.

Verified against a fixture project that registers `ConfiguredByTheProjectRule` and configures it with both
shapes. Package-walk emission is byte-identical across all three targets, which is the point: the flag is
the only way in, and a run over paths has no project to ask.

##### And the same rule under the fires gate

The tests above prove the values are read and reach the emitted constructor. They do not prove the plugin
agrees with PHPStan at runtime, which is the standard everything else here is held to — "it emitted" is not
a result, and neither is "it carried the right default".

`ConfiguredByTheProjectRule` now runs through the gate like any corpus rule: emitted, loaded into a mago
worker, run over an example pair, and diffed against real PHPStan running the original. Two things had to
change for a rule with no package behind it.

- **The gate emits it against a project.** `FiresGate::FROM_PROJECT` names one, and the transpiler is
  pointed at that project's container for the length of the transpile — the same thing `--from-config` does,
  through the same field.
- **The plugin is given no arguments.** Everywhere else the gate hands both sides the same values, because a
  threshold set on one side only is not a comparison. Here the plugin's *defaults* are what the project
  supplied, and passing them again would test the gate's table rather than the defaults. It also cannot
  work: PHPStan takes the parameter a rule declares and the plugin takes the property, and a derived
  property has no parameter of its own. The two tables are read against each other statically instead, so a
  rule emitted against a project that nobody configured on the PHPStan side fails to type-check rather than
  passing quietly.

The bad example calls `dump()` — in the promoted list — and `vardump()`, which is only reachable through the
derived map. Mutation-checked at this level too: forcing `phpDefault()` down its list branch makes the gate
report a disagreement with PHPStan, not just a failed string assertion. That is the map defect caught where
it would actually have hurt.

One direction of the pair check was reading a different rule list from the data provider, so this rule was
covered by four gate cases and simultaneously reported as an example directory nothing emits. Both now read
`gatedRules()`.

#### The return metric agreed on a fixture and missed a fifth of a real corpus

Five rules refuse with `no aggregate mapped for the collector`, and the census records **no `needs:` under
any of them** — the refusal fires before the body is walked, so the grep-a-capability strategy cannot see
this cluster at all. It is the largest one left inside the denominator.

Probed first, with all four collectors mapped temporarily: `ReturnTypeCoverageRule`,
`PropertyTypeCoverageRule` and `DeclareCoverageRule` all **emit**, so nothing hides behind the aggregate
mapping. `ConstantTypeCoverageRule` needs a runtime metric that does not exist, and `NewOverSettersRule`'s
collector is not a percentage aggregate at all — per-class findings from collected data, a different shape.
So the ceiling is three, not five.

`AGGREGATES` states the bar: an entry is added when its differential passes. Taking `returns` through it
found two defects and then stopped the entry.

**Magic methods were skipped by the wrong list.** The port tested `MetadataFlags::MAGIC_METHOD`; the
original's filter is php-parser's `ClassMethod::isMagic()`, which is membership in a fixed list of
seventeen names. Measured on a fixture holding `__get()`: PHPStan counted 6 methods, the port 7, because
mago does not set that flag for it. The list is what the rule means, so the list is what the port now
carries — copied verbatim, so an upstream addition is a diff rather than a silent drift.

**And a filter beside it was dead.** A separate constructor skip could not be made to fail by mutation:
`__construct` is one of php-parser's seventeen names, so the list already excludes it. Removed rather than
kept as defence — a filter no mutation can break is a filter nobody can trust.

With both fixed the fixture agrees exactly: 6 possible, 2 typed, 33.3 %, and the same four reported
locations. The fixture earns its parts — a magic method, a constructor, a trait method, an abstract method,
an interface method, one typed and one untyped return.

**Then the corpus said otherwise.** On a 2950-file consumer, real PHPStan counts 18307 method declarations
and the port counts 14398 — **−3909, a 21 % under-count**, where the parameter metric on the same corpus is
+81 of 13791 and inside its stated ceiling.

The cause is traced, not guessed. `CollectorDataNormalizer::normalize()` sums every collected record with
no deduplication, and a trait method is collected once per using class — so the real total counts it once
per user. `TypeCoverage::returns()` walks declarations and dedups by declaration location. The parameter
metric does not have this problem because it does not share that iterator: `DeclaredParameters` builds a
trait-user index and a `timesCounted()` multiplicity for exactly this reason, which is the work `returns`
still needs.

So no `AGGREGATES` entry. The rule stays refused, which is what the docblock's rule is for: a fixture
differential is necessary and was never sufficient, and this is the second time a metric agreed on a fixture
and diverged on real code.

The instrument is in the repository rather than the result alone: `run-coverage-corpus.php` takes
`--metric=` and `CoverageCorpus` names each metric's runtime method and summary line in one table, so the
next metric is one row and one run. A metric with no stated bound reports and fails on any difference,
rather than being gated against a ceiling measured for a different measurement.

##### And the declare metric, which passed

`DeclareCoverageRule` emits. Coverage 61 of 169 portable to 62, and `tomasvotruba/type-coverage` reads 2 of
10 rather than 1.

Picked over the other two by shape rather than by looking cheaper. `DeclareCollector` returns one record per
analysed *file*; the return and property collectors return one per declaration, once per using class. The
divergence that stopped `returns` — a collector summed without deduplication against a port that walks
declarations once — has nothing to act on in a per-file question, and neither does the reflection-extension
lookup that bounds the parameter metric.

**Fixture:** four files, one covered — no `declare` at all, a `declare` that is not `strict_types`, and
`strict_types=0`. Both tools report 4 possible, 1 typed, 25.0 %, and the same three files. Mutation-checked:
matching `strict_types` rather than `strict_types=1` takes the port to 2 typed of 4 and the comparison fails
on `ExplicitlyOff.php`, which is the file that exists for it.

**Corpus:** two Laravel consumers, 2932 of 2932 files and 1895 of 1895, agreeing on the percentage as well
as the count. The second matters more than the first: the first project declares strict types everywhere, so
its 100 % exercises nothing on the typed side, and only the second — 25.2 % on both tools — shows the two
halves counted the same way. `ACCEPTED_DIVERGENCE['declares']` states a ceiling of zero, which is a
measurement rather than the absence of one.

Two instrument corrections came out of it. The differential compared totals only, so two runs could count
the same declarations and disagree about how many were typed and still pass; it compares the percentage now,
and prints how far apart the two are when they differ. And the summary line each rule prints is copied out
of that rule rather than inferred: three of the four follow one pattern and `declares` does not — it prints
"Strict declares coverage" where the shape predicts "Declare coverage" — and a wrong summary is not a wrong
number but no number, because the regex finds nothing and the run dies.

One rendering difference, stated rather than smoothed over. PHPStan reports line `-1` for a finding about a
file rather than a position in one; mago has no way to report without a span, so the port anchors on the
file's first node. The test compares messages and files and says why it leaves lines out.

##### The property metric, which failed for three separate reasons

Third and last of the mapped-aggregate candidates. It stays refused, and the useful part is that the three
reasons are different from each other and from the one that stopped `returns`.

**The corpus, first.** On a 1897-file consumer, real PHPStan counts 1443 property declarations at 93.3 %
typed and the port counts 3110 at 65.0 % — **+1667, and 28.3 percentage points apart**. The direction of the
percentage rules out the obvious explanation: promoted properties are almost always typed, so if the extra
were promoted the port's percentage would be higher, not lower.

That gap was recorded as untraced and has since been traced, by reading
`PropertyTypeDeclarationCollector` rather than by reasoning about the numbers. Two causes, and each moves a
different half:

- **The original counts `Property` *statements*, not properties.** `count($classLike->getProperties())` is
  the total, and `public $a, $b, $c;` is one statement declaring three names — one, to PHPStan, and three to
  a port reading metadata. That inflates the port's denominator.
- **A `@var` docblock counts as typed.** The collector skips a property when `isPropertyDocTyped()` answers
  yes, and the port reads only a declared type. That deflates the port's numerator.

Two guesses were tested and refuted before the reading settled it, and both are worth keeping because both
were plausible. Properties inherited from *unanalysed* code are not counted by the port — a control with a
base class in the resolvable-but-not-analysed set counts one property, the child's own, on both sides. And
the untyped mass is not `@property` docblocks: of the forty classes contributing the most untyped
properties, **none** sits in a file containing `@property`. They are ordinary untyped declarations, and the
port sees them because it counts names where the original counts statements.

**Then a control, which found two things the corpus number could not separate.** Three classes: a base with
an untyped property, a child with a typed one and a constructor-promoted one, and a second child with an
untyped one. PHPStan counts 3 possible and 1 typed. The port counts 4 and 2.

- **The extra is the promoted property.** `PropertyTypeDeclarationCollector` collects `Property` nodes and a
  promoted parameter is a `Param`, so the original never sees it.
- **Inheritance is not a cause.** The inherited property is counted once by both, so the multiplicity that
  stopped `returns` does not arise here. Worth stating because it was the first thing to suspect.

**And a third, which no count would have shown.** The port reported *nothing* on that fixture while
computing 50 % against a required 99. Probed per property: `PropertyMetadata::$location` is set for the
**promoted** property and null for every ordinary declaration — the opposite of what the code's own comment
said. So the metric anchors findings on exactly the properties the original does not count, and can never
anchor one on a property it should report. A percentage that fails and a rule that reports nothing is the
plausible-but-wrong shape, and only running it showed it.

The comment is corrected in place. The metric is left otherwise as written, but no longer for want of a
cause: it now needs four changes, and all four are named — count statements rather than names, read a `@var`
docblock as a type, skip a promoted parameter, and find an anchor for an ordinary declaration. The first
needs the syntax rather than the metadata, which is the same route `DeclaredParameters` takes for the
parameter metric.

So of the three candidates behind `no aggregate mapped for the collector`, one passed and two did not, for
unrelated reasons — a summed collector against a deduplicating port, and this. The cluster was worth one
rule, and the census could not have said so: it records no `needs:` for any of the five.

##### The return metric, brought from −21 % to +3 % by counting a trait once per user

Not mapped yet, and much closer. The obstacle named last time — the collector sums records with no
deduplication, so a trait's body counts once for every class that uses it — is implemented, and the corpus
delta moved from **−3909 of 18307** to **+561**, with a second consumer at **+55 of 8526**.

**A fixture agreed by accident first, which is the part worth keeping.** A trait used by two classes and a
trait used by nobody gave 3 against the real rule's 3 — while counting the wrong things in both directions,
because the unused trait's method supplied the one the shared trait's second user was missing. Deleting the
unused trait separated them: PHPStan stayed at 3 and the port dropped to 2. Two errors of the same size in
opposite directions is what a single total cannot show.

Three facts about mago's model decided the shape, all probed rather than assumed:

- A class's `methods` list holds **only its own**. A trait's methods are not listed on the classes that use
  it, and a parent's are not listed on a subclass — so a declaration is reached exactly once, at its own
  class-like, and the deduplication that used to sit here had nothing to do but cancel the multiplicity.
- `properties` is the **opposite**: a trait's property *is* listed on every class that uses it. The two
  members are not symmetric, and a shared iterator over "members" would be wrong for one of them.
- A class-like's `kind` says whether it is a trait, so the multiplier is a lookup in `TraitUsers::of()` —
  the index `DeclaredParameters` already builds.

**What is left is one thing, and it is pinned by a control.** `overridden-trait-method` — a class that uses
a trait and declares the same method itself — counts 1 to the real rule and 2 here: the class's own method
wins, so the trait's version is never analysed in that context. The multiplier asks how many classes use the
trait, not how many of them actually reach the declaration. `DeclaredParameters::timesCounted()` answers the
harder question through `reachedAs()`, following overrides, `insteadof` and aliases — and it does so by
walking the syntax rather than the metadata, which is the shape this metric will have to take too.

Four control projects now carry return-count expectations alongside their parameter ones, and a fifth pins
the divergence exactly rather than as "at least one". All five numbers were written before the run,
including the prediction that the override control would disagree.

The controls harness is metric-aware for the same reason the corpus one is, and both now read one shared
table of what each metric is called: the runtime method, and the summary line its rule prints.

###### And the override case, which fourteen of fifteen controls now agree on

The divergence the last note pinned is closed. A trait's method is counted for the classes that **reach**
it, not for the classes that use the trait: a class declaring the same method itself never has the trait's
version analysed in its context, and an alias means it reaches the declaration under a different name.

`DeclaredParameters` already answered that question, in a private helper. It is shared now rather than
copied — one implementation of "which name does this class reach this declaration site under", used by both
metrics, with the fifteen parameter controls standing as the check that moving it changed nothing.

No syntax walk was needed after all. The earlier note said this metric would have to walk the source the way
the parameter one does; it does not, because the reach question is asked of a *declaration site*, and the
metadata carries the site. The parameter metric walks syntax for a different reason — its collector's LSP
guard reads the method node's name — which is a reason this collector has none of.

**Every control now agrees except one, and that one is already known.** `conditionally-redeclared` — a class
declared twice in one file behind a version guard — counts 1 to the real rule and 0 here, the same
under-count `ACCEPTED_DIVERGENCE['parameters']` records as −7 on `nikic/php-parser`. Ten of the fifteen
control projects now carry return expectations beside their parameter ones, including
`reflection-extension`, which agrees: the lookup that bounds the parameter metric asks a question this
collector never asks.

**The corpus is still not zero.** +496 of 18307 on one consumer and +55 of 8526 on the other, down from
+561 and +55, and both over-counts. Fifteen controls do not explain it, so the next instrument is the
set-difference one — `run-coverage-setdiff.php` names *which* declarations two counters disagree about for
the parameter metric, by stripping every type so the real rule enumerates its own set. The same trick works
for return types and does not exist yet. Until it does, the cause is unknown and is written here as unknown.

###### `@method` is not a method, and the set difference is how that got named

+496 became +444, and the second consumer's +55 became +42. The percentages now agree on both.

The cause was found by asking *which*, not *how many*. Three explanations for a delta were refuted from
totals earlier in this file; this one took two steps and no guessing.

- **Bisect by directory.** `run-coverage-corpus.php --paths=` put +470 of the +496 in `app`, +26 in
  `database/factories`, and **zero** in `tests/Feature`. A directory with none is as useful as one with
  many: whatever this is, it is not something every PHP file has.
- **Then ask the port for its own set.** The factories directory was the small one, and the port reported 31
  declarations with no return type where the real rule reported none. Naming them took one run: `createMany`
  and `createManyQuietly`, twice per factory file, sixteen files.

They are `@method` lines on the class docblock. Laravel's factories carry two each. The collector visits
`ClassMethod` **nodes** and a docblock writes none, so the original never sees them; mago's codebase lists
them beside the written methods, so the port counted them. Skipping the names in `pseudoMethods` and
`staticPseudoMethods` closes it.

The parameter metric was never affected, and the control says so: it walks the syntax, where a docblock has
no function-like node at all. That is the second time today the two metrics diverged because one reads
metadata and the other reads source.

**Still +444 and +42, so still no entry.** The bisect points at `app` and the instrument that named this one
works on any directory, so the next cause is a run away rather than a design question.

###### An enum's three free methods, and where the last seven live

+444 became **−7** on one consumer and **+42 became zero** on the other. The over-count is closed; what is
left is an under-count of seven, and the gate fails on those by design.

The same two steps found it. Bisecting `app` by subdirectory put **+430 of the +444 in `app/Enums`** and
zero in `Models`, `DataObjects` and `Concerns` — 157 enums, and 430 is close to three per enum. The language
gives an enum `cases()`, and a backed one `from()` and `tryFrom()`; nobody writes them, so the collector has
no `ClassMethod` node to visit and the codebase lists them like any other method. PHP forbids declaring a
method under one of those names on an enum, so skipping them by name cannot skip a written one.

**The remaining seven are traced to a directory and to a mechanism, but not yet to a cause.** Six are in
one consumer's 42 top-level factory files and one is elsewhere in `app`. The interesting part is that
neither half of those 42 files diverges on its own: 21 files give 200 against 200, the other 21 give 175
against 175, and all 42 give 470 against 464. Analysing them together adds 95 declarations to the real
rule's count and 89 to this one — a trait used by classes across both halves, counted once per user, where
six of those users are not reached here.

So the cause is in the user set rather than in the counting: `TraitUsers::of()` or `reachedAs()` misses six
classes that PHPStan analyses the declaration in. That is a narrower question than any that has been asked
of this metric so far, and the leave-one-out bisect the corpus runner already supports is how to close it.

Two things this run establishes independently of that. The other consumer agrees **exactly**, on both the
count and the percentage, over 1897 files — the first time any metric other than `declares` has done that.
And the parameter metric is untouched by both of today's causes, because it walks the syntax: a docblock has
no function-like node, and neither does an enum's `cases()`.

###### A `@method` line takes no name away from a trait — and a shipped bound that does not hold

The return metric is at **−1** on one consumer and **exactly zero** on the other. The six that were left
are closed, and closing them turned up something more important about a rule that already ships.

**The six.** One trait, `FactoryTrait`, five methods, 34 users — and the per-method reach counts said
`createMany` and `createManyQuietly` reached 31 of the 34 while the other three methods reached all 34.
Three of those factories declare those two names as `@method` lines. The codebase resolves the name to the
documented declaration, so asking *where the name lands* answered "not the trait" and the class was not
counted. PHP disagrees: a docblock takes no name away from a trait, and PHPStan analyses the trait's body in
that class's context like any other user's. 3 classes × 2 methods = the 6.

Mutation-checked on a control holding a trait, a plain user and a documenting user: without the branch the
port counts 1 where the real rule counts 2, for **both** metrics. The control is in both suites, because
`reachedAs()` is shared and the case was found through the metric that does not ship.

**And the finding that matters more.** Running the *parameter* metric against a third consumer — one the
stated bound was never measured on — gives **−722 of 10164, or −7.1 %**. `ACCEPTED_DIVERGENCE['parameters']`
states a ceiling of +1.11 % and a floor of zero, quoting +81 of 13694 and +37 of 11428, and
`ParamTypeCoverageRule` is emitted carrying that sentence in its docblock. On this consumer the port
under-counts by seven percent, which the corpus gate is written to fail on.

Confirmed not to be today's change: the same run against the same consumer with `reachedAs()` reverted gives
the same −722. It is pre-existing and was simply never measured, because the bound was stated from two
consumers and this is a third.

That is the shape this file warns about in its own first section — a number quoted with its baseline is
auditable, and a number whose baseline is two projects says nothing about the third. The bound is not
wrong about the two it names. It is being read as a property of the rule, and it is a property of those
two corpora.

###### Chasing the −722: it is the LSP guard, exactly, and the shape alone does not reproduce it

The shipped parameter metric under-counts a third consumer by 722 of 10164. Localised, and the localisation
is exact rather than approximate.

- **By directory:** −721 of it in `app`, and inside that −386 in `app/Repositories`, −87 in `app/Commands`,
  −25 in `app/Http`, −24 in `app/Models`. Spread, not one construct.
- **By cause, in the directory with the most:** counting what `lockedByAncestor()` skips gives **386 on the
  nose**. The LSP guard accounts for the whole deficit there — turn it off and the port would count 999,
  which is what the real rule counts.
- **By declaration:** the 25 disputed parameter lines in one repository file belong to 14 methods, and the
  interface that class implements declares **all 14**. So the port's guard is doing what the rule's own
  words say — skip a method a parent or interface already declares — and the real rule is not skipping them.

**A control with the same layering agrees.** An interface extending an interface, an abstract base
implementing the outer one, a final class extending the base and implementing the inner one, methods on each:
both count 3. So the divergence is not the shape, and reading more source will not find it.

What is not yet known is why PHPStan's `getInterfaces()`/`hasMethod()` answers no for these classes when the
metadata answers yes. Two candidates, neither tested: the consumer's own configuration reaching the guard
through something the harness's `paths!` replacement changes, and an ancestry PHPStan resolves differently
from mago. Both are testable against that project and neither is testable from here.

**One instrument caveat found on the way, worth more than the hypothesis it killed.**
`run-coverage-setdiff.php` renames the class in its stripped copy so the copy does not collide with the
original. A renamed class still implements its interfaces, so the set it prints is trustworthy here — but
the same rename is why the tool cannot answer *why* the guard differs: it changes the very reflection the
guard consults. It names which declarations, and it is the wrong instrument for asking about ancestry.

The bound stays as written, because it is accurate about the two corpora it names. What is now recorded
beside it is that a third corpus breaks it in the other direction, and that the cause is the guard rather
than the counting.

#### An example pair that could not tell two readings apart

`RequireAttributeNameRule` emits and its pair passed, and the pair could not have failed for the thing it
most needed to check. The `phpstan-src-e7` session found it while probing the CST: `#[A] #[B]` is two
`AttributeList` nodes, not one list of two, so a rule counting attributes against a list would answer per
group — and every example here used a single-attribute group.

Two shapes added, and the second one matters more than the first.

- `#[Grouped('first'), Grouped('second')]` — one group, two attributes. Written on **one line** it proves
  nothing: findings are compared as `(file, line, message)`, so both readings collapse to a single finding
  and both tools answer 2. Written across **separate lines** the readings part company — a port treating a
  group as one attribute answers 2, and iterating the attributes inside it answers 3.
- Two groups on one declaration, which is the shape the peer's probe names.

Both tools answer **3**. The pair now distinguishes the readings, and the line-splitting is the part worth
remembering: an example can exercise a construct and still be blind to it, because the comparison is by line
and a construct written on one line has one line.

Nothing changed in the transpiler. The reading was already right; what was missing was any evidence of it.

##### The same blindness on a shipped rule, where splitting lines does not rescue it

`TraitRequiresInterfaceRule` emits, and its pair had the same hole — found by `phpstan-src-e7` auditing for
it after the attribute case, with a criterion worth keeping. Looking for emitted plugins that *report inside
a loop* gives four candidates and one of them is a red herring: `UppercaseConstantRule` loops over
`$node->consts` but **returns** from inside the loop, so it produces one finding per declaration however many
constants it walks. A search and an accumulation look identical from outside. The criterion that works is
`$errors[] = ...` inside a `foreach` — an accumulation — which leaves four emitting rules, of which this is
the live one.

It loops over its configured trait-to-interface pairs and adds a finding for each pair a class-like
violates. The gate configured **one** pair, so one violation was the most any example could produce — and a
port reporting once per class produces that too. The differential agreed either way.

**And the attribute fix does not transfer.** Those findings could be separated by writing the construct
across lines. These are all reported at the *class*, so they share a file and a line however the source is
laid out. The only shape that separates the two readings is the count at one span, which is now asserted:
two pairs configured, an example using both traits and implementing neither, and both tools reporting
exactly 2 on it.

The port was already right. What was missing was any example that could have caught it being wrong — which
is the first live instance of the multiplicity caveat recorded earlier here, rather than a hypothetical.

The general form, now that two rules have shown it: **an example pair proves nothing about a rule that
reports N times per node unless some example makes N greater than one** — and where the findings share a
span, the assertion has to be a count rather than a set.

###### And the condition for a collapse, which is narrower than "same line"

The other two accumulating rules were audited by `phpstan-src-e7` and neither needed what
`TraitRequiresInterfaceRule` needed. Reading the differential rather than reasoning about it says why.

`CorpusDifferential` groups findings **by identifier** before `bySite()` keys them on `file:line`. So the
overwrite that hides a missing finding needs **the same identifier and the same line**, not either alone.

- `NoRequiredOutsideClassRule` accumulates under one identifier and reports at each method's own line. Two
  offending methods are two lines by construction, and its bad example already held two — the accumulation
  was exercised without anyone arranging it.
- `PublicStaticDataProviderRule` reports *two* findings at one line when a provider is neither static nor
  public, which is the trait rule's shape exactly — except that the two carry different identifiers, so they
  land in different buckets and neither can hide the other.

The example still never held such a provider, so the port emitting only one of the two checks had never been
observed. It does emit both: with one added, both tools report 4 findings on that file at lines 51, 51, 56
and 61 — the repeated line being the provider that fails both tests.

So the audit ends with one rule fixed, one already covered by accident, and one covered by an added example
that was never going to fail. That is the right ratio to expect: the criterion finds candidates, and only
reading each one says which are real.

###### The property metric's over-count, pinned to a population and named

`phpstan-src-e7` asked for an apples-to-apples property count and could not get one — three filter attempts
in a row selected the wrong population, once selecting exactly the classes meant to be excluded. The corpus
harness already pins the population, so this is that number.

On one consumer's `app/Models` — 141 files, 142 class-likes — **PHPStan counts 132 property declarations and
the port counts 217**. The same run on a second consumer's models is 257 against 703.

Four measurements over that pinned population, which together say what the 85 is and what it is not:

| | |
|:--|--:|
| properties the port counts | 217 |
| of those, written in the class's own file | 134 |
| **not written there — a trait's or a parent's** | **83** |
| `magicProperties` on the same classes | 1508 |
| names in both lists | 1 |
| properties carrying a location | 0 |

**It is not the magic properties**, and that is worth stating because the ratio invites the opposite
conclusion: 1508 `@property` entries against 217 real ones is better than six to one, and the metric reads
**none** of them — the two lists overlap on a single name. A signal can be real, large, and attached to a
field nothing reads.

**It is the trait properties.** The 83 are `forceDeleting` from Laravel's `SoftDeletes`, and
`auditCustomNew`, `auditEvent`, `auditingDisabled` and their siblings from an auditing package's trait —
listed on every class that uses the trait, with the declaration in a vendor file the collector never visits.
That is the asymmetry recorded earlier, now sized: `methods` is own-only and `properties` is not, and here
that is 38 % of what the port counts.

**And the fourth number is the one that would have shipped a silent rule.** Not one of the 217 carries a
location, so in this population the metric computes 15.2 % against a required threshold and reports nothing
at all. The control that first showed this held three classes; this is 142.

###### `nameLocation` answers both questions, and a trait's properties are counted zero times

The property metric goes from **+1667** on one consumer to **−3**, and from +85 on a pinned model tree to
**exactly zero on both**. Still unmapped — an under-count of three or four is the gate's floor — but every
cause named earlier is closed and one of them was named wrongly.

**`PropertyMetadata` has two location fields and the port was reading the one that is never set.** Found by
`phpstan-src-e7` on a four-property fixture and confirmed here on the pinned population: `location` is null
for all 217, `nameLocation` is set for all 217. So the conclusion recorded earlier — that the metric could
anchor no finding and would ship silent — was right about the symptom and wrong about the cause. It was not
that the information is missing; it is in the other field.

**And the same field answers the over-count.** `nameLocation->file` is the class's own file for the 134
properties written there and the trait's file for the other 83. One comparison gives both the report span
and the own-versus-inherited test, with no second lookup.

**The trait rule is the opposite of the method one, which is why a shared iterator would have been wrong.**
`ReturnTypeDeclarationCollector` visits `ClassMethod` nodes, so a trait's method is visited once in every
using class's context. `PropertyTypeDeclarationCollector` visits `InClassNode` and takes
`count($classLike->getProperties())` off the class node — and a class node's property list never holds the
trait's. So a trait's methods are counted per user and its properties **zero times**. Two collectors in one
package, one shape apart. Counting properties per user, the way methods are counted, gave 5 against 3 on the
control that holds both.

**And `type` rather than `declaredType` for the typed half.** The original counts a property as typed when it
has a written type *or* a `@var` docblock. Probed on four properties before it was relied on: a bare property
answers no to both fields, a `@var`-only property answers no to `declaredType` and yes to `type`, and a
property with only a default answers no to both — so `type` is not picking up an inference from the default.

One regression, caught by the suite and worth recording. Replacing `$total += $times` with `++$total`
matched the first occurrence in the file, which is in `returns()`, not the one being edited. Four trait
controls failed immediately; without them a metric that had just been made exact would have gone back to
counting a trait once.

###### The property metric passes, and the typed half was three rules rather than one

`PropertyTypeCoverageRule` emits. Coverage 62 of 169 portable to **63**, and
`tomasvotruba/type-coverage` reads 3 of 10.

Both consumers agree **exactly**, on the count and on the percentage: 866 of 866 at 100 %, and 1443 of 1443
at 93.3 %. `ACCEPTED_DIVERGENCE['properties']` states a ceiling of zero and the emitted plugin carries it.

Four things had to hold together, and the last two were each found by a number that did not move the way it
should have.

- **A trait's properties are counted zero times**, unlike its methods. Two collectors in one package, one
  shape apart.
- **A promoted property is not counted at all** — and it is told apart by `MetadataFlags::PROMOTED_PROPERTY`,
  not by a non-null `location`. `location` looked like the promoted marker on every fixture and is set for an
  interface's own property declarations too, which PHP 8.4 allows: four of them in one file were the whole of
  a −4 corpus delta.
- **A declaration is taken where it is written**, which `nameLocation` answers and `location` does not.
- **And typed is three tests, not one.** Written with a type, *or* declared by a parent class, *or* a
  docblock mentioning `callable` or `resource`. The parent guard is the easy one to miss because it is a
  guard rather than a type test: leaving it out read 63 % against 100 % with the counts already exact. And
  `isPropertyDocTyped()` does not do what its name says — it is a substring test for the two types the
  original gives up on, so a `@var int` is **untyped** to it. Reading mago's `type`, which any `@var`
  populates, read 94.9 % against 93.3 %: closer than the truth and wrong in the other direction.

That last pair is the shape worth keeping. Two readings bracketed the right answer — one too strict at 63 %,
one too generous at 94.9 % — and neither is nearer being correct than the other. A number that is close is
not a number that is nearly right.

###### The return metric passes too, on a diamond nobody had drawn

`ReturnTypeCoverageRule` emits. Coverage 63 of 169 portable to **64**, and `tomasvotruba/type-coverage`
reads 4 of 10 — three of its four now carried by measurements rather than by argument.

Both consumers agree exactly, count and percentage: 18307 of 18307 and 8526 of 8526.

The last divergence was one declaration in 18307, and finding it took the instrument rather than the eye.
Every subdirectory of `app` agreed on its own while `app` as a whole was −1, which is the signature of an
interaction rather than a construct. Leave-one-out over `app/Concerns` closed it, then over that directory's
six files — and **three of the six individually made the delta zero**, which is what said the cause was a
combination.

`HasIframeLinkValidation` and `HasLinkValidation` both use `PrefixesUrlWithProtocol`, and one class uses
both. So it reaches the third trait through **two paths**, and PHPStan analyses that body once for each. The
walk that builds the trait-user index carried a visited set, so it counted the class once.

Reproduced before it was fixed, on a four-file control: one trait, two traits using it, one class using both
— the real rule counts 2 and the port counted 1, **for the parameter metric as well**. That is a divergence
in a rule that ships, found while chasing one in a rule that does not.

The fix counts paths rather than traits, which terminates because PHP forbids a circular `use`. It moved the
parameter metric on one consumer from +81 to +82, inside its stated ceiling, and the return metric from −1 to
zero.

One test had to change for a reason worth keeping. `test_an_aggregate_with_no_stated_divergence_carries_no_note`
asked about `returns` — and `returns` stopped being an unmeasured metric the day its differential passed. A
test whose subject can graduate out from under it quietly stops checking anything, so it now asks about
`constants`, the one metric with no runtime implementation at all.

###### What a second reader found that two corpora could not

Three findings from a Codex review of the aggregate work. Two were real and neither corpus contained the
input that would have shown them — which is the argument for a reader as well as a differential.

- **A grouped declaration counted twice.** `public $first, $second;` is one `Property` node to the collector
  and two entries in the metadata list, and the guards apply to the statement rather than to each name.
  Reproduced on a two-property control: the real rule counts 2 and this counted 3. Neither consumer holds a
  grouped declaration — measured, not assumed: 134 statements against 134 names on one and 465 against 465
  on the other — so the differential had nothing to catch it with.
- **An ordinary block comment read as a docblock.** PHPStan reads `getDocComment()`, which is a `Doc` node
  and never a `/* */`. A comment mentioning `callable` above an untyped property was typed here and missing
  there. The opening token is checked now.
- The third — that the documented-name fallback is unconditional — is real and is **not fixed**, because the
  fix that suggested itself is worse. See below.

**A control that is meant to disagree, and the fix that was tried and reverted.** A class that documents a
name two traits declare and picks one with `insteadof` counts 1 to the real rule and 2 here. The `@method`
line makes the codebase resolve the name to the docblock, so asking where the name lands says "not the
trait" for the winner as well as the loser, and the fallback rescues both.

Refusing the fallback wherever an adaptation block appears reads 0 against 1 — it takes the winner out too.
Both directions are wrong, and an over-count is the direction the gate treats as bounded rather than
blocking, so the over-count stays and is pinned exactly. Telling the two apart means reading the `insteadof`
winner out of the `TraitUseAdaptation` node, which is work rather than a condition.

Neither consumer contains the shape, so both metrics still read zero on both.

#### A consumer's larastan crashing our discovery, and where the fix belongs

`hihaho/hihaho@68d09f42` works around larastan 3.10.0: `LarastanStubFilesExtension` reads `LARAVEL_VERSION`
without a `defined()` guard, and larastan's own bootstrap is allowed to define it never — the boot that
would is guarded on a trait existing and throws nothing when no branch matches. The workaround is a
`bootstrapFiles` entry defining the constant from `Application::VERSION`, which needs no application.

**Applied verbatim it would break this repository.** There is no larastan here and no
`Illuminate\Foundation\Application` to read, so the file would fatal on class-not-found the moment PHPStan
loaded it — turning a clean run into a broken one to fix a problem this project does not have.

**Where it does apply is `--from-config`,** which runs the *consumer's* PHPStan. Traced rather than assumed:
`LarastanStubFilesExtension.php:25` in the installed 3.10.0 reads the constant bare, while
`BuilderHelper.php:80` guards it — so the commit's description is accurate, and one consumer here is on
3.10.0 and the other on 3.6.1.

Reproduced through this repository's own path, on a control that takes the workaround out of a real
consumer's configuration without touching it — a scratch config including theirs with `bootstrapFiles!: []`:

    PHPStan could not report its registered rules: Error: Undefined constant "Larastan\Larastan\LARAVEL_VERSION"

That message names the symptom and points at the wrong thing: a reader sees discovery failing to read their
rules. So the guard goes in `resources/registered-rules.php`, which already runs inside the consumer's
container bootstrap — with `class_exists()` on top of the `defined()` check, because most projects this is
pointed at are not Laravel.

After it, the control gets past the constant. It then fails further in on `Container::configPath()`, which
is the control's own artefact — a scratch project with no real application — not something this fix owes an
answer to. Both real consumers are unchanged: one discovers 442 rules as before, and the other fails the way
it already did, on a PHPStan that does not expose its container to bootstrap files at all. That second one
was checked against the unmodified file before it was called a regression.

#### The constant metric: three collectors in one package, three answers about a trait

`ConstantTypeCoverageRule` was the last `type-coverage` consumer still refused, on `no aggregate mapped for
the collector ConstantTypeDeclarationCollector`. It is mapped now, and the package reads 5 of 10.

The question every member collector has to answer is what a trait's members are worth, and the three in this
package answer it three different ways. `ReturnTypeDeclarationCollector` counts a trait's method once per
class that *reaches* it, so a class redeclaring the name takes it away. `PropertyTypeDeclarationCollector`
counts a trait's property **zero** times. This one counts a trait's constant once per using class **whether
or not the class redeclares it**. Nothing in the sources says so; each was a measurement.

The measurement that settled it is a trait with one constant used by one class that redeclares the same
constant. The real rule counts **2** — the trait's, analysed in that class's context, plus the class's own —
where the `reachedAs()` test the return metric needs reads 1. Beside it, a trait nobody uses counts **0**,
which is the half that stops "count each declaration once" from reaching the same total by cancelling two
errors. Both are controls, and putting the properties model in place (`$times = 0` for a trait) turns them
red at 7 against 9 and 1 against 2 — which is what says the counting is load-bearing rather than incidental.

An enum's cases are not constants the collector can see: they are `EnumCase` nodes and it visits `ClassConst`
ones. A fixture holding an enum with two cases and one constant counts 1, and that is what pinned it.

##### A grouped declaration, and the over-count the property metric still carries

The first whole-corpus run read **+1 of 715** on one consumer. Bisected by directory to one file, which
writes:

    private const string
        DYNAMIC_TEXT = 'Welcome {name}',
        STATIC_TEXT = 'Welcome to this video';

That is one `ClassConst` node to the collector and two entries in mago's metadata. `TypeCoverage::properties()`
collapses such a pair by scanning the source back to the previous `;` or brace — and the `}` inside
`'Welcome {name}'` reads as the end of a statement, so the pair counts twice. Blanking string literals before
that scan was written for the property metric and reverted, because an apostrophe in a comment opens a quote
that never closes and it cost 42 declarations across two consumers.

The tree answers it outright instead: `NodeKind::ClassLikeConstant` **is** the statement, so there is nothing
to infer from text. Probed before it was relied on — the node's span is `195..241` where the two names sit at
208 and 225. A control copies the consumer's shape and reads 1 to the real rule's 1; without the span map it
reads 2.

The same span is what a finding is anchored on, and that is a second thing it buys. The original reports
`$classConst->getLine()`, the line the `const` keyword is on, and a declaration written over three lines puts
its names two lines below. `AggregatesConstantCoverageTest` compares `line: message` against the real rule
under real PHPStan, through the plugin the transpiler actually emits, and the wrapped declaration is line 13
on both sides.

##### Where it was measured, and where it could not be

**Exact on both consumers it was measured against: 715 of 715 at 100.0 % typed, and 636 of 636 at 98.4 %.**
The percentage agreeing matters more than the count here — the second consumer has untyped constants, so the
typed half (a written type, or a constant a parent *class* already declares — `getParents()` is classes, not
interfaces) is exercised rather than assumed.

The second consumer is not the one the parameter and return bounds were measured on. That one cannot be
measured at all right now, on **any** metric: the after-analysis hook dies reading a protocol collection of
69332 entries against the SDK's limit of 65536. Which call hands it that collection was not traced — the
count shrinks by about the number of class-likes removed when a directory is excluded, which is consistent
with the class-like list and is not the same as having watched it. What *was* checked is that it is not this
change: the return metric fails identically on the unmodified tree, so it is the corpus growing past an SDK
limit. Naming it rather than quoting two consumers as though they were the two the other bounds name.

Also measured: a trait and its only user in one file. mago lists the trait's constant on the using class with
the trait's own declaring location, so comparing *files* says the class wrote it and the declaration counts
twice. Comparing *spans* says it was written in the trait. Neither consumer holds that shape, so it is a
control rather than a corpus finding — and removing the containment test turns that control red at 2 against 1
while both consumers stay exact, which is the whole reason it exists.

#### The package that transpiled nothing, and the collaborator shape that was in the way

`phpstan/phpstan-phpunit` read **0 of 13** — the only one of the seven at zero. Two of its rules emit now.

`NoMissingSpaceInClassAnnotationRule` and `NoMissingSpaceInMethodAnnotationRule` are the same rule at two
levels: gate on the class being a `TestCase`, take the declaration's docblock, and hand it to
`AnnotationHelper::processDocComment()`. That helper decides *and* builds the findings, which is the shape
the transpiler had no answer for — the class rule refused on "could not find the reported message", a
sentence about this transpiler's state rather than about the rule.

`COLLABORATOR_CALLS` gained a `kind` for it. `reports` means the call is not an answer: it is emitted where
the rule made it, and the identifier comes from `RuleErrorBuilder::identifier()` inside the collaborator
rather than from a table, because the message and the identifier are the two things a reader checks a port
against. Everything above the call is still the rule's own source — which is what keeps the two rules
different from each other, since the only thing that separates them is whose docblock is read.

##### Four things measured before anything was written

Each was a probe, and each could have killed the approach.

- **`TestCase` resolves without PHPUnit in mago's source paths.** `Support::enclosingClassIs()` answers
  `true` for a class writing `extends TestCase` in an analysed file, with no `includes` entry. Had it
  answered `false`, the guard would have failed closed, the rule would have reported nothing, and it would
  have looked like a clean project.
- **A docblock needs `FileAnalysisRequirement::SourceText`.** Without it `getTrivia()` returns an empty list
  and `Support::docblockText()` answers null for every declaration — silently. The emitter already puts
  `SourceText` on every node hook, so nothing had to change; the probe is what says so.
- **An ordinary `/* */` block comment is not a docblock on either side.** PHPStan reads `getDocComment()`,
  which is only ever a `Doc` node, and mago records the two as different trivia kinds. Both good examples
  hold a block comment with a bad annotation in it, and both engines stay silent.
- **The finding lands on the declaration, not on the annotation.** Real PHPStan over a fixture whose bad
  annotations sit on lines 8, 9, 14 and 15 reports on lines **11** and **17** — the `class` and `function`
  lines. Two bad annotations in one docblock are two findings on one line with different messages, which the
  gate compares as `line: message` and keeps both of.

Mapping `InClassMethodNode` to the same hook `ClassMethod` uses was the other half. Beside the rule it let
emit, it moved **three** still-refused rules off "no hook mapping for node type PHPStan\Node\InClassMethodNode"
onto the reflection accessor each of them actually needs — `WrongCaseOfInheritedMethodRule`,
`AttributeRequiresPhpVersionRule` and `ShouldCallParentMethodsRule`. Counted off the census diff rather than
off the four rules the old reason named, one of which is the rule that now emits.

##### The gate proves them and no corpus can

Both pairs pass the fires gate: real PHPStan reports on the bad example, the emitted plugin reports on it,
both are silent on the good one, and the two agree on line and message. Removing `covers` and `dataProvider`
from the ported list turns the comparison red and names the finding that vanishes, which is what says the
list is load-bearing rather than decorative.

**No corpus here exercises these rules, and the instrument says so itself rather than reporting a zero as
agreement.** Both consumers install `phpstan-phpunit` and both run 2932 and 1895 files through it:

    exercised: 0 of 1 identifiers; 1 reported nothing on either side, so this corpus says nothing about them

That is the correct output, not a gap in the run. Counted before the runs rather than after: hihaho writes
**0** docblock annotations from the thirteen names across 893 `TestCase` files, finconnect **1** across 197,
and mijntp's 566 `@uses` are outside its 3 `TestCase` files, which hold none of the thirteen names between
them. `phpstan-src` has 202 files using `#[DataProvider]`
and **0** using `@dataProvider` — the ecosystem has moved to attributes, so the shape these two rules exist
to catch is disappearing from real code. The positive half of the claim is the gate, on examples written for
it, and this file says so rather than quoting an agreeing zero.

#### One guard four rules open with, and a case fold that was being dropped

`AssertRuleHelper::isMethodOrStaticCallOnAssert()` is the first line of four `phpstan-phpunit` rules, and the
inliner could not take it: its body assigns a type in each branch of a decision tree rather than exiting from
a chain of guards. All four refused *inside a method none of them wrote*, which is a refusal that points at
the wrong file.

`COLLABORATOR_CALLS` now stands a runtime helper in for it, reached from the static-call side as well as the
method-call side and keyed on the fully qualified name either way. **One rule emits from that**:
`AssertSameNullExpectedRule`. The other three move onto the obstacle each of them actually has — a guard
body that is an expression, a second identifier, an argument list on an expression node — which is a better
refusal even where it is still one. `phpstan-phpunit` reads 3 of 13, and the seven-package total 68 of 169.

The ported question keeps the original's `->yes()`, which is the load-bearing word: for a union receiver
*every* member must be an `Assert`, so a nullable one is not. `Support::objectClasses()` already answers that
way — the empty list rather than a partial one as soon as an atomic is not a named object — which is why the
strict reading is used and never the `IgnoringNull` variant. `self`, `static` and `parent` all resolve to the
enclosing class, because the original's `parent` branch builds an `ObjectType` of
`$scope->getClassReflection()->getName()` rather than of the parent. A static call on an *expression*
(`$class::assertSame(..)`) is not answered: mago leaves `receiverType` null there, so the port is silent, and
the runtime docblock says so rather than leaving it to look measured.

##### The stub could not answer the question, and PHPStan said so first

`tests/Fixtures/examples/stubs/Framework.php` declared `abstract class TestCase` with no parent, where the
real `TestCase extends Assert`. Run against that, PHPStan reported `Call to an undefined method
BadAssertSame::assertSame()` and the rule could not fire at all — so a green pair would have proved nothing.
The stub gained an `Assert` with the four assertions the family names, and `TestCase` now extends it. That is
a shared file, so the whole gate was re-run after it: 424 tests, all passing.

The discriminating example is the good one. It holds a class that is **not** an `Assert` and declares an
`assertSame()` of its own; PHPStan is silent on `$other->assertSame(null, $value)` and a port that skips the
receiver question reports it. Making `isCallOnAssert()` return true unconditionally turns exactly that
example red, at exactly that line.

##### A shipped rule was silently dropping the fold the rule wrote

Translating `$x->name->toLowerString() === 'null'` emitted `Support::constantNameText($x) === 'null'`, a
case-sensitive comparison. The fold was already carried for a *member selector* — the comment there records
`IllegalConstructorMethodCallRule` being silent on `$subject->__CONSTRUCT()` — and the same defect was still
open one descriptor kind along, for anything that is already a string.

Found by the new rule and fixed for both: the emission diff over seven packages and three targets shows one
existing file changed, `NoOnlyNullReturnInRefactorRule`, whose source writes `->toLowerString() !== 'null'`.
It was missing a `refactor()` whose returns are written `NULL`. Its bad example now writes one, and
disabling the fold turns that pair red with "the plugin ran and found nothing" — the failure static checks
cannot see.

##### Nobody writes the thing this rule catches

Counted before drawing any conclusion from a run: `assertSame(null, …)` appears **0** times in hihaho,
finconnect, mijntp, phpstan-src and rector-src. That is not a gap in the corpora — it is what a rule
discouraging an idiom looks like once the idiom is gone. The gate is the evidence, and this file does not
quote an agreeing zero as if it were one.

#### The fixture agreed five times over, and the corpus said 0 of 9

`AvoidFeatureSetAttributeInRectorRule` needed one thing: `$ruleError = RuleErrorBuilder::…;
$ruleErrors[] = $ruleError;` — one append written in two statements, where the one-statement form was already
taken. With that arm the rule emitted, and the fires gate passed on the first try: PHPStan reports the bad
example, the plugin reports it, both silent on the good one, agreeing on line and message.

**Then the corpus differential over `rector-src` read `agree 0, only-original 9, only-port 0`.** The rule's
own home codebase, and the port found none of it.

That is the strongest instance in this file of "a green run over material you wrote is the weakest evidence
available". Five variations of the fixture were written trying to reproduce the miss and **all five still
agreed**: a same-class constant as the key, an untyped one, a constant held on another class, a call inside a
closure, and a call inside a closure passed as an argument. The gate would have shipped a rule whose only
real-code behaviour is silence.

##### One cause, controlled, and verified at the granularity it is published

Instrumented rather than reasoned about — a probe plugin over the real file printed what each step of the
emitted body returns. The class guard passed, the subtree search found the call, the argument was found, and
the *type* came back plain `string` where PHPStan has a constant string. The declaration is:

    /**
     * @var string
     */
    private const IS_BREAK_IN_SWITCH = 'is_break_in_switch';

A widening `@var` docblock on a class constant. PHPStan's `$scope->getType()` on a constant fetch reads the
initialiser and ignores it; mago's inferred type honours it. The control is two constants in one class, one
docblocked and one not: the docblocked one answers null and the bare one answers its literal.

And the population was counted rather than inferred. All **nine** only-original findings were enumerated —
five keys on `Rector\NodeTypeResolver\Node\AttributeKey` and four `self::` constants in four rules — and every
one of the nine carries `@var string`. `AttributeKey` docblocks every constant it declares, which is why the
rate was zero rather than partial.

##### Closed by reading the declaration, which a node hook can do after all

`Support::constantStringAt()` asks the inferred type first — it answers every shape this does not — and falls
back to the constant's own initialiser. The declaring file is found through the constant's metadata location
and read from disk: a node hook sees only its own file's *syntax*, which
`internal/probe-declaring-file-body.php` measured, but a plugin is PHP and the path is real. Tokenised rather
than matched, because an apostrophe in a trailing comment reads as an opening quote to a scan — the mistake
that cost the property metric 42 declarations when it was made there. Only a plain quoted string counts;
a concatenation or an escape answers null and the caller behaves as it did before.

Two probes were needed for the navigation, and the first reading was wrong both times. A `Foo::BAR` reached
through an argument arrives as the category node `Access`, whose only child is the `ClassConstantAccess` — a
kind test on the specific case answered "not a constant fetch" for every fetch there was, and the narrow
differential stayed at 0 until the node was descended into.

**After it: `agree 9, only-original 0, only-port 0` on all 2872 files of `rector-src`,** and that corpus's
whole run for this package went from `agree 25, only-original 9` to `agree 34, only-original 0`. The example
pair now carries a docblocked constant, and removing the fallback drops exactly that finding.

##### And the same fix on four rules that already shipped

The emission diff names them: `ReflectedMockedClassRule`, `ForbiddenArrayMethodCallRule`,
`NoLeadingBackslashInNameRule` and `RequireUniqueEnumConstantRule` each ask "is this a constant string, and
which one" of an argument or a value, and each was declining a class constant whose type a docblock had
widened. All four are gated, and the gate is green. On hihaho's 2932 files the run reads `agree 419,
only-original 3, only-port 0` — the direction that matters for a widening change is `only-port`, and it is
zero.

#### A shipped rule silent on every middleware pipeline, found by chasing a 3

The previous section's differential left one number unexplained: `hihaho` read `agree 419, only-original 3`
for `symplify/phpstan-rules`. Three findings on 2932 files is the size at which a delta is easy to leave
alone, and it was the whole of `NoDynamicNameRule` on one of its six targets.

Bisected to one file, `app/Http/Middleware/RedirectIfTermsNeedToBeAccepted.php`, and to one expression
written three times: `$next($request)`. A function call whose name is a plain variable — the shape a
middleware pipeline is made of, and the shape the rule exists to report.

Instrumented rather than reasoned about. `Support::isWrittenName()` answers **true** for it, so the rule's
`! $node->name instanceof Expr` guard inverted and the plugin returned before reporting. And the reason it
answers true is not a mistake in the list of written-name kinds — it is that the part alone cannot decide:

    Holder::$prop   namePart = Variable > DirectVariable   written    (a static property's own name)
    $next(1)        namePart = Variable > DirectVariable   dynamic    (a function call's name is an Expr)

Identical spellings, opposite answers. php-parser splits them by type — `VarLikeIdentifier` for the static
property, `Name` for a written function name — and mago does not. The parent node is what says which position
this is, and `Part` already carries the node and the source, so the correction needed no signature change and
changed no emitted byte.

The position test gates the descent rather than replacing it, because `Holder::$$n` is still computed in the
static-property position: it spells `Variable > NestedVariable`, which the kind list already rejects. All
eight shapes were probed in one file before and after — two static accesses, three member accesses, a written
method, a written function and a braced selector — and the first attempt at the fix got `$$n` wrong, which is
what the eight-shape probe caught.

**After it: `agree 422, only-original 0, only-port 0` on hihaho**, `agree 34, only-original 0, only-port 0` on
rector-src. The example pair's own docblock said it covered "five of its six targets"; the sixth is in it now,
and removing the position test drops exactly that finding from the comparison.

The lesson is the one above it, from the other direction. That pair had been extended once before — for
`\`-prefixed function names, after the rule reported 169 sites on `nikic/php-parser` — and still had no
variable call in it. A gate is only as wide as the shapes someone thought to write down, which is why the
corpus differential is run per identifier and why a 3 is worth bisecting.

#### Sweeping the rest of the emitted rules, and the one family that still disagrees

The two sections above each came out of a per-identifier corpus run, so the run was extended to every package
a corpus installs. Two of the four came back with nothing left to say:

- `symplify/phpstan-rules` on hihaho and rector-src — exact, after the two fixes above.
- `phpstan/phpstan-phpunit` and `phpstan/phpstan-deprecation-rules` on rector-src — `exercised: 0 of 3`. The
  instrument says so itself rather than reporting three agreeing zeros.

`phpstan-src` cannot be used as a corpus at all: it has no `vendor/bin/phpstan`, because it *is* PHPStan. The
differential refuses rather than comparing against a binary that is not there, which is the right answer and
worth writing down before someone else reaches for the obvious corpus.

That leaves one family, and it is the one already documented: the three boolean-condition rules of
`phpstan-strict-rules`. On hihaho's 2932 files, at the consumer's own level 7:

| identifier | agree | only-original | only-port |
|:--|--:|--:|--:|
| `booleanNot.exprNotBoolean` | 309 | 75 | 16 |
| `if.condNotBoolean` | 175 | 47 | 26 |
| `ternary.condNotBoolean` | 81 | 29 | 2 |

The `only-port` side is the one this file already priced, on Shopware, and traced to the ecosystem asymmetry:
PHPStan reaches a framework through larastan or `phpstan-symfony` and mago reaches it through nothing, so 33
of 42 disappeared the moment a comparable plugin was given to mago. **The `only-original` side had a number
and no cause.** It has two now.

##### The recorded mixed divergence, confirmed at a named site

`config/sentry.php` is the smallest isolated case — one finding, no agreements, one file. The condition is
`env('SENTRY_RELEASE') ?? file_exists(base_path('VERSION.txt'))`, and the port computes `mixed` for it and
passes. `BooleanRuleHelper` opens with `if ($type instanceof MixedType) return ! $type->isExplicitMixed();`,
and `env()` declares `mixed`, so PHPStan calls it explicit and reports.

That is exactly what `RuleLevel`'s docblock already states, and re-checked against the pinned SDK rather than
taken from the note: `Mago\Sdk\Analyzer\Type\MixedType` carries `issetFromLoop`, `nonNull`, `empty` and a
`truthiness`, and nothing that separates written `mixed` from inferred. The population is **1292** `mixed`
conditions in `app/` alone, so most of it is the implicit kind that PHPStan passes too.

##### And a second cause, which had no name

**442 of the conditions these rules read carry no inferred type at all.** `passesAsBoolean` is handed null and
passes, so the rule is silent. 441 of the 442 are a call — `$response->successful()`, `config('vapor.active')`,
`$token->expires_at?->isPast()` — and one is `$element instanceof Component` against a class mago cannot
resolve. That one matters: "every one of them is a call" would have been wrong at 1 in 442, which is the
granularity this file keeps being taught to check.

It is not a requirement the plugin forgot to ask for. Declaring all four type requirements at once —
`ExpressionTypes`, `TargetExpressionTypes`, `ReceiverType`, `ArgumentTypes` — left the count identical.

Reading the callee's *declared* return type instead is the obvious fallback and it does not price out. A probe
answers `none` for `$response->successful()`, whose signature says `bool`: the receiver of a chained call is
untyped for the same reason the call is, so the lookup has nothing to start from. Stated as an attempted
pricing rather than a conclusion about the fallback, because the probe's own navigation is a candidate
explanation for the `none`.

Both causes are silence, which is the safe direction, and both populations are far larger than the
disagreement they produce: 1292 `mixed` and 442 untyped conditions in one directory against 151
`only-original` over the whole corpus. Counted at flags all-false, which is what makes those two rows
comparable — the nullable rows move with the level and are not quoted here.

#### A branch that reported and did not say so

`AssertSameBooleanExpectedRule` is `AssertSameNullExpectedRule` with two branches, each carrying its own
message *and* its own identifier — `phpunit.assertTrue` and `phpunit.assertFalse`. It refused on "a second
identifier before the first was reported", and that sentence was false: the first branch had reported, two
lines above the refusal.

The guard is right and the bookkeeping was one arm short. `takeMessage()` refuses a second identifier only
when the first was never reported under, because then the second would be an overwrite nobody sees. Three
paths set `reportTaken` to say a report has been emitted; the fourth — a `return [RuleErrorBuilder…]` inside
an `if`, reported inline because the trailing report would run whichever way the branch went — emitted the
report and never set it. The `$errors[] = RuleErrorBuilder…` arm beside it already did.

`phpstan-phpunit` reads **4 of 13** now, and the seven-package total 70 of 169. The emission diff over seven
packages and three targets names one new file and no changed one, so no rule that already shipped is affected
by the correction.

##### One branch is not evidence for two

The example pair reports under both identifiers, and that is the point of it: a port that took the last
identifier for both branches would pass a one-branch pair unchanged. Confirmed against real PHPStan on the bad
example rather than inferred from the emission — three findings, lines 17, 18 and 21, two distinct identifiers
and two distinct messages, the third being `TRUE` in the other case, which the rule folds.

No corpus exercises it: `assertSame(true, …)` and `assertSame(false, …)` appear **0** times across hihaho,
finconnect, rector-src and mijntp — the same answer the `null` sibling got, and for the same reason. The gate
is the evidence, and this file does not quote an agreeing zero as if it were one.

#### Five small pieces, two Symfony rules, and a dead branch that had been shipping

`NoClassLevelRouteRule` and `RequireInvokableControllerRule` both reach `SymfonyControllerAnalyzer`, and both
refused inside it rather than on anything they wrote themselves. Five additions between them, each general
rather than rule-shaped, and each found by re-running the transpiler and reading the next refusal:

1. **A narrowing that cannot hold.** `hasRouteAnnotationOrAttribute()` takes `ClassLike|ClassMethod` and opens
   with `$node instanceof ClassMethod && ! $node->isPublic()`. The mirror fold already existed for the method
   caller — "the caller passed a method declaration, so this holds by construction" — and the class-like
   caller had none, so it was refused on a visibility question about a declaration that has no visibility.
2. **Short-circuiting at translation time.** A left operand that cannot hold makes the right one unreachable,
   but `combine()` folds only the *identity* operand and runs after both sides are translated. So the fold
   above was not enough on its own: the `isPublic()` still had to be translated to be thrown away.
3. **A collaborator built rather than injected.** `$attributeFinder = new AttributeFinder();` is the same
   handle as a constructor-injected one, one line later instead of one constructor away. Recorded under the
   short name, which is what an injected collaborator is already recorded under.
4. **`AttributeFinder::hasAttribute()` mapped rather than inlined.** It walks `attrGroups` two levels to reach
   each name, which is exactly the shape the `->attrGroups` mapping refuses to fake — metadata carries the
   names flattened and resolved, and answering `->attrs` and `->name` from that list would be three mappings
   pretending the tree has a shape it does not. The question maps exactly instead.
5. **A class constant in value position.** `SymfonyClass::ROUTE_ATTRIBUTE` — a package keeping the names it
   matches on in one holder class. `resolveClassConstant()` already found such constants for a message or a
   comparison; the only position without a reading was the one a mapped collaborator's arguments go through.

`symplify/phpstan-rules` reads **42 of 89**, and the seven-package total 72 of 169.

##### The dead branch was already in the plugins

The guard-chain assembly emitted a constantly-false guard as `false ? false : …` rather than dropping it, and
the emission diff over seven packages and three targets shows that shipping in **eight** files — six PHP and
two Rust, including `NoMockOnlyTestRule`, `NoRouteTrailingSlashPathRule` and `NoEloquentWithPropertyRule`.
Every one of the eight diffs is the same removal and nothing else, so the change is readability with identical
semantics; it is named here because a reader comparing two versions of a shipped plugin should not have to
work that out.

Two refusals also became more specific rather than disappearing: `NoIntegerRefactorReturnRule` moves off the
`new` onto the statement after it, and `RectorCheaperGuardsFirstRule` from "access path outside the
vocabulary: self::ABSTRACT_RECTOR_CLASS" to "is not a string constant of this rule" — which is accurate, since
that constant's value is `AbstractRector::class` rather than a written string.

##### Both halves of the route question, and no corpus

The analyzer accepts either a `#[Route]` attribute or a `@Route` docblock, and they reach the answer through
different helpers — so the pairs carry both, and the stub gained the attribute class for it. Breaking
`hasAttributeNamed()` turns both rules red and drops exactly the attribute-side findings, leaving the docblock
ones: the two halves are separately load-bearing.

No corpus exercises either rule. `extends AbstractController` appears **0** times across hihaho, finconnect,
rector-src and mijntp — all four are Laravel or Rector, and a Symfony corpus is not among the projects this
repository has to hand. The gate is the evidence, and this file says so.

#### The dynamic-name family, four rules at once

`phpstan-strict-rules` ships five rules about names a program computes rather than writes, and one of them
emitted. All four of the others refused on a hook mapping rather than on anything they do, and all four are
the same three lines: guard on the name being written, then report with the receiver described.

Mago has an exact counterpart for each node PHPStan gives them, which is what makes the mapping a mapping
rather than an approximation — probed in one file before any of it was written:

| PHPStan | Mago | children |
|:--|:--|:--|
| `MethodCallableNode` | `MethodPartialApplication` | `Expression` + `ClassLikeMemberSelector` + `PartialArgumentList` |
| `StaticMethodCallableNode` | `StaticMethodPartialApplication` | the same three |
| `PropertyFetch` | `PropertyAccess` | receiver + selector |
| `StaticPropertyFetch` | `StaticPropertyAccess` | class + name |

The probe settled two things that reading could not. A `PartialApplication` **category** node fires as well,
carrying the specific kind as its only child — the same shape as `Access` over `ClassConstantAccess` — so a
hook registering both would report every finding twice, and only the specific kinds are registered. And
`getName()`/`getVar()`/`getClass()` on a virtual node are the fields an ordinary call has under different
names, so they are rewritten into that fetch rather than given a second reading.

`phpstan-strict-rules` reads **16 of 45** now, and the total 76 of 169. `VariablePropertyFetchRule` is the one
that did not come with them: it asks `->isLiteralString()` of a type and takes the universal-object-crates
parameter, so it moves onto those rather than emitting.

##### The message described nothing, and the gate said so

`$context->receiverType` is null for a `MethodPartialApplication` — probed, with the requirement declared —
while `Support::expressionType()` on the same child answers the receiver's class. The receiver shortcut is
keyed on the field table's own navigation, which the new row spells identically to an ordinary call's, so it
matched and the message rendered as `Variable method call on .` — the description of nothing, on the right
line. Excluded by kind, with the probe in the comment; putting the exclusion back turns the pair red with
exactly that message.

##### A near miss another rule of the package catches

The good example first held an ordinary `$holder->$name()` beside the written callable, to show the rule is
silent on a different node kind. PHPStan reported it — under the *same* identifier, `method.dynamicName`,
because `VariableMethodCallRule` catches it and the gate registers the package's own neon as well as the rule
under test. A near miss that a sibling rule reports makes the pair say nothing about this one, so it is out,
and the reason is written in the example.

##### Corpus

`method.dynamicName` agrees **2 of 2** on hihaho and **7 of 7** on finconnect, with nothing only-original and
nothing only-port; the identifier covers `VariableMethodCallRule` and the new `VariableMethodCallableRule`
together, so it is a joint result rather than one for the new rule alone. The static and property identifiers
report nothing on either side of both corpora, and the instrument names them rather than counting them as
agreement.

#### An attribute as the node a rule fires on

`RequireIsGrantedEnumRule` reads the role in `#[IsGranted('ROLE_ADMIN')]` and asks for an enum constant
instead. It refused on the hook, and everything under it was already in the vocabulary: an attribute reached
*from a declaration* has had a field row since the attribute helpers were written, and the hook's own node
needs the same two readings.

Probed rather than assumed, on a file holding a positional argument and a named one:

- `attributeName()` answers the **resolved** name — `Probe\IsGranted` for an imported `#[IsGranted]` — which
  is what `$node->name->toString()` gives a rule after PHPStan's own name resolution.
- The arguments are a `PartialArgumentList`, which the ordinary argument helpers already navigate, and
  `positionalArgAt(0)` answers the value of a *named* first argument too — which is what `$node->args[0]` does
  on the other side.

`symplify/phpstan-rules` reads **43 of 89**, and the total 77 of 169.

The good example carries a near miss the gate would otherwise not have: an attribute of a different name
holding the same string. The rule gates on the resolved attribute name, so without that case the pair would
pass whether or not the gate does anything.

`NoBareAndSecurityIsGrantedContentsRule` is the other rule on this hook and did not come with it. It moves off
the hook onto `preg_split()`, which needs a list-producing runtime value and an iteration over it — a
descriptor kind this vocabulary does not have, rather than one more accessor.

No corpus exercises it. `#[IsGranted` appears in none of the four projects to hand, which are Laravel and
Rector; the gate is the evidence.

#### A rule that would have shipped iterating the characters of its own parameter name

`VariablePropertyFetchRule` is the fifth of the dynamic-name family and the one the last step left behind. Two
vocabulary additions get it to the emitter — `$type->isLiteralString()->yes()`, answered from the same
refinement `getConstantStrings()` reads, and `$classReflection->is($name)` asked of a class the rule *named*
rather than of the scope's, which is `classDescendsFrom()` one receiver along.

**And then it must not be emitted.** The rule takes `string[] $universalObjectCratesClasses`, and the package
wires it `universalObjectCratesClasses: %universalObjectCratesClasses%` — a container parameter **PHPStan's
own core declares**, not the package. The default lookup asked the package's neon, found nothing, and fell
back to the parameter's *name*, so the emitted constructor read:

    public readonly string $universalObjectCratesClasses = 'universalObjectCratesClasses',

A `string[]` option defaulted to a string, which `Support::anyOf()` would then iterate character by character.
That plugin parses, loads, runs, and is wrong — the exact failure the generator exists to refuse, and it took
making the rule reach the emitter to see it.

An unresolvable `%parameter%` is recorded now and refused where it is read, naming the parameter and why there
is no value behind it. Ordered before the derived-value check, so the message is the cause rather than the
symptom: reached in the other order it said "the package wires no configured values for this rule", which is
false — the package wires it, to something this transpiler cannot read.

**No rule that already ships is affected.** Every emitted manifest was scanned for a parameter whose default
equals its own name, and there is none; the emission diff over seven packages and three targets changes not
one byte. The census records the new refusal, which is what keeps the two vocabulary additions honest: they
are not carried by any emitted plugin, and reverting either changes that entry.

#### A lookup the resolver already knew, asked as a question

`NoAbstractControllerConstructorRule` is four guards and a report, and it refused on
`$node->getMethod('__construct')` — a call `resolveMethodLookup()` has resolved for a long time. Two things
were missing, and both are one shape short of what was there:

- **In predicate position.** The resolver answers the declaration or null, so `if (! $node->getMethod(…))` is
  the null check. Only the value path consulted it, so a rule asking the same call as a *condition* was
  refused by the generic arm underneath.
- **With a written name.** The first rule to reach the lookup found its method by a name read out of a
  docblock, so only the computed shape was resolved — and a plain `'__construct'` then refused on its own
  string literal.

`symplify/phpstan-rules` reads **44 of 89**, and the total 78 of 169. Three further rules move off
`->getMethod()` or the string literal onto the obstacle each actually has — a `foreach` in an inlined helper,
and `->returnType` on a looked-up method.

The mutation check is the gate's own: removing the predicate arm does not make a test fail by disagreeing, it
makes `test_every_example_pair_has_a_rule_that_emits` fail, because the pair is left with no rule behind it.
That test exists for exactly this — a rule that stops emitting takes its evidence with it and nothing else
notices.

The good example carries all three near misses the rule's guards turn on: an abstract `*Controller` with no
constructor, a concrete one with a constructor, and an abstract class with a constructor whose name does not
end in `Controller`.

The corpus says nothing about it, and the reason is not the one first written here. Abstract `*Controller`
classes are **not** absent from the projects to hand — hihaho has 1, finconnect 4, mijntp 8, rector-src none —
and the differential was run rather than inferred from that count: on hihaho the identifier reads
`agree 0, only-original 0, only-port 0`, because the one class there declares no constructor. The other two
that have such classes do not install `symplify/phpstan-rules`, so there is nothing to compare. hihaho's whole
run for the package stays at `agree 422, only-original 0, only-port 0`.

The first version of this paragraph said the classes appear nowhere, which was written from the shape of the
previous few rules rather than from a grep. It is the same mistake this file keeps recording: a count is
cheap, and an absence asserted without one is not a measurement.

#### Two questions that look like one, and the corpus that answers for 45 sites

`PreferDirectIsNameRule` asks whether the Rector rule *around* a call is the abstract base of a family, so it
can skip it. It refused on `isAbstract()`, and the arm that already answers `isAbstract()` was right to: the
existing one reads the `abstract` modifier off the declaration a class-like hook fired for, and this rule
registers `MethodCall`. There is no `abstract` token anywhere near that node.

Two questions with one spelling. The declaration one stays where it is; the enclosing one is answered from the
class-like's metadata flag, and only `isAbstract` is widened — the five predicates beside it (`isClass`,
`isInterface`, `isTrait`, `isEnum`, `isAnonymous`) are about *which hook fired*, and asking them of an
enclosing class means something else.

`symplify/phpstan-rules` reads **45 of 89**, and the total 79 of 169.

##### The strongest corpus result of the session

`rector-src` is where this rule lives, and it reads **`agree 45, only-original 0, only-port 0`** — the
package's whole run there goes from 34 agreeing to 79, with nothing on either side of the ledger. hihaho stays
at `agree 422, only-original 0, only-port 0` and reports nothing under this identifier, which is what a
Laravel application should do with a rule about Rector rules.

Measured, not inferred — the correction two sections up is why that distinction is now written down every
time.

##### The good example is three near misses

The direct `$this->isName()` the rule asks for; the abstract base of a family, where the fetched service
legitimately lives; and a plain class that is not a Rector rule at all. Making `enclosingClassIsAbstract()`
answer false reports the abstract one at line 31, which is exactly the guard it stands for.

`Runtime\Declares` went one point over its complexity limit when the helper landed there, so it sits in
`Reflect` instead — the class that already asks the codebase about a class-like. A new baseline entry for a
runtime class is the thing that split is there to avoid.

#### An attribute class, and one corpus number I could not account for

`RequireAttributeNamespaceRule` asks whether a class carries PHP's own `#[Attribute]` and, if so, whether it
lives in an `Attribute` namespace. Its only obstacle was `isAttributeClass()`, and the answer was already
built: `Support::hasAttributeNamed()`, added for `AttributeFinder::hasAttribute()` two steps ago, compares
resolved names exactly — which is how `#[\Attribute]` and an imported `#[Attribute]` both come back as
`Attribute`. Only `isAttributeClass` is mapped; nothing else moved.

`symplify/phpstan-rules` reads **46 of 89**, and the total 80 of 169.

The good example holds the near miss the guard exists for: a class that *carries* an attribute without being
one. Widening `hasAttributeNamed()` to "has any attribute" reports it at line 14, which PHPStan does not — so
the discrimination is load-bearing rather than incidental.

##### rector-src is silent on both sides; hihaho reads one only-original I did not trace

`rector-src` has seven attribute classes and reads `agree 0, only-original 0, only-port 0` — all seven are in
an `Attribute` namespace, so silence is the right answer on both sides. The package's whole run there stays at
`agree 79, only-original 0, only-port 0`.

hihaho has exactly one attribute class, `app/Attributes/Description.php` — namespace `App\Attributes`, plural,
so the rule reports — and the differential reads **`only-original 1`**. What was established, and what was
not:

- Running `mago` by hand over the differential's own sandbox, with its generated `mago.toml` and worker,
  produces the finding: one issue, that file, that identifier.
- A fixture of the same shape — docblock, `use Attribute;`, an attribute *with arguments*,
  `final readonly class` — is in the example pair now and the gate is green on it, line and message.
- So the emitted plugin does report this shape, and the number is not reproduced by anything I could build.

That number is traced now, and it was the instrument. See the section below.

#### The instrument was filing one rule's findings under another rule's name

The section above left an `only-original 1` it could not account for: the port reported the finding when mago
was run by hand over the differential's own sandbox, and the differential still counted it as missing. The
cause is in the instrument, on **both** sides, and it is worse than a lost finding.

Identifiers are matched by substring, because a rule may report under a code it computes —
`NoDebugInNamespaceRule` writes `'hihaho.debug.noDebugIn' . $namespace`, so the identifier the manifest
carries is only the start of every code it can report. But one identifier can be a strict prefix of another,
and `symplify.requireAttributeName` is a strict prefix of `symplify.requireAttributeNamespace`.

- **Port side.** `identifierIn()` returned the *first* identifier the code contained, so
  `RequireAttributeNamespaceRule`'s finding was filed under `requireAttributeName` — where it landed on the
  same site as that rule's own finding and was counted as an **agreement**. One rule's corpus number stood on
  another rule's work.
- **Original side.** `PhpstanReport::collect()` was asked one identifier at a time, and `str_starts_with`
  matched the namespace finding for the *name* identifier too. So the same finding was counted twice, once in
  each bucket.

Both now file each finding under the longest identifier that claims it, which keeps the computed-code case
working and settles the prefix one. `PhpstanReport::owner()` is the original side's half; both are pinned by
`AttributesAFindingToTheRightRuleTest`, whose mutation — first match instead of longest — turns it red with
exactly the wrong attribution.

##### How much was at risk, and what actually moved

Five identifier pairs across the seven corpora are prefixes of one another: four `phpunit.covers*` pairs and
the `symplify` one. Only the `symplify` pair could fire, because no `phpunit.covers*` rule emits.

Every corpus number quoted in this session was re-run against the corrected instrument. **`rector-src`
`symplify/phpstan-rules` is unchanged at `agree 79, only-original 0, only-port 0`**, with
`rector.preferDirectIsName` still `agree 45`. **hihaho moves from `agree 423, only-original 1` to `agree 423,
only-original 0, only-port 0`** — the one disagreement was the misattribution and nothing else.

The failure is silent in both directions at once, which is why it survived: the rule that gains a finding
reads as agreeing, and the rule that loses one reads as under-reporting, and neither says anything is wrong.
It was found only because a rule shipped whose identifier happened to be the longer half of a pair, and
because the number it produced was chased rather than accepted.

#### A second loop, and 298 agreeing findings on a Laravel application

`NoControllerMethodInjectionRule` walks a controller's methods and then each method's *parameters*, and the
second loop was the one with no reading. Three steps, each narrower than the last:

1. **`getParams()` of a looped method.** `Support::declaredParams()` navigates a `Part` as readily as the
   hook's `Node`; a hardcoded `$node` was the only thing holding it to the declaration under analysis.
2. **`getParams() === []`** — whether a method takes parameters at all. The list was produced and iterated
   nowhere, so the emptiness test had no arm.
3. **`foreach` over it**, which is one row in `ITERABLES`.

`symplify/phpstan-rules` reads **47 of 89**, and the total 81 of 169. Two other rules moved past the same
obstacles onto what each actually needs.

##### The corpus result

hihaho reads **`agree 298, only-original 0, only-port 0`** — the package's whole run there goes from 423
agreeing to **721**, still with nothing on either side of the ledger. The rule is filed under Symfony, but it
fires on any class named `*Controller` whose public method takes a class-typed parameter that is not Symfony's
`Request`, which is what a Laravel controller does 298 times in that application. rector-src is unchanged at
`agree 79, 0, 0` and reports nothing under the identifier, having no controllers.

That is the largest single-rule agreement measured in this repository outside the aggregates, and it is worth
saying why it was available: the rule reports per *parameter*, so one application yields hundreds of sites for
one rule, and every one of them exercises both loops and all four guards.

##### The good example is five near misses

A `Request` parameter, which the rule allows by name; a parameterless action; a private method, which the
visibility guard skips; a magic method that is not `__invoke`; and a class not named `*Controller`. The bad
one holds two offending parameters, because the rule reports once per parameter rather than once per method.

#### The other spelling of the same loop, and a name that has to be the resolved one

`NoValueObjectInServiceConstructorRule` writes `$node->params` where the rule before it wrote `getParams()`,
so the list the last step made iterable had one more door into it. Two additions:

- **`->params` on a method hook node**, which is the same list `getParams()` hands back.
- **A written parameter type read as its *resolved* name.** `$param->type->toString()` gives what PHPStan
  resolved the name to, and this rule matches it against `#(ValueObject|DataObject|Models)#` — a pattern that
  only ever matches a *namespace* segment. Reading the name as written answers `Money`, which matches
  nothing. The mutation says it exactly: swapping `hintName()` for `textOf()` leaves the plugin reporting
  nothing on its bad example, where PHPStan reports `Examples\ValueObject\Money`.

**The seven-package total does not move: `symplify/phpstan-rules` registers this rule nowhere**, so it is
outside the portable denominator by the same rule that keeps eight of its rules out. What it gains is a rule a
consumer can register itself, emitted and gated — and a corpus number, which is the part worth having.

##### 158 agreeing on the same application

hihaho reads `agree 158, only-original 0, only-port 0`, taking the package's whole run there from 721 agreeing
to **879**, still nothing on either side. Two rules in two steps have added 456 agreeing findings to one
application, both by iterating parameters — which is the shape that yields sites in bulk, because a service
constructor has several and each is its own finding.

##### The good example holds the case metadata could have broken

A value object may hold a value object, and the rule skips it by the *enclosing* class's resolved name.
Metadata lowercases many names, and a lowercased one never matches `ValueObject` — so that example is what
says the guard reads a name with its case intact. Beside it: a service taking a service, an untyped
parameter, and a value object as a *method* argument, which is what the rule asks for.

The gate caught one more thing on its own. `$scope->isInClass()` folds away on a `ClassMethod` hook, and the
sentence it folds with had never been emitted before — so `test_every_dropped_guard_names_why_it_cannot_hold`
refused it as a drop nobody had proved. It is proof by construction, like the declaration-hook one beside it:
PHP has no method outside a class-like, and the fold covers the four class-likes and `Method` and deliberately
not a function, closure or arrow function.

#### A collaborator whose four branches are PHPStan's type classes

`NoClassReflectionStaticReflectionRule` refused with "early return from a helper that is not a boolean
literal", which named the shape of `RectorAllowedAutoloadedTypeAnalyzer::isAllowedType()` rather than the
reason it cannot be inlined. The reason is what its branches are: `UnionType`, `ConstantStringType`,
`ObjectType`, `GenericClassStringType`. Those are PHPStan objects, not statements, so the *question* is ported
into `Runtime\RectorAutoloadedTypes` and the guards still come from the rule.

Two things had to change beside the table entry.

- **The static call shape had to carry arguments.** `staticHelperStandIn()` emitted `helper($context, $node)`
  literally, which was true of the one entry it had and would have silently dropped this one's argument. Both
  call shapes now build the list through one method. Emitting all seven packages plus `tests/Fixtures/Rules`
  for all three targets before and after gives **zero diff** apart from the output path in `mago.toml.snippet`
  — so that extraction changed no emitted byte.
- **A type that arrives already asked for is not asked again.** The rule writes
  `$t = $scope->getType($argValue)` and passes `$t`, so the `types` position holds a descriptor that already
  *is* a type; wrapping it a second time would have asked for the inferred type of an inferred type.

##### Every branch was measured, and one of them was wrong first

The two type models disagree about which shape a written expression produces, so each branch came from
`internal/probe-type-atomics.php` rather than from reading:

| written | PHPStan | mago atomic |
|:--|:--|:--|
| `Alpha::class` | `ConstantStringType` | `ClassLikeString`, variant `Literal` |
| `'TS\Alpha'` | `ConstantStringType` | `String`, `literalValue` on the refinement |
| `class-string<Alpha>` | `GenericClassStringType` | `ClassLikeString`, variant `OfType`, `constraint` |
| `class-string` | `ClassStringType` | `ClassLikeString`, variant `Any` |
| a class outside the analysed set | `ObjectType` | **`ReferenceType`**, kind `Symbol` |

The last row is the one the gate found rather than the probe. A first version read only `NamedObjectType`, and
the good example failed on `new \ReflectionClass($type)` with a `PHPStan\Type\ObjectType $type` parameter: the
port reported where PHPStan is silent. Probing that position gives `ReferenceType{Symbol,
PHPStan\Type\ObjectType}` — mago spells an *unresolved* class differently from a resolved one, and the
analysed set there is one example file. PHPStan gives an `ObjectType` either way, so reading only the resolved
shape is wider than the rule, not narrower.

That failure is also the mutation check: the branch went in because the gate was red without it and is green
with it, over the real rule under real PHPStan against the real emitted plugin.

Two smaller facts came out of the same probes and are recorded on the methods that depend on them:
`getClassAncestors()` carries implemented interfaces as well as parents, and it answers **lowercased** — which
is why the `is_a()` port folds case instead of comparing with `in_array()`.

##### What the pair covers

Bad, all four agreeing with PHPStan on line and message: a `::class` of the file's own class, the same name as
a plain string, a `class-string<T>` narrowed to it, and a bare `class-string`. Good: a php-parser `::class`, a
PHPStan class reached as an object, a `class-string<Node>`, a two-argument `ReflectionMethod`, and a
one-argument `ReflectionObject` — the last two for the rule's count and class-name guards.

`symplify/phpstan-rules` goes to **48 of 89**, and the seven-package total to **82 of 169 portable**.

#### A guard that read the wrong node, and a negation that only half applied

`NoRoutingPrefixRule` refused on `no node predicate for instanceof Identifier on a bytes`. The subject was the
alarm rather than the obstacle: `$parentCaller->name`, where the rule had just narrowed `$parentCaller` to a
`MethodCall`, resolved through the `ConstFetch` arm and rendered `Support::constantNameText()` about a method
call. Had the rule written only `$parentCaller->name->toString() !== 'import'`, that would have *translated* —
null compared against `'import'`, so every `@FrameworkBundle` import the original allows would have been
reported. The refusal is what stopped a plausible-but-wrong rule shipping.

Four defects came out of it, each measured before it was fixed.

##### 1. A narrowing the predicate inliner threw away

`rememberRefined()` records what an `instanceof` guard established, and it never reaches a helper the inliner
takes as a *predicate*: there the guard becomes a conjunct of one boolean expression, no binding statement is
emitted, and nothing records the test. The narrowed kind is now recorded on its own, and the descriptor
carries it as `as` — the key `Vocabulary::FIELDS` is already indexed by, so the field navigation and the
argument-list path both read it without a second mechanism.

That closed `no argument list on a expr node` for three rules besides this one.

##### 2. `!(a) || !(b)` was unwrapped as though the parentheses paired

`PhpBackend::conditional()` folds `!(c) ? false : rest` into `(c) && (rest)` by taking `!(` off the front and
`)` off the back. Those are not always the same pair. `!(a) || !(b)` passes both tests, and unwrapping gave
`a) || !(b`, rebuilt as `(a) || !(b) && (rest)` — De Morgan applied to one operand with the connective left
alone, which is the opposite guard for every subject where `a` holds.

Measured, not assumed: no rule emitting at the time hit it, so the fix changes no emitted byte. That is
exactly why `NegatesAWholeGuardTest` pins it rather than a snapshot — the shape is one vendor release away
from a rule that does emit, and it fails silently. Reverting the fix reproduces `(a()) || !(b()) && (rest)`
and turns two of its six cases red.

##### 3. An exiting statement hoisted out of an expression

An inlined predicate folds to one expression, so anything it appends to the statement list lands at the
*caller's* position. For a statement that exits, that inverts the helper: a binding which cannot be made
should make the helper answer false — the finding stands — while the hoisted form returned from the hook and
reported nothing. This rule hoisted an argument binding whose bail fired on every `prefix()` whose receiver is
not a call at all, which is the ordinary case the rule exists for.

An argument read inside a predicate is now the expression itself. `argumentList()` and `positionalArgAt()`
both answer null for a subject with no arguments and every question asked of the value is null-tolerant, so a
missing argument makes the chain false — which is what the helper's own `return false` says.

`refuseAHoistedExit()` stays behind it for the statement kinds that have no inline form. It is a net with no
live case today, and that is stated rather than implied: with the inline binding taken out it fires, and the
census records the refusal for `NoRoutingPrefixRule` and `NoGetRepositoryOutsideServiceRule`. That is its
mutation check, and it is the only evidence for it.

##### 4. Navigating from a nested call found nothing, silently

The gate caught the last one, and only the gate could have. With the three fixes in place the bad example
agreed with PHPStan and the good example was reported twice — the allowed-bundle test never held. Probing each
step: mago wraps a *nested* call in a `Call` category node with the concrete `MethodCall` as its only child.
`isMethodCall()` already went through that wrapper; `selector()`, `argumentList()` and `nthExpression()` did
not. So the kind test answered yes and every navigation off the same part searched the wrapper's children,
found none, and answered null.

The hook's own node is the concrete call, which is why nothing had needed the unwrap and why no emitted rule
was wrong about its *own* node. A rule reaching a call through a field was the shape that had never been
gated.

##### What the pair covers

Bad: two `import(...)->prefix(...)` calls on this project's own controllers, agreeing with PHPStan on line and
message. Good: the two allowed bundle prefixes, a `collection(...)->prefix(...)` — `CollectionConfigurator`
declares `prefix()` too, so only the receiver's type declines it, which is what says the type guard does the
work rather than the name — and a `prefix()` on an unrelated object. The two configurators joined the shared
stubs; the pair resolves against those rather than a real Symfony install, like every pair beside it.

`symplify/phpstan-rules` goes to **49 of 89**, and the seven-package total to **83 of 169 portable**.
`NoWithOnStubRule` is the one emitted file that changed, and its behaviour did not: the guard the first fix
repaired is followed by `! $var instanceof Variable && ! $var instanceof PropertyFetch`, which already
excluded the case the broken guard let through.

#### A subset measurement that described the harness

The two steps before this one changed how every emitted plugin navigates, so the evidence that matters is a
run over code nobody wrote for us. `../hihaho`, the whole project, `symplify/phpstan-rules`: **912 agreeing, 0
original-only, 0 port-only** over 2932 files, against **912 / 0 / 0** for the commit before them. The
per-identifier tables are identical apart from one new `0 0 0` row for `NoRoutingPrefixRule`.

That comparison took two attempts. The first ran the old commit from a `git worktree` with this repository's
`vendor/` symlinked in — and composer's autoloader resolves `__DIR__` through the symlink, so both sides loaded
the *same* `src/`. The tell was `emitted: 52` on both, where the old commit emits 51. It is the shape
`CLAUDE.md` already records for a no-op `git stash`: a BEFORE run that is silently the AFTER one. The second
attempt used a self-contained copy, and `emitted: 51` against `emitted: 52` is what says the two sides differ.

`rector.noClassReflectionStaticReflection` reads **33 agreeing, 0, 0** there — the first outside evidence for
the rule two steps back, whose only check until now was its own example pair. `symfony.noRoutingPrefix` reads
`0 0 0`: no corpus available here uses Symfony's routing configurators, so the fires gate remains its only
evidence, and this run says nothing about it.

##### 29 findings that were the source paths, not the port

Narrowing the same corpus to `--paths=tests` reported **only-port 29**, all under `symplify.noDynamicName`, all
of the shape `($this->handler)(..)` — invoking a property whose class declares `__invoke`. The rule allows
that; the port reported it.

The cause is the one the mago-config docblock already names, one level in. `includes` carried the consumer's
`vendor/` so mago could walk a framework ancestry, and nothing else. PHPStan's autoloader does not stop at the
analysed directories: it resolves an `App\` class declared under `app/` while the run analyses only `tests/`.
Mago had never read the class, so its inferred type is a `ReferenceType` rather than a named object, the
`__invoke` test could not run, and the guard fell through.

The control settles it rather than the reading: adding the consumer's own root to `includes` takes the same
corpus, the same 1071 files and the same 9 agreements from **29 port-only to 0**. The full-project run is
unchanged at 912 / 0 / 0, and so is the `php-parser` table — which is what a fix confined to subset
measurements should look like.

Two things follow for any number quoted from a `--paths=` run. It was measured with mago reading less of the
project than PHPStan, so it overstated the port's width; and the direction is one-sided, so a `--paths` run
that reported **no** divergence was never weakened by this.

#### A parent class, and a differential that answered about the wrong file

`ForbiddenExtendOfNonAbstractClassRule` refused on `->getParentClass()`. Every question it asks after that is
a field of `ClassLikeMetadata`, probed rather than read across: `directParentClass` for the parent,
`ABSTRACT` and `BUILTIN` on its flags, `location->file` for the path the `vendor` guard tests. A vendor class
is `BUILTIN false` and a `\ArrayObject` is `BUILTIN true`, so the rule's two consecutive guards stay two
questions rather than collapsing into one.

`getParentClass()` answers a *named class*, which is a kind the vocabulary already had — so
`! $parent instanceof ClassReflection` becomes the existence test and `isAbstract()` goes to the codebase with
no new arm. Only `isBuiltin()`, the declaring file and a `=== null` on that kind were missing.
`ShouldCallParentMethodsRule` moves past the same obstacle to `->hasNativeMethod()`.

##### The gate was green and the corpus was not

`../hihaho`, whole project: **89 agreeing, 0 original-only, 119 port-only**. Every divergent site was a
`FormRequest` subclass — a class extending a concrete framework base, which the rule skips because the parent
is declared under `vendor/`.

The cause was in the harness, and specifically in the previous step's fix to it. Adding the consumer's whole
root to mago's `includes` also adds `_ide_helper.php`, which a Laravel project keeps there and which
redeclares framework classes. Probed in the differential's own configuration:
`Illuminate\Foundation\Http\FormRequest` resolves to `/…/hihaho/_ide_helper.php`, not to the vendor copy, so
the `vendor` guard could not hold. The control is the previous configuration: with `includes` back to the
vendor tree alone, the same rule reads **89 / 0 / 0**.

PHPStan does not have the problem because it resolves through the autoloader, which names one file per class.
So the fix follows that map rather than excluding stub files by name: `ResolutionRoots` reads the consumer's
`composer.json` and includes its `psr-4`, `psr-0`, `classmap` and `files` entries.

##### And the first version of that fix was worse than the bug

Including every autoload root took the same corpus to **35 agreeing and 966 original-only**. An `includes`
entry is scanned rather than analysed, and `app` and `tests` were in both lists, so most of the corpus stopped
being reported on at all. A root already covered by `--paths` is therefore left out, which is what makes the
list add context for what the run does *not* analyse — the case a subset creates and the only case the roots
exist for.

Both runs now read what they should: the whole project **1001 / 0 / 0** with the new rule at 89 / 0 / 0, and
`--paths=tests` **128 / 0 / 0**, keeping the 29 the previous step closed.

##### Two things the pair cannot show, said rather than implied

The vendor branch has no sandbox: there is no vendor tree in the example directories, so the pair covers the
abstract parent, no parent, a builtin parent and an interface-only class, and the vendor guard is exercised
only by the differential above. And the file test compares mago's path against PHPStan's absolute one — mago's
is relative to the analysed root when the paths are relative — so a project whose own directory has `vendor`
in its name would diverge, in the narrow direction. No corpus here has one.

##### The refactor that dropped a refusal

Splitting `nullComparison()` to keep it under the complexity limit lost the arm that refuses a null test
against a kind with no meaning for one. Nothing failed: the emitted output was unchanged and the suite was
green apart from the census, whose single moved line — `StrictFunctionCallsRule` no longer needing
`null comparison against Expr_Variable, which resolved to a hook-node` — was the whole evidence that a
load-bearing refusal had gone. A refusal that stops existing is invisible in every direction except that one.

#### A refusal that named the accessor rather than the obstacle

`IllegalConstructorStaticCallRule` refused on `->getFunction()`, which reads as a capability gap and is not
one. Two small arms close it: `$scope->getFunction()` reduces to the enclosing function's *name*, which
`enclosingFunctionName()` has answered since the cognitive-complexity port, and `$scope->isInTrait()` walks to
the nearest class-like and asks its kind. Neither needed a new descriptor kind — the name is bytes, so the
rule's `=== null` guard translates through the byte comparison it already had.

The refusal now reads `->getTraitAliases()`, and that one is real. `isInRenamedTraitConstructor()` asks PHP's
trait *alias* table — which name a using class reaches a trait method under after `use T { m as other; }` —
and mago's metadata carries no counterpart. `TraitUsers::aliases()` reads the same adaptations off the CST for
the coverage passes, but that is an after-analysis walk over every file, not a question a node hook can ask
about the class it is standing in.

So the rule does not emit, and the census says why. That is the whole of this step: the previous reason
pointed at an accessor two other rules use for something else entirely, and a reader sizing the work from it
would have started in the wrong place.

`RequireParentConstructCallRule` loses `->isInTrait()` from its needs at the same time; it still refuses on
the `throw` its first guard uses as an assertion.

No emitted byte changed, across all seven packages and all three targets.

#### Three corpora, and the denominator they leave behind

The last several steps added rules whose only evidence was their own example pair. Three differentials, all
reproducible from projects on the measuring machine:

| corpus | files | identifiers | exercised | agree | only-original | only-port |
|:--|--:|--:|--:|--:|--:|--:|
| `hihaho`, symplify | 2932 | 56 | 14 | 1001 | 0 | 0 |
| `rector-src`, symplify + phpunit + deprecation | 2872 | 61 | 8 | 88 | 0 | 0 |
| `finconnect`, strict-rules + phpunit + deprecation + complexity + type-coverage | 1895 | 25 | 15 | 1294 | 420 | 1346 |

`rector-src` is worth its own row for what it adds rather than its total: four identifiers no other corpus
reaches — `rector.avoidFeatureSetAttributeInRector`, `rector.noOnlyNullReturnInRefactor`,
`rector.preferDirectIsName` and `symplify.stringFileAbsolutePathExists` — all at zero divergence.

**Read the denominator first.** Across the three there are 81 distinct identifiers and **33 are exercised**.
Of the `symfony.*` and `doctrine.*` rules, exactly one is: `symfony.noControllerMethodInjection`. None of the
three corpora is a Symfony application, so fifteen Symfony rules and three Doctrine ones have their example
pair and nothing else — `symfony.noRoutingPrefix`, added two steps ago, among them. A `0 0 0` row for those
is not agreement.

##### Every divergence on `finconnect` has one of two named causes

**1340 of the 1346 port-only findings are a configured threshold against a package default**, and the
consumer's own neon says so: `cognitive_complexity: class: 517, function: 484` where the package ships 40 and
9 (389 findings), and `type_coverage: param: 83.2, property: 86.4` where it ships 99 for both (951). A
generated plugin carries its own package's defaults deliberately, so it reports more. Same cause as the
`php-parser` table above, three orders of magnitude louder because this consumer's thresholds are set to
where its code currently is.

**The remaining 6, and the 420 original-only, are the boolean-condition family.** Traced rather than assumed:
the port describes `$this->request->get('form')` as `scalar|array|null` and reports, where PHPStan is silent.
Both directions come from the same gap — PHPStan reaches Laravel through larastan, mago through nothing — and
`--extension-host=` is the control for it, already measured on another corpus at 33 of 42 false positives
closed by one fifteen-line return-type provider.

##### The flags are a real axis, measured rather than assumed

`BooleanRuleHelper::passesAsBoolean` depends on `checkNullables` and `checkUnionTypes`, which the emitted
plugin takes as constructor parameters at PHPStan's own defaults. Forcing both on for *both* engines with
`--parameter=`, on the same corpus and the same package:

| | agree | only-original | only-port |
|:--|--:|--:|--:|
| the family at PHPStan's defaults | 679 | 417 | 6 |
| the same family, both flags forced on | 853 | 469 | 10 |

So a number quoted for this family without its flags is not a number. That is why the plugin takes them
rather than baking them, and why `--parameter=` exists: one corpus run twice answers what two corpora at
different settings cannot.

##### A fourth corpus, and the first Symfony one

`symfony/demo` at `--depth 1`, 49 files of application code: **70 agreeing, 0 original-only, 0 port-only**.
Small, and it is the only Symfony application measured here, which is what it is for. Four identifiers get
outside evidence for the first time — `symfony.noClassLevelRoute` (3), `symfony.requireInvokableController`
(12), `symfony.requiredIsGrantedEnum` (3) and `phpunit.avoidAnyExpects` (1) — taking the exercised union
across the four corpora from **33 to 37 of 81**.

`symfony.noRoutingPrefix` still reads nothing. The demo routes by attribute, so it has no
`import(..)->prefix(..)` for the rule to find, and its example pair remains its only evidence.

Reproducing it takes four config lines and a build step, all in the *corpus*, none in this repository:

```neon
includes:
    - vendor/symplify/phpstan-rules/config/services/services.neon
    - vendor/symplify/phpstan-rules/config/symfony-rules.neon
    - vendor/symplify/phpstan-rules/config/doctrine-rules.neon
parameters:
    excludePaths:
        - config/reference.php (?)
```

then `php bin/console cache:warmup`, because `phpstan-symfony` reads the compiled container XML and aborts
without it. The services file is separate from the family files on purpose in that package, and the
differential registers every emitted rule as a service — so a consumer that includes some families and not
the shared collaborators cannot be measured until it includes them.

Two harness gaps surfaced getting there, both real and both fixed:

- **`phpstan.dist.neon` was unreadable.** The resolver knew `phpstan.neon` and `phpstan.neon.dist`; Symfony's
  own skeleton writes the suffix in the middle. The first Symfony corpus looked like a project with no PHPStan
  configuration at all.
- **PHPStan's optional marker crashed the exclusion test.** `config/reference.php (?)` parses as a
  `Nette\Neon\Entity`, not a string, and `absolute()` took a TypeError. The marker says nothing about the
  corpus, so the path is unwrapped and kept.

#### A refusal that was right about the general case and wrong about this one

`PhpUpgradeImplementsMinPhpVersionInterfaceRule` refused on `instanceof FullyQualified`, and that refusal's
own text says why it could be answered: "the test is about resolution rather than spelling". Its loop walks
`$node->implements`, which resolves to `directParentInterfaces` — names the *codebase* resolved. So no item
can be the unresolved spelling the guard skips, and the guard folds.

Sound only because the comparison behind it reads the same resolved list, so the `->implements` descriptor now
carries its provenance and the name comparison folds case for a metadata-sourced item — the fold
`holdsMetadataNames()` already applied to a whole list, applied to one item of it.

##### Reading the emission caught two bugs the fold would otherwise have shipped

The first emission was plausible and wrong twice over, and both were latent gaps rather than anything this
rule introduced:

- **The comparison was case-sensitive against a lowercased left side.** `$implement === 'Rector\Version-
  Bonding\Contract\MinPhpVersionInterface'` can never hold, so the loop never exited.
- **`return [];` inside the loop emitted nothing.** A trailing `return []` is the fall-through of collected
  report conditions and correctly emits no bail; one inside a loop body is a real exit, and a method's last
  statement cannot sit in a loop. Without the bail the loop body came out empty.

Either one alone makes the rule report every class the loop exists to let through.

##### Measured on real code, not argued

`rules/Php8*` in `rector-src` holds 38 classes that match both of the rule's guards — the fully qualified
name ends in `Rector` and carries a `\Php80\`-shaped segment — and all 38 implement the contract. Both
engines are silent on them, so the differential row reads `0 / 0 / 0` and by the usual standard says nothing.

Here it says something, because the mutation says what the row cannot. Over `rules/Php81`, nine of those
classes:

| the emitted plugin | findings |
|:--|--:|
| as emitted | 0 |
| with the case fold taken out | 9 |
| with the loop's bail taken out | 9 |

So the guards were reached nine times and the exit fired nine times, on code nobody wrote for this. That is
the difference between a `0 0 0` row that is a pass and one that is silence — and the only thing that
separates them is having checked that the guards ran.

#### A loop whose two guards mean "or", and a constant on the next class along

`NoDoctrineListenerWithoutContractRule` refused on `a foreach in an inlined helper whose body is not a guard
chain: Stmt_If`. The body is two membership tests, either of which answers the loop:

```php
foreach ($class->getMethods() as $classMethod) {
    if (in_array($classMethod->name->toString(), DoctrineEvents::ORM_LIST)) { return true; }
    if (in_array($classMethod->name->toString(), DoctrineEvents::ODM_LIST)) { return true; }
}
```

The inliner read a leading `if` as a `continue` guard, which is a *conjunct* — "this item does not match" —
and refused anything else. A leading `if` that returns the loop's match value is the opposite: the item
matches and the rest of the body is not reached, so it is a **disjunct**. A body mixing the two is refused by
name rather than folded, because a `continue` only guards what follows it and the answer nests rather than
joins.

Two smaller gaps behind it:

- **An array constant on a named class.** `DoctrineEvents::ORM_LIST` is a plain list of strings, known at
  transpile time exactly as a `self::` one is; the resolver only read the current class's. It goes through the
  same index the static-helper inliner uses, into a scratch constant scope so a same-named constant on the two
  classes cannot shadow.
- **`in_array()` over a method declaration's name.** The byte helpers already reach it through
  `Support::methodName()` for `str_ends_with`; a membership test over the same text asks the same question.

##### The pair proves the fold, and the mutation says so

No corpus on hand holds a Doctrine lifecycle listener — `hihaho` reads `0 / 0 / 0` for it and says nothing —
so the example pair is the evidence, and it is built to carry the fold: `BadProductListener` declares only an
ORM event and `BadDocumentListener` only an ODM one, so each bad case satisfies exactly one of the two
disjuncts.

| the emitted plugin over the pair | findings |
|:--|--:|
| as emitted | 2 |
| with the disjunction flipped to a conjunction | 0 |

A conjunction would need both lists to match at once, which neither class does. That is what makes the two
bad cases a test of the fold rather than of the rule around it.

`NoListenerWithoutContractRule`, the Symfony sibling with the same helper, moves past the same obstacle to
`->attrGroups on a hook-node` — the class-like attribute walk this vocabulary refuses deliberately.

#### A loop that ends by matching rather than by guarding

`NoConstructorAndRequiredTogetherRule` refused on `a foreach in an inlined helper whose body is not a single
guard`. Its helper is four `continue` guards and then `return true`:

```php
foreach ($class->getMethods() as $classMethod) {
    if (! $classMethod->isPublic()) { continue; }
    if (! $docComment instanceof Doc) { continue; }
    if (! str_contains($docComment->getText(), '@required')) { continue; }
    if (str_contains($docComment->getText(), 'circular')) { continue; }

    return true;
}
```

`anyBody()` required the trailing statement to be a guard of its own, so the refusal named the statement
rather than the shape. A bare `return <the match value>` after the guards adds no condition: reaching it means
every guard passed, which the conjunction of their negations already says.

##### Both the fold and each guard behind it are measured

No corpus on hand holds the shape — a `@required` public method beside a constructor — so the pair is the
evidence, and its `GoodCircularException` exists for the guard that sits directly in front of the trailing
return:

| the emitted plugin over the pair | findings |
|:--|--:|
| as emitted | 2 |
| with the `circular` conjunct removed | 3 |

The third finding is `GoodCircularException`, which the original allows. So the last of the four guards
survived the fold, which is the one a wrong reading of "the trailing statement is the guard" would have
dropped.

#### A split that has to happen while the plugin runs

`NoBareAndSecurityIsGrantedContentsRule` refused on `preg_split()`. Every other piece of it already
translated — the `in_array` over three class constants, the literal-string test, the three `str_contains` on
the attribute's value — and its helper is the guard chain the step before this one made foldable. What was
missing is the split itself.

It cannot be done at transpile time: the subject is a string literal read out of the *analysed* code, so the
pieces are only known while the plugin runs. `Support::splitByPattern()` does it, and the flags are checked
rather than ignored — `-1, PREG_SPLIT_NO_EMPTY` is what the caller writes and what the helper implements, and
any other limit or flag set produces a different list.

The rule's own `if ($joinedItems === false)` guard folds away. `preg_split()` answers false only for a
pattern it cannot compile, and the pattern reaches the helper as a literal the transpiler read out of the
rule — so the helper's return type is `list<string>` and there is no `false` for the comparison to find.

##### The split is load-bearing, and the shared identifier is not evidence

`GoodSingleIsGranted::verified()` carries `is_granted("ROLE_ADMIN") and user.isVerified()` — joined, so the
earlier guards pass, and one piece is not a permitted call, so the rule allows it. That is the case the split
exists for:

| the emitted plugin over the pair | findings |
|:--|--:|
| as emitted | 2 |
| with the split replaced by the whole string as one piece | 3 |

Unsplit, the whole expression contains `is_granted`, so the custom-function test never fires and the good
example gains a finding the original does not make.

**The corpus row cannot be read as this rule's evidence.** `NoBareAndSecurityIsGrantedContentsRule` and
`RequireIsGrantedEnumRule` report under the *same* identifier — `symfony.requiredIsGrantedEnum`, which is the
package's own constant for both — so `symfony/demo`'s `agree 3, 0, 0` names both rules and separates neither.
Checked rather than assumed: none of the demo's `#[IsGranted]` attributes joins two checks, so all three
belong to the sibling. The differential prints both rule names for a shared identifier, which is the honest
rendering; what it cannot do is attribute per rule.

#### An attribute walk that is one question, and the three fields behind it

`NoListenerWithoutContractRule` refused on `->attrGroups on a hook-node`, which the vocabulary declines
deliberately: metadata carries attribute names flattened and resolved, so answering `->attrs` and `->name`
from that list would be three mappings pretending the tree has a shape it does not.

The way past it is the one the codebase already prefers — map the *question*, not the fields. The nested walk

```php
foreach ($class->attrGroups as $attrGroup) {
    foreach ($attrGroup->attrs as $attr) {
        if ($attr->name->toString() === <literal>) { return true; }
    }
}
```

is recognised whole and becomes `Support::hasAttributeNamed()`, which `AttributeFinder::hasAttribute()`
already reaches through the collaborator table. The literal still comes from the rule's own source, so no
table holds the package's constant. Every part is matched against the source — both field names, the
single-statement bodies, the `===` against a literal — so a walk asking something *else* of an attribute is
still refused: `NoEntityOutsideEntityNamespaceRule` reads `->getParts()` off the name and is declined by the
same recogniser.

Three smaller fields behind it, each the second spelling of something already answered:

- `$classMethod->params` on a method the rule found in a loop — the list `getParams()` gives.
- `str_starts_with()` on a written type hint, through `hintName()`, which answers the resolved name
  `$param->type->toString()` gives after PHPStan's name resolution.
- `in_array($class->extends->toString(), [..])` on the PHP target. The `extends` arm of the membership test
  had a Rust rendering only, so the rule refused with "operand is still Rust" — the shape the backend's own
  refusal exists to catch.

##### Both new folds are load-bearing, measured on the good example

The pair carries one good case per accepted route: the attribute, the contract, an `__invoke`, a security
parent, a form-event parameter, and a Doctrine method the sibling rule owns. Two mutations, each against the
committed pair:

| the emitted plugin | good-example findings |
|:--|--:|
| as emitted | 0 |
| with the attribute question replaced by `false` | 1 |
| with the form-event hint test replaced by `false` | 1 |

`symfony/demo` reads `0 / 0 / 0` for both listener rules and says nothing about either: its listeners all
carry the subscriber contract, which is what the rules ask for.

#### A class-like body is a mixed list, and the narrowing that walks it

`NoProtectedClassStmtRule` refused on `no node predicate for instanceof PhpParser\Node\Stmt\ClassConst on a
expr`, which named the shallowest of three obstacles. Adding the two predicates was five minutes; the two
below are what the rule actually needed, and both were live defects rather than gaps.

**`->stmts` on a class-like answered the empty list.** The navigation resolved through `bodyOf()`, which looks
for a body kind — `MethodBody`, `Block`, a loop body — and a class-like has none. So a rule walking
`$classLike->stmts` would have emitted, loaded, iterated nothing and reported nothing, with every static
check passing. No rule in the corpus reached it, which is why nothing had said so: `->stmts` is only mapped
for a hook-node, and the four rules that write it hook a function-like.

`internal/probe-class-members.php` measures the layer instead of assuming it. Every member of a class, a
trait, an enum and an interface sits under exactly one `ClassLikeMember` child of the declaration, holding
exactly one of `Method`, `Property`, `ClassLikeConstant`, `TraitUse` or `EnumCase`, in source order. All four
declarations are in the probe because the emitted hook targets `Class`, `Enum` and `Interface`: this rule
never reaches an interface, since `declarationKindIs('Class')` guards ahead of the loop, but the next rule
walking a body will be handed one. `classMembers()`
unwraps that level and returns all of them, including the trait use the rule then skips through its own
`continue` — filtering here would make the port skip it for a different reason.

**A property keeps its modifiers one level down.** The same probe: `protected int $uses = 0;` is a `Property`
wrapping a `PlainProperty`, and the `Modifier` is a child of the inner one, where a method and a constant
carry theirs directly. `methodIsProtected()` reads the outer node's children, so it answers *not protected*
for every protected property. `memberIsProtected()` reads both levels, and the mutation below is the
measurement.

##### The `instanceof` narrowing was recorded without its polarity

The rule's loop opens with

```php
if (! $classStmt instanceof ClassMethod && ! $classStmt instanceof ClassConst && ! $classStmt instanceof Property) {
    continue;
}
```

Each `instanceof` recorded a narrowing for the subject, so the last one won and the member was navigated as a
`Property` from there on. What the guard establishes is "one of these three" and neither of them.

Short-circuit is the whole rule, and it was not being applied: `A && rest` and `! A || rest` reach `rest`
only where `A` held, and `! A && rest` and `A || rest` only where it did not. `keepNarrowingsOf()` now rolls
the record back for every shape but the two that carry, and `translateGuard()` does the same for what
survives the guard — `if (! $x instanceof K) { return; }` keeps its narrowing, a compound condition does not.

Only those two shapes carry, rather than a polarity rule applied recursively through every operand. A
rollback loses precision and cannot invent any, so the failure direction is a refusal that names the mixed
kind.

Here the stale kind refused rather than mis-read, because `FIELDS['Property']` carries no `name` row for the
read to land on. That is this rule's luck rather than the mechanism's: `Property` and `Method` both carry a
`type`, so a rule reading that after the same guard would have got the answer for the wrong member kind with
nothing to say so.

##### Every new fold is measured on the committed pair

The bad example holds one protected member of each kind, so each mutation moves the count by exactly the
member it stops answering for. The good examples cover the three routes that must stay silent: an abstract
class, `setUp()`/`tearDown()`, and a method the parent declares.

| the emitted plugin | bad-example findings |
|:--|--:|
| as emitted | 3 |
| with `classMembers()` returning `[]` | 0 |
| with `memberIsProtected()` reading the outer node only | 2 |
| with `is_class_constant_declaration` replaced by `false` | 2 |
| with `is_property_declaration` replaced by `false` | 2 |
| with the narrowing rollback removed | refused, so nothing to run |

Both engines report the same three lines — the constant, the property and the method, each at its own line
rather than at the class — which is what the anchor being the member and not the declaration means.

**The headline counts do not move.** `symplify/phpstan-rules` registers this rule nowhere, so it is one of
the eight the census excludes from the denominator: the package stays at 55 of 89 and the total at 89 of 169.
What the step adds is the capability, the two defects above, and one more gated emission.

#### The arithmetic operand helper, ported from a real run rather than from its source

The `phpstan-strict-rules` arithmetic family — thirteen rules — all ask
`OperatorRuleHelper::isValidForArithmeticOperation()`, and `Runtime\RuleLevel`'s own docblock had said why it
was not ported: the helper reaches `RuleLevelHelper::findTypeToCheck()`, which takes a criteria closure. That
part was already solved for the boolean family. What was left was the criteria, and the source could not be
read for it: PHPStan ships as a phar here, so `toNumber()` cannot be traced.

So it was measured instead, and `internal/probe-arithmetic-atomics.php` runs the measurement rather than
describing it: one file with a unary `+` per operand shape, the atomics mago gives at the position the rule
reads, and the real rule over the same file at each flag setting. It prints this:

| operand | reports when |
|:--|:--|
| `bool`, `true`, `null` | always |
| `int\|bool` | `checkUnionTypes` |
| `?int` | `checkNullables` **and** `checkUnionTypes` |
| `int`, `float`, `string`, `numeric-string`, `array`, a named object, a bare `object`, `mixed`, `int\|string`, `int\|float` | never |

`checkThisOnly` at its level-0 default silences the whole family: the same run reports 0 errors. That is why
the gate sets it false for these two rules, exactly as it already did for the six boolean ones.

Two of the original's four branches turned out not to need porting, and the table is what says so rather than
a reading of the code:

- **`toNumber() instanceof ErrorType` returns a *pass*** — its own comment says "already reported by PHPStan
  core". Every type that cannot coerce at all is therefore silent, which is the whole "never" row above. In
  the port that is one test: a candidate is a type whose every atomic is `int`, `float`, `bool` or `null`.
- **The operator-overloading branch is unreachable.** It asks whether an object accepts `+ 1`, and an object
  never gets past the branch above. A named object and a bare `object` are silent on the real run at every
  flag setting, which is the measurement rather than the inference.

`numeric-string` needs no accessory type either. PHPStan rejects a plain `string` through `toNumber()`, so
both spellings are silent — and `internal/probe-arithmetic-atomics.php` shows mago drops the accessory
regardless: a `numeric-string` parameter and the literal `'12'` both arrive as a bare `ScalarType(string)`.
Passing every string agrees with the original on both, and there is no third string to disagree about.

##### What the pair measures, and what it cannot

`OperandInArithmeticUnaryPlusRule` and `OperandInArithmeticUnaryMinusRule` emit, taking
`phpstan-strict-rules` from 16 to 18. Mutations against the committed pair:

| the emitted plugin | good-example findings |
|:--|--:|
| as emitted | 0 |
| with the coercion test removed | 5 — `string` twice, `array`, `stdClass`, `object` |
| with the union gate removed | 1 — `bool\|int`, named whole, as the original names it |
| with the null strip removed | 0 |

The last row is the honest one: the gate runs at the level-0 defaults, so `checkNullables` and
`checkUnionTypes` are both false and the null strip changes no answer there. It is load-bearing at
`checkNullables: true, checkUnionTypes: true`, where `?int` reports — and that is what
`PortsTheArithmeticOperandHelperTest` pins, row by row, against the table above. A pair cannot reach it,
because the flags are constructor parameters and the gate builds one worker per rule at the defaults.

##### The census under-sizes what a refusal costs, and this is the measurement

The four increment and decrement rules refuse with `no PHP navigation for node.var`, and their `needs:` line
in the census says only that. Adding the hook moved the refusal to the field row; adding the field row moved
it to a node predicate; the unported `isValidForIncrement()` sits behind all three. So the list names the
first obstacle and one more, not the set — three obstacles deep on these rules, and the helper it does not
mention is the expensive one.

That matters for the census's own purpose, which is sizing work before doing it: grepping
`isValidForArithmeticOperation` in the census before this step returned nothing, while the capability gated
seven rules. The header already warns that a shared label is not a shared capability; this is the other
direction, and the same warning has to be read into a `needs:` line that looks complete.

#### The increment family: four rules, one shared body, and six obstacles behind one refusal

The four increment and decrement rules refused with `no PHP navigation for node.var`, and the census listed
two needs under each. Both were true and neither was the work. Six things had to be built, and each one only
became visible once the one in front of it was gone — the sizing problem the previous section measured, now
from the inside.

**Two hooks that Mago spells as one node each.** `PreInc`, `PreDec`, `PostInc` and `PostDec` are four
php-parser classes over two Mago kinds — `UnaryPrefix` and `UnaryPostfix` — with the operator in a child. So
the kind picks the side and a gate picks the operator, the shape `BooleanNot` already used for `!`.
`postfixOperatorIs()` is the postfix reader; `unaryOperatorIs()` reads a `UnaryPrefixOperator` child and
answers false for `$x++`.

**A constructor the rule inherits.** All four are one class each holding a node type and two strings, and the
`OperatorRuleHelper` they delegate to is a parameter of the abstract parent's constructor.
`collectConfiguration()` read the rule's own constructor and returned early when there was none, so the
helper read as an unknown property and the refusal said "method call outside the vocabulary" — a message
about the call rather than about why it could not be resolved.

**An abstract declaration is not an implementation.** `$this->describeOperation()` resolves to whichever
class in the hierarchy has a body, and `Hierarchy::declaring()` returned the abstract parent's. PHP does not.

**Two literal folds.** The shared body builds its message and its identifier from methods each subclass
fills in: `sprintf('Only numeric types are allowed in %s, %s given.', $this->describeOperation(), ..)` and
`->identifier(sprintf('%s.nonNumeric', $this->getIdentifier()))`. The first needs a `$this->m()` whose whole
body is `return '<literal>';` to fold; the second needs that plus an all-`%s` `sprintf()` folded at transpile
time. The literal goes into argument position rather than into the format, so every other rule's `sprintf`
stays byte-identical.

**An `instanceof` the hook decides.** The shared body opens with `($node instanceof PreInc || $node
instanceof PostInc) && ! isValidForIncrement(..) || ($node instanceof PreDec || ..) && ..`. A hook fires for
one php-parser class, so each test is settled at transpile time. The fold is narrow: both classes have to be
hook entries on the same Mago node *carrying a gate*, which is the situation it is about — several classes
sharing one node kind, told apart by an operator. A virtual PHPStan node is excluded by that, and
deliberately: `$node->getOriginalNode() instanceof Class_` inside the class hook is a real runtime question.

##### A unary operand is not a receiver, and the probe said so before the gate did

`$scope->getType($node->var)` has a shortcut: where the argument is the vocabulary's own navigation to the
hook kind's receiver, the type arrives ready-made under `ReceiverType`. php-parser calls a unary operand
`->var` — the same name it gives a call's receiver — so adding that field made the shortcut fire on a node
kind that has no receiver at all.

`internal/probe-unary-receiver-type.php` measures it: on `++$count` and `$count--`, `$context->receiverType`
is null with the requirement declared, while `expressionType()` on the operand answers `int`. Both kinds are
now on the no-receiver list, next to `MethodPartialApplication`, which was found the same way.

The mutation is worth reading, because it is not a refusal:

| the emitted plugin | bad-example findings |
|:--|:--|
| as emitted | `bool given`, `null given`, `array given`, `stdClass given` |
| with the unary kinds off the no-receiver list | four findings, on the right lines, each reading `,  given.` |

A plugin that reports correctly and tells the reader nothing. The gate compares messages as well as lines,
which is what turns that into a failure.

##### The port is measured, and one of the six folds was speculation

`internal/probe-increment-operands.php` runs all four rules over one operand of every shape at each flag
setting. The population is much wider than the arithmetic family's: an `array` and a named object report at
every setting, where `+` and `-` are silent on both. The original is why — `isValidForIncrement()` and
`isValidForDecrement()` have no `toNumber()` pass, so nothing hands those shapes to PHPStan core. Reusing the
arithmetic table would have silenced most of what these rules find.

| operand | reports when |
|:--|:--|
| `bool`, `null`, `array`, a named object | always |
| a bare `object`, `int\|bool`, `int\|string` | `checkUnionTypes` |
| `?int` | `checkNullables` and `checkUnionTypes` |
| `int`, `float`, `numeric-string`, `mixed` | never |
| a plain `string` | `--` and `$x--` only, never `++` |

The last row is the one divergence, and it is chosen rather than overlooked. `isValidForIncrement()` passes a
string outright — its comment is `$a = 'a'; $a++;` — and `isValidForDecrement()` does not, so PHPStan reports
`--$text` and says nothing about `--$numeric`. Mago erases the distinction the difference turns on: measured,
a `numeric-string` parameter arrives as the same bare `ScalarType(string)` a plain string gives. The port
passes both, so a decrement of a plain string goes unreported rather than a decrement of a numeric one being
reported. The decrement pairs therefore hold a numeric string and not a plain one — a pair asserts agreement,
and it cannot hold a case where the two disagree.

Five of the six folds are load-bearing, each measured by breaking it: the `instanceof` fold, the inherited
constructor, the abstract skip and the message literal each take the rule back to a refusal naming a
different obstacle, and the receiver-type entry produces the empty-type message above. The sixth was
speculation and is gone: the same literal folds added to `stringLiteral()` alongside `rawStringLiteral()`
were never reached — removing them left all six rules emitting — so they were removed rather than kept
because they looked symmetrical.

##### One divergence measured on the way past

A docblock `array<int, string>` arrives as the same bare `KeyedArrayType` a plain `array` does, so a rule
interpolating the type renders `array` where PHPStan renders `array<int, string>`. That is mago dropping the
parameters rather than `Runtime\Describe` losing them, which is why the row sits in
`internal/probe-arithmetic-atomics.php` next to the atomics rather than in the renderer's own census. The
bad examples take a plain `array` for that reason.

#### The binary family: the dispatch dissolved, and what was behind it does not

The six binary arithmetic rules were single-blocker after the helper was ported: only the opening dispatch
stood, and it reads like the hardest shape in the corpus.

```php
if ($node instanceof BinaryOpDiv) { $left = $node->left; $right = $node->right; }
elseif ($node instanceof AssignOpDiv) { $left = $node->var; $right = $node->expr; }
else { return []; }
```

Two node classes, two pairs of field names, one body. `internal/handoff-multi-kind-hook-is-not-a-redesign.md`
had measured the same shape for the call kinds and found it dissolved — the children were identical in the
same order, so two php-parser names were one navigation — so the first thing to do was ask the same question
here. `internal/probe-binary-operands.php` answers it: a `Binary` holds
`Expression | BinaryOperator | Expression` and an `Assignment` holds
`Expression | AssignmentOperator | Expression`. The dispatch is a *target-set declaration*, not a per-branch
binding, and the mechanism written for it confirmed that on the rules themselves — both arms resolved to
`nthExpression(0)` and `nthExpression(1)`, compared after rendering.

All six emitted. Then the pairs disagreed with PHPStan on exactly one finding each, and that finding is why
this section ends where it does.

##### Mago types a compound assignment's right operand as the assignment's result

The pair's fourth case is `$count /= $nothing` with `null $nothing`: PHPStan reports it and the port did not.
The same probe, extended to print what the analysis knows about each operand:

| expression | operand 0 | operand 1 |
|:--|:--|:--|
| `$a / $b`, `bool $b` | `int` | `bool` |
| `$a /= $b`, `bool $b` | `int` | **`int\|float`** |
| `$a /= $n`, `null $n` | `int` | **`mixed`** |
| `$a /= $s`, `string $s` | `int` | **`mixed`** |
| `$b /= $a`, `bool $b` | `bool` | `int\|float` |

`ExpressionTypes` embeds "every expression type in the file", and for a compound assignment the type
recorded against the right-hand operand is the value the assignment *produces*. One level down, on the
`DirectVariable` inside the `Expression`, answers the same, so there is no navigation that recovers it. The
left operand is unaffected.

Every one of `int|float`, `mixed` passes an arithmetic check. So a plugin registering `Assignment` reports on
`$a / $b` and goes silently quiet on `$a /= $b`, and registering `Binary` alone is the same silence with the
target list admitting it. Both are a rule that looks like the original and covers half of it, which is what
the refusal invariant exists for.

##### So the outcome is a named refusal, and the mechanism is what names it

`Translator::refuseAnOperatorDispatch()` recognises the shape and refuses it, naming the kind and the operand
position. The sentence it prints says the two *kinds* hold their operands in the same positions, not that the
two *arms* navigate the same children: the agreement check left with the positive path, so the shipped
recogniser reads the arms' conditions and not their bodies. The kind-level fact is the measured one, and it
holds for any rule matching the shape rather than only for these six. The census now carries that sentence under all six rules instead of "if statement that is not a
single-statement guard, but a chain of 1 elseif and an else" — which pointed at the `elseif` while the
obstacle was two levels away, and would have sent the next reader to build the dispatch that turned out to be
free.

Both halves are load-bearing, measured by breaking each:

| the transpiler | what the census says under the six rules |
|:--|:--|
| as committed | the dispatch, the kind, and the operand position |
| with the recogniser removed | `if statement that is not a single-statement guard` |
| with `Assignment` off `KINDS_WITHOUT_OPERAND_TYPES` | `if statement that is not a single-statement guard` |

The positive path — narrowing the hook's target list to the arms and binding the operands once — was written,
run, and then removed with the emissions it produced. No rule in the corpus can take it while the operand
type is unanswerable, and machinery nothing exercises is what this repository deletes rather than keeps. What
stayed is the pair of tables the refusal reads and the recognition that fills them in.

**No count moves.** `phpstan-strict-rules` stays at 22 of 45 and the total at 95 of 169. What the step
produced is a measurement that closes a line of work rather than opening one: the six rules are not waiting
on a transpiler feature, they are waiting on mago typing an operand it currently types as a result.

#### A written name, a name shortened, and the wrapper that made a guard chain silent

`NoServiceSameNameSetClassRule` emits, taking `symplify/phpstan-rules` to 56 of 89. Its refusal named the
guard-body shape inside an inlined helper, and three things sat behind it.

**`NamingHelper::getName()` is one navigation, not a helper to inline.** Its body is three returns of three
different expressions — a variable's name, a name's or identifier's text, and null — which the choice
recogniser does not take (that one folds *literals*) and the producer path refuses on the first of them.
`Support::writtenName()` answers it instead, and the null matters: three rules in the package test
`is_string()` on the result and decline when it is not. So it answers null for any other node rather than
falling back to a part's source text, which would turn "not a name" into a name nobody wrote.

**A name shortened to its last segment, written as a branch.** `if (str_contains($name, '\\')) { $name =
Strings::after($name, '\\', -1); }` is the same question `lastNameSegmentHelper()` already answers for a
helper written to ask it. The fold drops the condition, which is sound because `last_name_segment()` returns
the whole string where there is no separator — so the two agree on the case the condition was guarding.

**And the wrapper.** With both of those in, the plugin emitted, loaded, ran, and reported nothing where
PHPStan reported twice. `internal/probe-service-name-guards.php` prints the guards one at a time and names
it: `a0='Access' isCca=true classPartKind=NULL`. Mago wraps a member access in an `Access` category node the
way it wraps a nested call in `Call`, the *predicate* looked through it — `concreteMemberAccess()` — and the
*navigation* did not. So `isClassConstantAccess()` said yes about an argument whose class part then came back
null, and two guards later the rule was silent.

That is the second time the same shape has cost a rule: `Call` was found on
`$routes->import('..')->prefix('/x')`, where the receiver of `prefix` arrives wrapped. The helper is now
`throughTheCategoryWrapper()` and reads a list of the wrappers rather than one kind.

##### What the pair measures, and the one guard it does not

| the plugin | bad-example findings |
|:--|--:|
| as emitted | 2 |
| with `Access` off the wrapper list | 0 |

##### The written name is the wrong name, and the resolved one is wrong for three words

The first version of this read the *written* spelling, and the pair passed: the example wrote short names in
their own namespace, where written and resolved-then-shortened coincide. That agreement hid the fact this
repository has already written down twice — PHPStan resolves names before a rule sees the tree, so
`NamingHelper::getName()` on the class side of `Widget::class` answers `Examples\Wiring\Widget`.

Measured by adding the mixed spelling to the bad example: PHPStan reports
`set(Widget::class, namespace\Widget::class)` as the duplicate it is, and the port comparing written
spellings was silent on it.

Reading the resolved name instead over-reported in the other direction, and the pair caught that within one
run. PHPStan's resolution leaves `self`, `static` and `parent` alone, so the original compares `self` against
`DuplicatedName` and declines — while `resolvedName()` maps the keyword to the enclosing class and made the
port report a duplicate nobody asked about. `nameAfterResolution()` is the faithful reader: the resolved name
for an ordinary one, the keyword itself for those three, and the written name for a subject that is not a
name at all.

| the plugin | bad-example findings |
|:--|:--|
| as emitted | the two short duplicates, and the mixed-spelling one |
| reading the written spelling | the two short ones only — the mixed spelling lost |
| reading the resolved name with no keyword exception | those three, plus `self::class` — which PHPStan does not report |

The fixture needed one more measurement of its own. `\Examples\Wiring\Widget::class` does not survive the
formatter — pint rewrites it back to the short form, and an alias import makes pint rewrite the *other* side
into the alias — so the case is written `namespace\Widget::class`, which resolves the same way and which no
formatter rule shortens.

That is also what makes the last-segment fold measured rather than incidental. The port's value now carries
the namespace, so removing the fold refuses the rule outright — and before the resolution fix, the message it
produced was the short class only because the source happened to write it short.

The kind restriction inside `writtenName()` is *not* exercised by the pair: widening it to answer any part's
source text leaves both findings in place. What it protects is the meaning — `NamingHelper::getName()`
answers null for a node that is not a name, and a rule comparing two of these would otherwise call two
different calls with the same source text equal. The good example holds the shape it is about
(`$containerConfigurator->services()->set(..)`, whose receiver is a call), and both readings decline that
one for the same reason.

##### A shipped exit that was wrong, with no example that can reach it

Reading the emission turned up a defect next door. A binding the port synthesises for a navigation that may
fail — `$arg_value = Support::positionalArgAt(..); if ($arg_value === null) { .. }` — always exited with the
rule's bail, including inside a loop. The original's guard on such a value is `continue`:
`AvoidFeatureSetAttributeInRectorRule` writes `if (! is_string($attributeName)) { continue; }`, so a `return`
there abandons every later call in the same class. One line of one shipped plugin changes,
`return;` to `continue;`.

**No example in the corpus can execute it, and two attempts to write one are why that is stated rather than
assumed.** The binding answers null only where the call has fewer arguments than the index, and a rule
reading `getArgs()[0]` unguarded is a rule that *throws* on such a call: adding `$node->setAttribute();` to
the bad example took PHPStan from 7 findings to none, an internal error rather than a comparison. A spread
argument — `setAttribute(...$spread)` — looked like the agreeing case and is not: both engines decline it and
mago's argument reader unwraps the spread's value fine, so the count stayed 7 with the fix and 7 without it.

So the fix rests on the original's own `continue` rather than on a measurement, and it is marked that way
here. The refinement binding keeps the bail deliberately: that one is only reached where the guard it
replaces exits with one, so what the original does is already known there.

#### A closure filter, carried as the question it asks

`NoConstructorOverrideRule` emits, taking `symplify/phpstan-rules` to 57 of 89. Its two needs were both
things the vocabulary declines by name, and one of them is a shape five rules in the corpus write.

**`fast_has_parent_constructor($scope)`** is three questions in one — the scope is in a class-like, that
class is not anonymous, and its parent declares `__construct`. All three already had readings, so the helper
is a composition rather than new machinery. The anonymous case comes for free: mago models an anonymous class
as its own node kind, so the enclosing-class read answers nothing for one, which is the `false` the original
returns there with a comment saying so.

**A `findFirst()` whose filter is a closure** is the interesting one. `NodeFinder::find()` and `findFirst()`
with a closure were refused by name, and rightly — a closure over php-parser nodes is not something an
emitted plugin can hold. But the *closure* is not what the rule is asking. This one reads

```php
$nodeFinder->findFirst($node->stmts, function (Node $node): bool {
    if (! $node instanceof StaticCall) {
        return false;
    }

    return fast_node_named($node->name, '__construct');
});
```

which is "is there a `parent::__construct()` anywhere in this body". The narrowing guard says which kind to
search for and the comparison says which name, so both are *read* rather than translated, and the emission is
one call: `Support::firstNodeNamed($context, Support::bodyOf($context, $node), ['StaticMethodCall'],
'__construct')`.

The recogniser is deliberately one shape wide — a one-parameter closure with no `use`, a single narrowing
guard whose body is `return false;`, and a final `fast_node_named()` comparison. Everything else refuses with
what it saw, and the census shows that working: `ServicesExcludedDirectoryMustExistRule` moves from
`access path outside the vocabulary: ->find()` to `find() with a closure filter, whose every match the rule
then walks — only findFirst() reduces to one question`. The first named the accessor; the second names why
this rule is not the next one.

##### Both halves measured on the committed pair

| the plugin | findings |
|:--|:--|
| as emitted | the silent override only |
| with the search finding nothing | that, plus the override that *does* call the parent |
| with the parent-constructor test forced true | plus both classes whose parent declares no constructor |

The good examples are the two routes the rule allows and the port has to keep apart: a `parent::__construct()`
call in the body, and a parent that declares no constructor at all — one class with no parent and one
extending a marker.

##### The inheritance the two rules rest on, measured at a distance

Both halves of the helper above assert something about `getDeclaringMethod()`, and the pairs as first written
could not see either. Three cases were added and all three agree with the original:

- **A grandparent's constructor.** `class C extends B`, `B` declaring nothing, `A` declaring `__construct` —
  PHPStan asks the *parent's* reflection and a reflection inherits, so it reports `C`. The port reports it
  too, which is what says the codebase read walks the hierarchy rather than stopping at the direct parent.
- **An override of a method a grandparent declares.** The same question in the other direction, and the
  higher-stakes one: `NoProtectedClassStmtRule` shipped three steps ago and *skips* an override whose parent
  has the method. Both engines skip it. Had the read stopped at the direct parent the port would have
  reported where the original is silent — the wrong direction, in a rule already released.
- **An anonymous class.** The original returns false for one with a comment saying so, and the port answers
  the same way because mago models an anonymous class as its own node kind and the enclosing-class read finds
  nothing. Silent on both sides.

The protected-member case also cost a fixture correction worth recording: the first version of that good
example declared the grandparent's protected method on a *concrete* class, and both engines reported that
declaration — correctly, since it is exactly what the rule is about. A good example that contains a real
violation is not a good example, and the gate said so on the first run.

#### The key was never what stopped it

`DataProviderDeclarationRule` refused with `foreach with a key`, and that sentence would have sent the next
reader to build keyed iteration. The loop is

```php
foreach ($this->dataProviderHelper->getDataProviderMethods($scope, $node, $classReflection)
    as $dataProviderValue => [$reflection, $methodName, $lineNumber]) {
```

and the key is the third thing wrong with it. `getDataProviderMethods()` is a generator that `yield from`s two
other generators — one reading `@dataProvider` annotations, one reading `#[DataProvider]` attributes — and the
second is gated on `$this->PHPUnitVersion->supportsDataProviderAttribute()`, a service the plugin has no
equivalent for. The loop body then hands everything to `processDataProvider()`, which builds the findings.

So the keyed-foreach refusal now resolves the iterable first and lets its own refusal surface. The census
moves from `foreach with a key` to `access path outside the vocabulary: ->getDataProviderMethods()`, which is
still a label rather than a capability — the census header's own warning — but it names the thing that has to
be built rather than the loop that would have been built for nothing.

Nothing else moved: one census line, no emitted byte, and the mutation is the census itself — without the
resolution the message goes straight back to `foreach with a key`.

#### A flag the loop carries, and the filter shape behind it

`NoServiceAutowireDuplicateRule` refused on `if statement that is not a single-statement guard, but 2
statements: Stmt_Expression + Stmt_Continue`. The two statements are

```php
if ($this->hasAutowireDefaultsMethodCall($stmt)) {
    $hasDefaultsAutowire = true;
    continue;
}
```

which is a flag the loop carries: the statement that turns autowiring on is not itself a finding, and every
statement after it is judged differently. The flag machinery already took `if (COND) { $flag = ..; }`; the
`continue` is what made this a different statement, because the rest of the body must not run for that item.

Both halves emit as written — a boolean local and a `continue` are ordinary PHP, and a loop carrying state
across iterations needs nothing from this transpiler beyond not refusing it. The `continue` is still refused
outside a loop, where it would leave the hook rather than the iteration.

**The rule does not emit yet, and the census now says why.** The refusal moves to `a search filter that is
not a narrowing guard followed by one name comparison` — the one-shape limit the closure-filter recogniser
declares about itself. This rule's two filters are both wider: one asks for a call named `autowire` *whose
receiver* is a call named `defaults`, and the other for one with no arguments or a literal `true`.

That is the next thing to build, and it wants the general form rather than two more shapes: bind the
closure's parameter to the found node and translate the rest of its body as a predicate, emitting the search
as a loop with a break. It would serve both filters here and
`ServicesExcludedDirectoryMustExistRule`'s `find()`, which walks every match. Left for its own step, because
it needs statement kinds neither backend has yet.

No emitted byte changes, and one census line moves.

#### The general closure filter, and the emission it refused to make

Last step's closure-filter recogniser carried one shape — a narrowing guard and one name comparison — and
said so in its refusal. The general form replaces it: the first guard is read for the kinds to search, the
closure's parameter is bound to the found node, and the *rest of the body* goes through the same
`predicateFromStatements()` that folds an inlined helper's guard chain. That method was extracted from
`predicateFrom()` for it, and the extraction is byte-neutral across all three targets.

`NoConstructorOverrideRule` now emits the loop rather than a one-shape helper call, and agrees with PHPStan
on the same pair:

```php
$found_0 = null;
foreach (Support::findKind($context, Support::bodyOf($context, $node), ['StaticMethodCall']) as $candidate_0) {
    if ((Support::selectorIs(Support::selector($context, $candidate_0), '__construct'))) {
        $found_0 = $candidate_0;
        break;
    }
}
```

`Support::firstNodeNamed()` went with the shape it existed for. Nothing referenced it once the general form
landed, and an unexercised helper is what this repository deletes.

##### And then it emitted something wrong, twice

`NoServiceAutowireDuplicateRule` reached the end of the chain and emitted. Reading the emission caught two
faults in one shape, both of the kind that parses, loads, runs, and answers about the wrong thing.

**The filter's statements landed outside the loop.** Its second filter binds `$node->getArgs()[0]` off its
own parameter, and the binding was hoisted to the caller's position — where it read `$candidate_0`, the
*first* search's loop variable, left over from a loop that had already finished. Splicing the statements into
the loop body fixed that reading and the numbering hid nothing further: the counter no longer goes back, so
two searches in one rule are `found_0`/`candidate_0` and `found_1`/`candidate_1`.

**And then the position itself was the fault.** The original's filter reads

```php
if (! NamingHelper::isName($node->name, 'autowire')) { return false; }
if ($node->getArgs() === []) { return true; }
$firstArg = $node->getArgs()[0];
```

so a call with *no* arguments is answered `true` before anything reads argument zero. Inside the loop the
binding still runs first, and its own null exit answers for that call — the port went silent on `autowire()`
with no arguments, which is the common case the rule is about. A guard chain folds into an expression, and an
expression has no place to put a statement that must not run yet.

So the splice was written, run, read, and replaced by a refusal: a filter has to fold to one expression, and
one that needs a statement is refused with the statement's kind and the reason its position matters. The
census carries that sentence, and the mutation is the sentence — disabling the check emits the rule again.

The `break` is load-bearing too: without it the loop keeps going and the *last* match wins where the original
takes the first. It is measured by absence rather than by findings, since no example holds two matching
nodes in one subtree — stated here rather than claimed.

`NoConstructorOverrideRule` stays the only rule through this path, so the count does not move: 97 of 169.
What moved is that the next filter shape is a refusal about expression positions rather than about the
recogniser's own narrowness.

#### An array element's key, and four guards the rule never reaches

`NoStringInGetSubscribedEventsRule` emits, taking `symplify/phpstan-rules` to 58 of 89. Three things, and two
of them are about the same trap: a php-parser field's *nullability* changing what a test means.

**A searchable kind for `ArrayItem`.** The rule walks every element of a `getSubscribedEvents()` return.
`ArrayElement` is Mago's category node, and searching for it rather than for the keyed and unkeyed variants
beneath keeps one search where php-parser has one class.

**`->key` is nullable, and that is a different question.** php-parser gives every element an `ArrayItem` with
a `?Expr` key, so `! $arrayItem->key instanceof Expr` asks "is there a key at all". The vocabulary already
had an `instanceof Expr` arm — written for `$node->class instanceof Expr`, where the field is `Name|Expr` and
the question is "is the class dynamic" — and the first emission of this rule went straight through it:

    if (!(! Support::isName(Support::arrayElementKey($context, $array_item)))) { continue; }

which is the *opposite* test. A string key is not a name node, so `! isName` held, the guard passed, and the
rule would have reported... except that the same reading also passes for an element with no key at all. So
the kind now says which field this is — `expr-option`, the way `hint-option` already marks a nullable hint —
and the nullable arm answers `!== null`.

**And the four guards the rule cannot reach.** Its `ClassConstFetch` branch is six statements whose net
effect is `continue`:

```php
if ($arrayItem->key instanceof ClassConstFetch) {
    $classConstFetch = $arrayItem->key;
    if ($classConstFetch->class instanceof Expr) { continue; }
    if ($classConstFetch->class->toString() === SymfonyClass::FORM_EVENTS) { continue; }
    if ($classConstFetch->name instanceof Expr) { continue; }
    if ($classConstFetch->name->toString() === 'class') { continue; }
    continue;
}
```

The trailing bare `continue` is unconditional, so those four tests decide nothing — the rule skips *every*
class-constant key, and the tests read as though it skipped only some. That is an upstream quirk, and the
fold is an exact simplification rather than an approximation: the proof is local, since every statement above
the `continue` either binds a local nothing outside the block reads or is itself a guard whose only body is
`continue`. A statement that could report, assign outside, or leave the rule is not accepted.

##### The pair had to be widened before it could see the difference

Both folds are load-bearing, and finding the second one's evidence took a correction. The bad example — a
`'kernel.request' => 'onRequest'` key — reports identically with the key typed as a plain `expr` or as an
`expr-option`, so it separates nothing. The case that does is the *priority* shape every real subscriber
writes:

```php
AnotherEvent::class => ['onAnother', 10],
```

whose two inner elements have no key, are found by the same search, and are skipped by the original on
`! $arrayItem->key instanceof Expr`.

| the plugin | findings |
|:--|:--|
| as emitted | the string key only |
| with `->key` typed as a plain `expr` | that, plus a false positive on the keyless priority element |
| with the always-continue fold removed | refuses |

The good examples also hold both class-constant spellings — `FormEvents::PRE_SUBMIT`, which the dead guards
name, and two of the project's own — because that pair of cases is what the fold asserts.

#### What the two Rust targets are, measured rather than assumed

A peer session read Mago's source at the pinned tag and settled a question this repository had been carrying
in its table rather than in evidence. The `phpOnly` flag on 33 `Vocabulary::HOOKS` rows was read here as
"Mago's Rust side has its own hook trait for this and nothing in the corpus has pinned down which" — and the
`Trait_` row already recorded one case where that suspicion was wrong. It was wrong more widely than that.

**Eight of the trait names in this table do not exist.** Mago 1.47.4 registers thirteen: `ProgramHook`,
`StatementHook`, `ExpressionHook`, `FunctionCallHook`, `MethodCallHook`, `StaticMethodCallHook`,
`NullSafeMethodCallHook`, `ClassDeclarationHook`, `InterfaceDeclarationHook`, `TraitDeclarationHook`,
`EnumDeclarationHook`, `FunctionDeclarationHook`, `IssueFilterHook`. `ForeachHook`, `PropertyAccessHook`,
`ClosureHook`, `StaticPropertyAccessHook`, `AttributeHook`, `MethodPartialApplicationHook`,
`StaticMethodPartialApplicationHook` and `ClassLikeMemberHook` are inventions of this table. Two more rows —
`NullSafeMethodCallHook` and `ProgramHook` — name traits that *do* exist and are flagged PHP-only anyway,
which is the `Trait_` mistake twice more.

**The hook surface was never the wall.** `after_expression` fires inside `Expression::analyze()` before the
variant dispatch, so one registration sees every expression at every depth, and this repository's own
shipping output has been proving it: `tests/Fixtures/expected-rust/ForbiddenStaticConstFetchRule.rs` is a
non-`phpOnly` `ExpressionHook` that narrows to `Access::ClassConstant`, a variant two levels down. So "one
Rust hook trait registers one kind", written in this table as the reason the multi-kind rows are PHP-only,
is contradicted by the snapshot next to it.

**What the wall actually is.** Mago has two plugin systems. `crates/analyzer/src/plugin/` is the internal
trait registry its four bundled providers use, reached by a compile-time static list, and a rule there ships
only by being compiled into a fork. `crates/analyzer/src/external/` is the supported external API: it
dispatches by `NodeKind` over the extension-host protocol, with per-kind data requirements as bitflags. That
second one is what this tool's PHP target already targets — confirmed from this side, where
`Mago\Sdk\Analyzer\FileAnalysisRequirement` has exactly the six cases the external API names
(`ExpressionTypes`, `TargetExpressionTypes`, `ReceiverType`, `ArgumentTypes`, `TargetSubtree`, `SourceText`),
and an emitted plugin declares `getTargets(): NodeKind[]` beside them.

So the PHP target uses the intended API rather than a fallback, and the two Rust targets aim at a registry
that was never meant to be reached from outside. That explains, with no deficiency on Mago's side, both of
the things this repository could not account for: the emitted Rust calls a `support` module nobody has
written, and the Rust tiers emit no install path where the PHP tier emits a `mago.toml` snippet. One run,
same rules, `--out` side by side:

    php/      generated/  generated-php/  mago.toml.snippet  worker.php
    analyzer/ generated/

The README now says which target installs. What has *not* been decided is whether a Rust tier should exist
at all: since `external/` dispatches by `NodeKind` — the same model the PHP target uses — a Rust tier behind
a fork would be a second implementation of the same thing, and that is a question for the maintainer rather
than a fact to record.

Nothing was raised upstream, and nothing should be: the ask that looked warranted for a day would have
requested an API that already exists.

#### Half a Doctrine check, and the half that is missing is stated

`NoEntityMockingRule` emits, taking `symplify/phpstan-rules` to 59 of 89. It refused on
`->getAttributes()`, which named the accessor rather than the problem: the rule delegates to
`DoctrineEntityDocumentAnalyser::isEntityClass()`, and that helper asks two questions of a class.

**One is exact.** Its `ENTITY_ATTRIBUTES` are `Doctrine\ORM\Mapping\Entity` and
`Doctrine\ODM\MongoDB\Mapping\Annotations\Document`, and mago's `ClassLikeMetadata->attributes` carries a
resolved name per attribute. So the attribute half is a metadata read.

**The other cannot be asked.** The helper also looks for `@Entity`, `@ORM\Entity`, `@Document` or
`@ORM\Document` in the class's docblock, and `ClassLikeMetadata` carries no docblock text — read field by
field, it holds flags, hierarchy, members, attributes and template information, and nothing the marker could
be found in. So `Runtime\DoctrineEntities` ports the attribute half and says which half it is not.

The divergence is an *under-report*: an entity mapped by annotation is invisible to the port, so the rule
answers no and the finding is not made. That is the direction this repository picks when one must be picked,
and the pair therefore holds no annotation-mapped entity — a pair asserts agreement, and that case is one
where the two disagree by construction. Attribute mapping has been Doctrine's documented default since ORM
2.9, so the missed population is the older one; nothing here measures how large it is.

##### Measured on the pair

| the plugin | findings |
|:--|:--|
| as emitted | the mocked entity only |
| with every known class treated as an entity | that, plus the mocked service — a false positive on a good example |

The good example also mocks a name the codebase does not know, which the rule skips on `hasClass()` before
it asks anything about attributes.

One thing worth noting about the port's shape: this is the fourth collaborator carried as a runtime port
rather than translated — after `RuleLevel`, `RectorAutoloadedTypes` and `PhpUnitAnnotations` — and all four
have the same reason. The helper's body reads something PHPStan exposes and Mago either models differently
or does not model at all, so a statement-by-statement translation would refuse on its first line while the
*question* it asks is answerable. The table of a package's own constants comes with the port in each case,
which is the exception to reading such literals out of the rule's own source.

#### An accessor's name over a permanent answer, and the blunt version of the fix

`ForbiddenFuncCallRule` refused with `access path outside the vocabulary: ->normalizeConfig()`, which reads as
a to-do for this transpiler. It is not one. The call is

```php
$requiredWithMessages = $this->requiredWithMessageFormatter->normalizeConfig($this->forbiddenFunctions);
```

and `$forbiddenFunctions` is a constructor parameter the package's auto-included neon never wires. Measured:
`symplify/phpstan-rules`' `composer.json` names four files under `extra.phpstan.includes` —
`services/services.neon`, `ctor-rules.neon`, `mock-rules.neon`, `phpstan-extensions.neon` — and the two
configs that *do* register this rule with a list of forbidden functions, `configurable-rules.neon` and
`rector-rules.neon`, are in neither. They are opt-in, which is what `--from-config` exists for.

So the refusal now surfaces the wiring rather than the accessor, and it is a permanent answer about the
package rather than a gap here: nothing in this transpiler can supply a value the package does not ship.

##### The blunt version was written first, and three rules got worse

Resolving *every* argument before refusing seemed like the same reordering a keyed `foreach` already makes
for what it iterates. It is not, because the deeper refusal is not automatically the better one:

| rule | before | with every argument resolved |
|:--|:--|:--|
| `ForbiddenFuncCallRule` | `->normalizeConfig()` | the wiring answer |
| `NoSetClassServiceDuplicationRule` | `Strings::match()` | `Scalar_String` |
| `ClassDependencyTreeRule` | `ParametersAcceptorSelector::selectFromArgs()` | `unknown local $scope` |
| `DataProviderDeclarationRule`'s second need | `->find()` | `unknown local $resolvedPhpDocBlock` |

`unknown local $scope` names this transpiler's state and no obstacle at all, which is the failure the
census's own header warns about from the other direction. So the reordering is now conditional on the
argument reading a property from one of three named sets — wired to an undeclared container parameter,
computed in the constructor from outside the pure set, or not wired at all. Those three are facts about the
package; everything else keeps the accessor.

The check asks the property sets rather than matching the refusal's text, so a reworded message cannot
silently stop matching. Exactly one census line moves, and the mutation is that line: without the condition,
the four rows above move together.

### The survey reads what a refusing statement encloses

The validation pass this closes a gap in already existed: survey mode, `PackageCoverage::needs()`, and the
`needs:` lines under every refused census entry. What it never did was look *inside* the statement that
refused, so a rule whose whole body sits in one `if` or one `foreach` contributed one entry — the shape of
the wrapper — and nothing about the work inside it.

Measured before building: **28 of 80 refused rules said only what their emit run already said.** Twenty-six
had a `needs:` block that was the refusal echoed back, and two had none. That is the survey reporting that it
ran rather than what it found, and it is a third of the corpus it exists to size.

The descent is one loop: when a statement refuses, its enclosed statements are read in its place. A clause
that holds a body rather than being a statement — `else`, `elseif`, `catch`, `finally`, a `switch` case, all
five `Stmt` subclasses in php-parser — is descended through instead of handed over, because translating one
would add `statement outside the vocabulary: Stmt_Else` to every branching rule: a line about php-parser's
class hierarchy, not about the rule.

**After: 21 of 80.** Nine rules gained obstacles they had never reported, the five `OperandsInArithmetic*`
rules among them — each of which used to describe a family of one obstacle that measurement had already shown
to be six. Eighty-six needs lines are new across the census, and no line is lost: the one that moves is
`collector returns something other than a list of values` under `NewWithFollowingSettersCollector`, reordered
because five obstacles now precede it.

Two of the 21 arrived there rather than starting there: the widened `unknown local $` filter below took away
their only extra line. That is the filter working, not a regression.

#### Two artefacts of stepping over, filtered by mechanism rather than by wording

The descent translates statements out of the position they were written in, so some of what it produces is
about the descent and not about the rule:

| artefact | why it is not a need | lines it added |
|:--|:--|--:|
| `unknown local $x` nested one label deep | a skipped assignment left the name unbound; the existing filter was anchored to the start of the line and missed `assignment value outside the vocabulary: unknown local $stmt` | 19 |
| `continue outside a loop` and its two variants | `inLoop` is false only because the enclosing `foreach` refused at its iterable — a `continue` outside a loop is a fatal error in PHP, so no rule holds one | 28 |

The second is measured, not argued: the phrase appears **zero** times in the census this descent was added
to and 28 times in the one it produced. The first widening also removes six lines that were already noise by
the existing filter's own stated rationale, `unknown local $this` among them.

Left alone deliberately: `$errorMessage is not a message built in this rule`, which reads like the same class
of artefact and is not — it appears five times in the pre-descent census, so it is an existing need with an
existing meaning.

#### Mutation checks

Three folds, three mutations, each restored from a copy rather than with `git checkout --`:

| mutation | census effect |
|:--|:--|
| descend into no statements (`foreach ([] as $nested)`) | 86 needs lines disappear |
| drop the `outside a loop` filter | 28 artefact lines return |
| re-anchor `unknown local $` to `str_starts_with` | 19 artefact lines return |

`TracksUpstreamDriftTest` failed on each and passes with all three in place.

#### What still does not appear, and why the bound is still a bound

A second obstacle inside a single **expression**. An expression has no position to resume from — the
statement around it needs a value — so the first one still stops the walk. Three prose sites said the old
bound and now say this one: the census header (fixed in its generator, never in the file), the
`Transpiler::$collectNeeds` docblock, and `PackageCoverage::needs()`.

What is in the remaining 21 has not been read rule by rule, and the census says so rather than guessing: some
refuse inside one expression, and some — `NewOverSettersRule` for one — refuse before any statement is
reached at all.

> **Superseded.** Counted rather than guessed, and the shape was right: 12 of them refuse before the body
> is reachable and 8 inside it. See *"The twenty the survey adds nothing to, counted"* below.

#### Verification

Emit-all across `php`, `analyzer` and `linter` over the four corpus packages plus `tests/Fixtures/Rules`:
188 files, `diff -r` clean apart from the `--out` path the `mago.toml` snippet embeds. Suite 922/922. PHPStan
0 errors with no new baseline entry — `Transpiler` moves from 182 to 192, which is the class the guidelines
name as growing with coverage.

One PHPStan error was fixed rather than baselined: `bodyOf()` first read sub-nodes as `$node->{$name}`, a
variable property access. It reads `get_object_vars($node)` now, because php-parser publishes the sub-node
names and nothing that reads one by name. That the two agree was probed rather than assumed — over 23 nodes
covering every branching shape, `getSubNodeNames()` returns exactly the public properties that are not
`attributes`, with no extras and none missing.

No count moves. The census gets 86 more lines about the same 80 refusals.

### A foreach's key and value, and the half of a rule that is not the obstacle

`OverwriteVariablesWithForeachRule` refused with `no mapping for ->keyVar on a hook-node`, and after the
descent shipped above it named `->valueVar` as well. Both are the same capability, and neither is why the rule
cannot be ported.

**Two things were checked before building, and one of them contradicted what had already been said out loud.**

The rule's other question is `$scope->hasVariableType($name)->yes()`. `Translator` handles it — it sets
`readsPriorScope` so the rule runs on the pre hook and calls `variable_is_undefined`. That reads as solved,
and it was reported as solved. It is not, for the target every count uses: the emitted PHP refused with

    operand is still Rust and has no PHP rendering yet:
    !(!(support::variable_is_undefined(context, support::direct_variable_name(/* PHP target only */)...)))

so the query renders for `analyzer` and `linter` and not for `php`. Reading the mapping said the opposite of
what running it said, which is the reachability trap CLAUDE.md names: the method answers, and the answer is
about a different target.

The second is the self-recursive helper. `checkValueVar()` calls itself for each item of a `list()` target,
and `Translator::enterInline()` refuses a helper that reaches its own name. So the rule needs three things
this transpiler does not have, of which the census named one.

#### The CST, probed rather than assumed

`internal/probe-foreach-target.php` dumps the children of every `Foreach` over the three shapes a loop is
written in. The structure is exact and it maps onto php-parser's nullable `keyVar` without approximating:

| written | `ForeachTarget` holds | expressions under it |
|:--|:--|:--|
| `as $v` | `ForeachValueTarget` | one — the value |
| `as $k => $v` | `ForeachKeyValueTarget` | two — key first, value second |
| `as [$a, $b]` | `ForeachValueTarget` | one — the array, which is what php-parser answers too |

So "does this loop bind a key" is the target's own kind, the same shape `Calls::arrayElementKey()` already
reads one level up. The first version of this probe printed nothing at the level that mattered and the
conclusion drawn from it — that the targets have no children — was wrong; the edit that added the level had
silently failed to apply. It was caught by checking that the probe contained the code whose output was being
read, which is worth doing every time a probe answers "nothing".

#### What the navigation moves, and what it does not

Exactly one census line. `OverwriteVariablesWithForeachRule` stops saying `no mapping for ->keyVar` and starts
saying `guard body is neither `return []` nor `continue`, but Stmt_Foreach` — the destructuring branch, which
is the first of the three real obstacles rather than the one that was never an obstacle. **No rule emits, and
no count moves.**

That leaves runtime code no corpus rule exercises, which is how a helper ships wrong. So the two halves are
measured by two fixture rules with example pairs, and the fires gate runs each against real PHPStan:
`ForeachKeyOverwritesRule` reports every keyed loop, `ForeachValueDestructuresRule` every destructured value.
Both examples carry a keyed *and* an unkeyed case on purpose — a port reading a fixed child position answers
the key for a keyed loop, and the key is a plain variable, so a pair built only from unkeyed loops would agree
either way.

#### Mutation checks

| mutation | what the fires gate said |
|:--|:--|
| `foreachValue` reads expression 0 on a keyed target | `Bad.php` line 26 goes silent — the key sits there and it is a plain variable |
| `foreachKey` accepts a `ForeachValueTarget` as well | `Good.php` line 23 is reported — the destructuring loop has children, and a port asking "are there children" rather than "which kind" reports it |

Both are the failure the example pairs were built to catch, and both restored from a copy.

#### Verification

`Runtime\Calls` reached 87 against a limit of 80, so the three methods moved to `Runtime\Loops`, split on the
call graph: they reach `Calls::nthExpression()` and nothing reaches back. `Support` keeps the shipped names,
and the emit-all diff across all three targets before and after the split is empty — the facade result the
guidelines describe, measured again rather than assumed.

Suite 930/930, up from 922 — eight of those are the two new pairs. PHPStan 0 errors with no new baseline entry. Emit-all adds the two
fixture rules to the `php` manifest and changes no existing generated file; both refuse for `analyzer` and
`linter`, which is correct — the fields are PHP-only.

### A closure does not end the enclosing function, and the port thought it did

`Runtime\Declares::enclosingFunctionName()` stopped its walk at a `Closure` or `ArrowFunction` and answered
null, with a docblock saying that is what PHPStan answers too. It is not. Two lines of
`MutatingScope` settle it:

    public function getFunctionName(): ?string
    {
        return $this->function !== null ? $this->function->getName() : null;
    }

and `enterAnonymousFunction()`, which builds the closure's scope by handing `$scope->getFunction()` straight
through. So a closure *inherits* the enclosing function rather than replacing it, and
`getFunctionName()` inside one still answers the method around it.

`NoDynamicNameRule` is the shipped rule that pays for the difference: it exempts a dynamic name whose
enclosing function is `__get` or `__set`, so a dynamic name written inside a closure inside `__get` is quiet
in PHPStan and was reported by the port. Measured, not argued — the fires gate on the pair, with the closure
as the only change:

    'GoodMagicAccessorIsExempt.php' => [ 0 => '19: Use explicit names over dynamic ones' ]

against a PHPStan side that reports nothing in that file. Removing the two-line early return makes the gate
green.

#### The first two attempts to reproduce it were invalid, and a control is what said so

The first test put `$this->$name` in a *non-exempt* method of the good file and read the resulting failure as
the closure bug. It was not: that line is a real violation, so the good example simply stopped being good.
The port-only assertion (`stays silent on the good example`) fails either way, which is exactly why it cannot
be read as evidence about a cause.

The control that caught it was moving the same closure into a plainly non-exempt method and expecting PHPStan
to *report*. PHPStan stayed silent, which is impossible if the exemption were doing the work — and impossible
for the reason first assumed. Stripping back to one change at a time, with nothing added to the good file but
the closure, produced the run quoted above, where the PHPStan side is empty and the port's line is the only
entry.

Two attempts, two wrong causes, and the same lesson each time: a failing test is evidence that something is
wrong, never evidence of what.

#### How the walk reached the wrong shape

The docblock asserted PHPStan's behaviour rather than citing it, and the assertion was plausible — a closure
*is* anonymous, and `getFunctionName()` sounds like it should answer about the nearest function-like. The
question the name invites and the question the field answers are different, and only reading
`enterAnonymousFunction()` separates them. Reachability again: `getFunctionName()` exists and answers, and
what it answers about is a second claim.

#### Verification

Fires gate 564/564 with the new case in the pair. Suite green. PHPStan 0 errors. Emit-all across all three
targets unchanged — the fix is runtime, and no emitted byte reads differently for it. No census line moves and
no count moves; what moves is one shipped rule agreeing with PHPStan where it did not.

### A refusal that names the method but not who it was called on

Two ticks in a row picked a rule to port from the census label `access path outside the vocabulary:
->getFunction()`, and both times the label pointed at the wrong call. `$scope->getFunction()` — the function a
node sits in — is mapped and has been for a while. `$this->reflectionProvider->getFunction($name, $scope)` —
which resolves a function the code *names* — is not, and is what all five refusing rules write. The two share
their spelling from `->` onward and share no capability at all.

The census header already warns that a shared outer phrase is not a shared capability. This is the same
failure one level in: the inner text is identical and the receiver is the whole difference.

So `describe()` now names the receiver for a method call, and `noIterationRefusal()`'s docblock is the
precedent — that message got a discriminator for exactly this reason, one label over.

#### Only two receivers, because naming every local made it worse

The first version named any local. It split `->getLine()` four ways — `$classConst`, `$param`, `$property`,
`$node` — which is one capability under four names the rule author happened to pick. A label has to be
comparable *across* rules, and a local's name is the one part of a call site that is not.

The version shipped names a property of `$this`, which is the rule's own collaborator, and the two variables
every `processNode()` receives:

| receivers named | distinct labels over ~108 occurrences | what split |
|:--|--:|:--|
| none (before) | 52 | — |
| any local | 61 | including `->getLine()` four ways and `->generalize()` three, all by local name |
| `$this->x`, `$scope`, `$node` | 55 | `->getMethodReflection()`, `->getResolvedPhpDoc()`, `->getLine()` |

Each of the three surviving splits is two different questions that had one name: a PHPStan node's own method
reflection against the scope's enclosing one, a class reflection's docblock against a `fileTypeMapper`
lookup, and a node's line against another node's.

#### Mutation checks

Dropping the named-variable arm moves 15 census lines; dropping the collaborator arm moves 23.
`TracksUpstreamDriftTest` fails on each and passes with both.

#### Verification

Every census line the change touches is relabelled rather than lost — the two that read as removals,
`no iteration mapped for ->getNodes()`, are the same entry now reading `$node->getNodes()`. Occurrences go
from 108 to 109 because one entry that used to deduplicate against an identically-spelled other no longer
does, which is the whole point.

Suite green. PHPStan 0 errors; `Translator` moves from 2331 to 2335 with no new baseline entry. Emit-all
unchanged across all three targets — a refusal's text reaches no emitted file.

### The same closure bug, a second time, in the class next door

The walk fixed in `Declares` last commit had a sibling. `Deprecations::scopeIsDeprecated()` listed `Closure`
and `ArrowFunction` among the kinds `$scope->getFunction()` can answer with, and its comment carried the same
false claim: that PHPStan answers null inside a closure too.

`DefaultDeprecatedScopeResolver` is four lines and settles it — the third of its three questions is

    $function = $scope->getFunction();
    if ($function !== null && $function->isDeprecated()->yes()) {

and `enterAnonymousFunction()` passes that function straight through. So a closure written in a `@deprecated`
method is a deprecated scope, and every rule in `phpstan-deprecation-rules` opens with that check.

The direction is the unsafe one. The port read the scope as undeprecated and **reported where PHPStan is
quiet**, which is the failure mode the class's own header docblock says it exists to prevent.

Reproduced with the closure as the only change to the good example:

    'GoodDeprecatedConst.php' => [ 0 => '43: Use of constant FILTER_SANITIZE_STRING is deprecated.' ]

against an empty PHPStan side. Dropping the two kinds from `FUNCTION_LIKE_KINDS` makes the gate green.

#### Whether there is a third

Swept every `Closure`/`ArrowFunction` mention in the runtime rather than assuming two was the count. Four
others exist and none is about the scope's function: `CognitiveComplexity::NESTING` counts a closure as
nesting, which is what the rule it ports does; `DeclaredParameters::FUNCTION_LIKES` counts the parameters of
every function-like, closures included, which is what type-coverage measures; `FormRequestRules` searches for
closures; and `Members` reads one's body. `Declares` and `Deprecations` were the two, and the sweep is why
that is a count rather than a guess.

#### Why it happened twice

Both sites asserted PHPStan's behaviour in a comment instead of citing it, and the assertion is the natural
reading of the method name: a closure is anonymous, so an accessor called `getFunction()` sounds like it
should answer about the closure. Nothing short of `enterAnonymousFunction()` separates the question the name
invites from the one the field answers, and neither docblock had been there.

#### Verification

Fires gate 564/564 with the new case in the pair. Suite green. PHPStan 0 errors. Emit-all unchanged across
all three targets. No census line moves and no count moves; one more shipped rule family agrees with PHPStan
where it did not.

### Arguments are read as written, and that is now pinned rather than assumed

Three rules refuse because they call `ParametersAcceptorSelector::selectFromArgs()` and
`ArgumentsNormalizer::reorderFuncArguments()` — they read arguments in *declared* order, so a named argument
lands where its parameter sits. Sizing that led to a claim about every other rule: that a rule which does not
normalize reads arguments as written, PHPStan hands them back as written, and the port reading the written
order therefore agrees. That claim was reasoned, not measured, and two of this session's bugs came from
exactly that.

Measured now. `NoArrayMapWithArrayCallableRule` reads `$node->getArgs()[0]->value` and asks whether it is an
array literal. Written `array_map(array: $values, callback: [$this, 'twice'])`, argument zero is `array:
$values`, so the rule stays quiet even though the call does pass an array callable — and the port is quiet
too. Both silent, which on its own is worth nothing.

So the case was mutated rather than trusted. Reversing the list in `Calls::argumentAt()` makes the port report
the new line and stop reporting the bad example:

    - 'Bad.php'  => [ 0 => '16: Avoid using array callables in array_map() ...' ]
    + 'Good.php' => [ 0 => '47: Avoid using array callables in array_map() ...' ]

The case is live, and it now pins written order for every rule that does not normalize. If argument reading
is ever made order-aware by default, this pair fails — which is the point of putting it in a good example
rather than in a comment.

`PositionalFlagRule`'s pair already carried named arguments, but for its own guard rather than for the
generic reader; this is the first case that holds `Calls::argumentAt()` itself to the written order.

#### The normalizing three are buildable, which was the open question

Probed rather than assumed, because a helper that cannot resolve a parameter name is no helper:
`$context->codebase->getFunction()` carries parameter names for internal functions as well as user-declared
ones, and the lookup folds case.

    array_keys            3 params: $array, $filter_value, $strict
    in_array              3 params: $needle, $haystack, $strict
    str_replace           4 params: $search, $replace, $subject, $count
    ParamsProbe\localFn   3 params: $needle, $haystack, $strict
    paramsprobe\localfn   3 params: $needle, $haystack, $strict

So the reordering is reachable. What is not yet designed is the translator side: the rules bind the function
reflection to a local and ask it two things — `->getName()` and `->getVariants()` — and the site that maps
`$scope->getFunction()` says in its own comment that it avoided a handle "with two arms and no third question
behind them". Here there are two arms, so that is a design decision rather than a mapping, and it is not made
in this commit.

#### Verification

Fires gate 564/564. Suite green. PHPStan 0 errors. Emit-all unchanged across all three targets. No census line
moves and no count moves.

### The argument normalizer is buildable and should not be built

Last commit left a design decision open: the three rules that call `ParametersAcceptorSelector::selectFromArgs()`
and `ArgumentsNormalizer::reorderFuncArguments()` bind a function reflection to a local and ask it two things,
`->getName()` and `->getVariants()`, and the site that maps `$scope->getFunction()` says in its own comment
that it avoided a handle "with two arms and no third question behind them". This is that decision, made
against the census rather than against the shape of the code.

**The two arms turn out not to be the problem.** `$function->getVariants()` never escapes the idiom — it is
passed to `selectFromArgs()`, whose result is passed to `reorderFuncArguments()` and nowhere else. So the pair
collapses to one operation, "arguments in declared order", and the runtime needs only the callee's name, which
`Names` already resolves. No handle is required.

**The algorithm is portable and the null return is nearly unreachable.** Read rather than guessed:

- no named arguments at all → `array_values($callArgs)`, and `reorderFuncArguments()` then returns the *same*
  `FuncCall` object. The normalizer is the identity for every call written positionally, which is almost all
  of them.
- otherwise each named argument moves to its parameter's index, positional arguments keep their written index,
  and a name the signature does not declare is appended.
- `null` comes back only when a variadic parameter is followed by another parameter, which PHP's own grammar
  forbids. It is defensive code in valid PHP, the same shape as the sweep `PositionalFlagRule`'s pair
  documents as unexercisable.

So the work is real, bounded, and reachable — mago carries the parameter names, as the previous commit
measured.

**And building it moves nothing.** The three rules that would use it need, in total:

| rule | distinct needs | the normalizer is |
|:--|--:|:--|
| `ClassDependencyTreeRule` | 4 | one of four, behind a cross-file constructor lookup |
| `StrictFunctionCallsRule` | 8 | one of eight |
| `ArrayFilterStrictRule` | 16 | one of sixteen |

Every one of the others is a separate capability — union walking, `->toBoolean()`, `->getIterableValueType()`,
`$scope->getNativeType()`, a `break` statement, `array_key_exists()`. Closing the normalizer leaves all three
refusing, and leaves a subsystem in the runtime that no emitted rule reaches. That is the trap the foreach
navigation hit two commits ago at one method's scale; here it would be at a subsystem's.

The need-lists above exist because of the descent added earlier in this session. Before it, `ArrayFilterStrictRule`
reported one need and this decision would have been made on the belief that the normalizer was most of the job.

**Decision: not built.** Revisit if a rule appears whose only remaining need is declared-order arguments. Until
then the normalizer is a correctly-sized piece of work with nothing behind it.

### Auditing the claims the runtime makes about PHPStan

Two commits found the same bug in two classes, and both were a docblock asserting PHPStan's behaviour instead
of citing it. So the runtime was swept for every such assertion rather than waiting for a third to surface.

Nine sites claim what PHPStan answers. Four say "measured" and carry their evidence. The other five were
checked here.

**One was stale, and it is the shipped surface.** `Support::enclosingFunctionName()` still carried the exact
sentence proven false — "a node inside one has no enclosing *name* ... which is what PHPStan answers too" —
because the fix went into `Declares` and the facade delegates. The behaviour was right and the documentation
of it was wrong, on the class every emitted plugin reads. Fixed to cite `Declares` rather than repeat a claim.

**Two hold.** `Types::typeIsBoolean()` says PHPStan answers `yes` only for a wholly boolean type. Traced:
`UnionType::isBoolean()` goes through `notBenevolentUnionResults` to `TrinaryLogic::lazyExtremeIdentity`,
which returns `maybe` when members disagree — so `bool|null` is `maybe`, and `maybe->yes()` is false. The
runtime's "every atomic is boolean" matches on all three outcomes. `Support::classExists()`'s claim is about
coverage rather than about an API, and is already hedged as such.

**One turned up a trap worth naming.** `getDeclaringMethod()` does answer over the hierarchy, as claimed —
probed, not assumed. What the probe also showed is that its neighbour does not, and silently:

    Child::ownMethod       getDeclaringMethod found   getMethod found
    Child::fromBase        getDeclaringMethod found   getMethod null
    Child::fromTrait       getDeclaringMethod found   getMethod null
    Helper::fromTrait      getDeclaringMethod found   getMethod found

Both runtime calls to `getMethod()` are correct, and the last row is why: each reads a method declaration the
hook is sitting on, and for a method written in a trait the enclosing class-like *is* the trait, which
declares it. That was the case worth probing — this codebase's trait handling has diverged before — and it
holds. The distinction is now written on `declaringClassOfMethod()`, because a third call site asking "does
this class have this method" would answer null for every inherited method and report nothing.

#### Verification

No behaviour changes: two docblocks and one probe. Suite green, PHPStan 0 errors, emit-all unchanged across
all three targets. No census line moves and no count moves.

### What is actually left, measured instead of characterised

Twice in this session the remaining work was described as "a type-system tier" — one coherent piece to attack
deliberately. That was a characterisation from the rules read most recently, not a count. Counted, it is
wrong, and the shape of what remains is different enough to change what to do next.

**269 needs entries across 80 refused rules, 170 of them distinct. 129 appear exactly once.**

Six of the 80 are the `OperandsInArithmetic*` family, which was built to emission and withdrawn on
measurement — mago types operand 1 of a compound assignment as the value the expression produces, and on
12125 real files the division rule made zero agreements. Counting them inflates every cluster they sit in, so
the table below excludes them. That exclusion is the point of the row: `a second identifier before the first
was reported` reads as an 8-rule cluster and is a 2-rule cluster, because six of the eight are that family.

| need | rules (excl. withdrawn) |
|:--|--:|
| guard body is neither `return []` nor `continue`, but `Stmt_Expression` | 10 |
| `$errorMessage` is not a message built in this rule | 7 |
| statement outside the vocabulary: `Stmt_Expression` | 6 |
| guard body is neither `return []` nor `continue`, but `Stmt_Return` | 6 |
| collector returns something other than a list of values | 4 |
| `array_merge()`, `Expr_Ternary`, `->getType()`, a 2-statement `if` | 4 each |

Not one of them is a type-system capability. The largest is a statement shape this transpiler already handles
in three other positions — the four `*TypeDeclarationCollector` rules hit it on `if ($param->variadic) {
--$paramCount; continue; }`, an accumulator adjusted before the `continue`.

**And no cluster is a lever.** Every one of the ten rules behind the largest need has at least four distinct
needs of its own:

    RequireParentConstructCallRule    4      ConstantTypeDeclarationCollector   7
    WrongCaseOfInheritedMethodRule    4      ParamTypeDeclarationCollector      7
    AssertEqualsIsDiscouragedRule     4      ReturnTypeDeclarationCollector     7
    PropertyTypeDeclarationCollector  5      NewWithFollowingSettersCollector  11
    NoReferenceRule                   6      ArrayFilterStrictRule             15

The clusters also overlap rather than stack: three of the four rules in `collector returns something other
than a list of values` are also in the largest row, so closing both moves the same three rules partway.

So the honest position is that there is no next lever. Coverage past 99 of 169 is many small capabilities,
most serving one rule, and the sizing question is no longer "which cluster first" but whether the corpus is
worth that at all. This measurement is here so the question gets asked with the numbers rather than with an
impression — including the impression this same session offered twice.

### A differential over real code, and the one disagreement in 112 it found

The example pairs are authored by the person who wants them to pass, so the guidelines call a green run over
them the weakest evidence available. The corpus differential is the answer to that, and it had not been run
since the closure fixes. Run against `nikic/php-parser` — 270 files, 80 emitted rules, 83 identifiers:

    total: agree 1692, only-original 1, only-port 410

Most of the 410 is configuration, not divergence. `complexity.classLike` and `complexity.functionLike`
account for 34 of them and the report says so itself, printing the two messages side by side: *keep it under
80* against *keep it under 40*. The emitted plugin carries the package default and this repository's own neon
sets a higher one. `typeCoverage.constantTypeCoverage` is the other 375, at 0 agreements — a threshold, not a
finding. Neither is a bug, and both are what `--parameter=` exists to pin.

**One line in the 410 is a real defect**, and it is the kind only real code writes:

    symplify.noConstructorOverride   agree 111  only-original 0  only-port 1
        only-port  vendor/nikic/php-parser/lib/PhpParser/Internal/TokenPolyfill.php:42

`TokenPolyfill.php` declares its class **twice**. The first, under `if (\PHP_VERSION_ID >= 80000)`, extends
`\PhpToken`; the file then `return`s, and a second declaration extending nothing follows with the constructor
at line 42.

PHPStan asks the *scope* for the class the node is in, which is the second, so there is no parent and no
override. `Reflect::parentHasConstructor()` asked the codebase for the *name*, got whichever declaration the
metadata kept — the first — and reported a constructor that overrides nothing. A false positive, which is the
unsafe direction.

The fix asks the declaration before the name: `Inheritance::hasExtends()` reads the enclosing class-like's own
`extends` off the tree. It is a narrowing guard only — where a name has one declaration the two answers agree,
and where they differ the node is the one PHPStan is looking at.

`Reflect`'s class docblock said "nothing here reads the CST", so it now names this method as the exception and
why: the grouping's distinction is between what a *name* resolves to and what the analysed node is, and this
is that distinction one level out.

#### The other needle was not one

`typeCoverage.paramTypeCoverage` reported 1 only-original against 1053 agreements, in the same file at line
69 — `public function is($kind): bool`, whose only type is a docblock. The totals differ by 7 possible and 6
typed, so the two engines disagree about a handful of params across the corpus rather than about that one.
`Vocabulary`'s type-coverage note records exact agreement on two Laravel consumers, so this corpus is new
information and it is not the same defect. Left open rather than folded into this commit.

#### Verification

Reproduced in a fixture pair before the fix — the port reported the good example, PHPStan reported nothing —
and the mutation is that guard: without it, `GoodTwoDeclarationsOfOneName.php` is reported again. Fires gate
564/564, suite green, PHPStan 0 errors, emit-all unchanged across all three targets. No census line moves and
no count moves.

### The second needle was documented, and the documentation had the cause wrong

Last commit left `paramTypeCoverage` open as "a different defect ... new information". It was neither.
`Vocabulary::ACCEPTED_DIVERGENCE` already carried it, with the number this differential re-derived: *"a class
declared twice in one file behind a version guard is counted by PHPStan and by neither body here, which is -7
on nikic/php-parser"*. A control for the exact shape has existed under `conditionally-redeclared`, and a test
asserted the divergence rather than an agreement.

So the finding was not the -7. It was that the stated **cause** was wrong, and the wrong cause is what made it
look unportable.

**What the test said:** the port reads metadata keyed by class name, gets one entry, and counts neither body.

**What a probe says:** the CST holds both declarations and both method bodies, and the walk reaches them. The
metadata for the name holds `parent='phptoken'` and *no methods at all*.

    CLASS node: class Polyfill extends \PhpToken {}
    CLASS node: class Polyfill {     public function __construct(public int $id, publi
      METHOD node: public function __construct(public int $id, public string $t
      METHOD node: public function is(mixed $kind): bool ...

    metadata: parent='phptoken' methods=

The bodies are read. What discards them is the LSP guard: `ancestorsOf()` asked the codebase for the *name*,
the metadata for a twice-declared name keeps one entry — here the first — and every method the second body
declares that `PhpToken` also declares then read as locked by an ancestor and was skipped.

#### The fix was already in the file

`ancestorsOf()` had two branches: metadata by name, and — for an anonymous class, which has no name — the
declaration's own `extends` and `implements` read off the tree with each named ancestor's ancestry folded in
from metadata. The second branch is correct for both. The clauses belong to the declaration; the name does
not.

Deleting the named branch makes the control count 3 against PHPStan's 3, and the other 16 parameter controls
are unchanged. On the corpus:

| | before | after |
|:--|:--|:--|
| `symplify.noConstructorOverride` | agree 111, only-port 1 | agree 111, only-port 0 |
| `typeCoverage.paramTypeCoverage` | agree 1053, only-original 1 | agree 1054, only-original 0 |
| totals in the message | 2752 possible / 721 typed against 2745 / 715 | 2752 / 721 on both sides |

`only-original` across the whole run is now 0. The remaining 409 `only-port` is the configuration difference
the previous commit named — the complexity thresholds and the constant-coverage minimum — and the one
surviving `paramTypeCoverage` message difference is the configured minimum, *over 100 %* against *over 99 %*,
with identical counts either side of it.

Both defects the differential found on this corpus have the same shape, which is worth saying once: a name is
not a declaration, and the codebase is keyed by the first.

#### Verification

`ACCEPTED_DIVERGENCE`'s note is carried into the emitted plugin, so the emit-all diff is that comment and
nothing else — no emitted code changes. The reviewed snapshot under `tests/Fixtures/aggregate` holds that
plugin and failed on the wording, which is the check doing its job; it is updated because the new sentence
describes what the code now does and the old one described a defect that is gone. The 0.0111 ceiling stays: it covers the over-count from PHPStan's
reflection extensions, which is a separate and genuinely unportable cause. 39 counting controls pass, suite
green, PHPStan 0 errors.

### A second corpus, and the one divergence a plugin cannot close

`nikic/php-parser` came out clean after the last two commits, so the differential ran over
`rector/rector/src` — 489 files, and a corpus whose own code the `rector.*` rules were written for.

    total: agree 246, only-original 1, only-port 657

The 657 is the configuration difference already named: the two complexity thresholds and the
constant-coverage minimum, which the port carries at the package default and this repository sets higher.
Nothing new.

The 1 is new, and it runs the *other* way — PHPStan reports and the port does not, which is the direction a
narrowing guard can cause and the previous commit had just added one. It is not that guard.

    only-original  vendor/rector/rector/src/StaticTypeMapper/ValueObject/Type/SimpleStaticType.php:13

`SimpleStaticType extends StaticType`, so the guard passes. `PHPStan\Type\StaticType` lives only inside
`phpstan.phar`, and mago scans `.php` files. The parent is unresolvable, so "does the parent declare
`__construct`" answers no, and the port stays silent where PHPStan — running from that phar, with an
autoloader — reports.

Nothing in the port can close this. A plugin cannot read a phar. It is named on `Reflect` so the next
differential run does not read it as a defect.

#### The first probe answered a different question

The probe that established this was written with a bare `mago.toml` holding only `paths`, and it reported
that *every* class outside the analysed directory was unresolved — including `PhpParser\Node\Stmt\Class_`,
which is plain files. That would have made the finding a broad structural asymmetry rather than one narrow
cause, and the write-up had already started saying so.

The corpus differential does not run that configuration. It sets `includes`, a resolution context scanned for
symbols and never analysed, and `ResolutionRoots` puts the consumer's whole `vendor` in it. Re-probed under
that:

    PHPStan\Type\StaticType                       phar-only                 UNRESOLVED
    PhpParser\Node\Stmt\Class_                    vendor, plain files       resolved
    Rector\...\SimpleStaticType                   the analysed path         resolved
    ArrayObject                                   builtin                   resolved

One cause, not a class of them. The guidelines' rule is that a probe answers the question it asked rather
than the one about to be acted on; here the two differed by a config line, and the wrong answer was the more
alarming one.

#### Verification

No behaviour change — one docblock. Suite green, PHPStan 0 errors, emit-all unchanged: the runtime ships as a
package rather than being emitted, so a note on `Reflect` reaches no generated file.

### A third corpus, and the trait case the differential reads as false positives

`laravel/framework`'s `Support` and `Database` trees — 367 files, and the trait- and facade-heavy idioms this
port has diverged on before:

    total: agree 7592, only-original 407, only-port 551

Several identifiers move, and this section closes only the largest single cause. `NoDynamicNameRule` is the
sharpest: **177 agree, 15 only-port, 1 only-original**, in a rule whose closure guard was fixed two commits
ago — so the first question was whether that fix caused them. It did not; that fix narrows.

Nine of the fifteen are in traits, and the traits differ in one way that decides it:

| trait | users in the analysed paths | findings |
|:--|--:|--:|
| `ReadsClassAttributes` | 0 | 4 |
| `SoftDeletes` | 0 | 4 |
| `ManagesTransactions` | 0 | 1 |
| `CanBeOneOfMany` | 3 | 1 |

PHPStan reaches a trait's body only through a using class. With no user in the analysed tree it never
analyses the method and reports nothing; a node hook fires on the declaration and reports once.

#### Proved with a control, not read off the table

One file, two traits, identical bodies, one used by a class beside it and one not:

    symplify.noDynamicName   agree 1  only-original 0  only-port 1
        only-port  .../trait-control/src/Traits.php:12      <- the unused one

The used trait's line agrees; the unused one is port-only. Nothing else differs between them.

That is the same mechanism `TraitMethodHookDivergesTest` already measures at its other end — a trait method
reported once where PHPStan reports it per using class — so the fixture gained an unused trait and the test
now asserts **two** mago-only entries rather than one. The degenerate case is the one that reads as a false
positive in a differential, which is why it is worth pinning in CI rather than describing.

Not a defect to fix: the port analyses the file it is given, and declining to analyse a trait until something
uses it would be a deliberate narrowing with no evidence behind it. Named so the next differential run can
subtract it.

#### What is left on this corpus, unattributed

Six `noDynamicName` findings outside traits — `Pluralizer.php:93` calls `$function($comparison)` where
`$function` iterates a list of function-name literals, which PHPStan's callable check accepts and the port's
`typeIsCallable` does not. Plus `noProtectedClassStmt` at 5 only-original against 966 agreements,
`forbiddenStaticClassConstFetch` at 7 only-port against 86, and `returnTypeCoverage` at 33 each way. Each is
its own question and none is opened here.

#### Verification

No behaviour change — one fixture trait and the assertions that read it. Suite green, and the trait-divergence
test passes with the new entry in both places it appears.

### The anonymous class the hooks never see, and why registering it is not one line

`NoProtectedClassStmtRule` misses 5 findings against 966 agreements on `Illuminate\Database`. All five are
the same shape:

    AsBinary.php:24            protected string $format;
    AsEnumArrayObject.php:26   protected $arguments;
    AsEnumArrayObject.php:76   protected function getStorableEnumValue($enum)
    AsEnumCollection.php:26    protected $arguments;
    AsEnumCollection.php:72    protected function getStorableEnumValue($enum)

Every one is inside a `new class(..) implements CastsAttributes { .. }` that a Laravel cast returns.

The rule hooks `InClassNode` and tests `getOriginalNode() instanceof Class_`. php-parser has no separate
class for an anonymous one — it *is* a `Stmt\Class_` with a null name — so PHPStan visits it and the test
passes. Mago gives it `NodeKind::AnonymousClass`, the emitted plugin registers
`[Class_, Enum, Interface]`, and the hook never fires.

Two edits look sufficient and are not. `Emitter::targets()` adds the kind for every `classOnly` hook, and
`Declares::declarationKindIs()` answers `Class` for an anonymous one because that is what php-parser's
`instanceof` means. Together they change the target list of **20 emitted rules** and make
`NoProtectedClassStmtRule` see the five.

**They also cost two rules that emit today.** `NoMissingSpaceInClassAnnotationRule` and
`AttributeRequiresPhpVersionRule` move to `REFUSE` with `null comparison against Expr_Variable, which
resolved to a class-reflection`, and the census names it before any corpus does.

The cause is a fold, and the fold says so itself at `Translator:9259`:

    // two of them are settled by which hook it is: the class hook fires only for classes, and
    // never for anonymous ones, which are a separate node in Mago.

`isClass()`, `isAnonymous()` and their four neighbours are answered *statically* from the fact that the hook
cannot fire on an anonymous class. Registering the kind makes that false. `Reflect::parentHasConstructor()`
leans on the same assumption from the other side — its docblock says the anonymous case "comes for free"
because the enclosing-class read answers nothing for one.

So the work is: give `isAnonymous()` and its neighbours a real runtime answer from the node's kind, re-check
every fold that assumes the hook never sees one, and only then register the kind. That is a coherent piece of
work and it is not this commit — shipping the two edits alone trades 5 findings for 2 rules, which is a net
loss, and shipping them with a broken fold would be worse than either.

Reverted, measured, and left here so the next attempt starts from the dependency rather than from the
symptom.

### Registering the anonymous class, once the folds it invalidates are answered

The previous commit stopped at the dependency: `NoProtectedClassStmtRule` misses 5 protected members inside
the anonymous classes Laravel's casts return, and registering `NodeKind::AnonymousClass` breaks folds that
rest on the hook never seeing one. This is that work done in order.

**The blocker was smaller than it looked.** The regression the previous attempt measured —
`NoMissingSpaceInClassAnnotationRule` and `AttributeRequiresPhpVersionRule` moving to `REFUSE` with `null
comparison against Expr_Variable, which resolved to a class-reflection` — traces to
`everyHookKindIsInAClass()`, which walks the target set against `HOOK_KINDS_ALWAYS_IN_A_CLASS`. That list
lacked `AnonymousClass`, and an anonymous class **is** a class-like: a hook firing on one always carries a
class reflection. One line, and correct on its own terms rather than as a workaround.

Four changes, in the order the dependencies allow:

| change | why it is right, not just necessary |
|:--|:--|
| `HOOK_KINDS_ALWAYS_IN_A_CLASS` gains the kind | a hook on an anonymous class is in a class-like |
| `isAnonymous()` stops being `unreachable()` | it is a real question once the hook can fire on one, answered from the node's kind |
| `$node->name instanceof Identifier` stops folding to true | asking whether the declaration is named is exactly the question, and an anonymous class is the one that is not |
| `Emitter::targets()` gains the kind for `classOnly` | `InClassNode` fires there, so the plugin has to |

The third was found by its own comment. It folded to always-true citing "the same reasoning that makes
`isAnonymous()` unreachable here" — the assumption I had just removed. A fold that names what it rests on is
a fold that can be found again.

`Declares::declarationKindIs()` answers `Class` for an anonymous one, because the two questions behind it —
php-parser's `instanceof Stmt\Class_` and `ClassReflection::isClass()` — are both true of one. `AnonymousClass`
stays the narrow question, which is what `isAnonymous()` now compiles to.

#### Measured, not assumed, at three widths

The change alters the target list of **20 emitted rules**, so the question is not only whether the five
appear but whether anything else moved.

    Casts directory only        noProtectedClassStmt   agree 7    only-orig 0   only-port 0
    Support + Database, before  noProtectedClassStmt   agree 966  only-orig 5   only-port 0
    Support + Database, after   noProtectedClassStmt   absent from the divergence list — 971 agree

    Support + Database totals   before  agree 7592, only-original 407, only-port 551
                                after   agree 7597, only-original 402, only-port 551

Every other identifier is unchanged, and `only-port` is *identical* — the 20 retargeted rules produce no new
finding anywhere on that corpus. The census does not move either: no rule changes outcome.

The emitted diff is 21 files: 20 target lists, plus the rules that asked `isAnonymous()` and had the guard
dropped, which now emit a real one. The reviewed snapshot for `CompoundClassGuardRule` failed on its target
list and is updated, because the hook has to fire where PHPStan's does. Two allowlist entries in
`test_every_dropped_guard_names_why_it_cannot_hold` named folds that no longer exist and are removed; the two
that remain are about `SEARCHABLE` and the `ClassLike` hook row, neither of which this commit touches.

#### What is deliberately not in this commit

`HOOK_KINDS[ClassLike]` still registers four kinds. The same argument applies to it — php-parser's `ClassLike`
covers an anonymous class — but that row reaches a different set of rules and would need its own measurement.
`ExplicitClassPrefixSuffixRule`'s silence on an anonymous class is still asserted by name in the dropped-guard
allowlist, and it stays true.

#### Verification

Suite 930/930, fires gate green with a new `BadInsideAnonymousClass.php` pair whose three findings both
engines report. Mutation: without the target-list line the port loses all three and the pair fails. PHPStan 0
errors — `Translator` moves 2335 to 2337 and `instanceofPredicate()` 114 to 116, both already-baselined
entries with no new one.

### The `ClassLike` hook row, measured and not shipped

The previous commit left this open: `HOOK_KINDS[ClassLike]` registers four kinds where php-parser's
`ClassLike` covers five, and the same faithfulness argument applies. It was built and then dropped, because
the argument for it turned out to rest on a claim that is false.

Registering the kind reaches exactly **one** rule. The first measurement said three —
`NoAbstractControllerConstructorRule` and `NoControllerMethodInjectionRule` also gained a real
`$node->name instanceof Identifier` guard — but that was a stale baseline: those two got it from the previous
commit's fold change, and the tree I diffed against predated it. Rebuilding the baseline from the committed
state leaves `ExplicitClassPrefixSuffixRule`'s target list, and nothing else.

**The justification was that registering makes the name guard load-bearing rather than decorative.** That is
testable, so it was tested: neuter the guard in the runtime and the example holding an anonymous class should
report. It does not. The rule proceeds past the guard, reaches the class branch, and still reports nothing.

Tracing why corrected a comment that has been in the good example since the guard was dropped. It claimed the
port "would report the missing Abstract prefix if the hook ever fired". It would not — an anonymous class
cannot be abstract, since `new abstract class` is a syntax error, so the prefix branch never applies, and an
anonymous class's empty name ends with none of the suffixes the rule looks for.

So the silence there is over-determined three ways: the hook does not fire, the guard would stop it, and no
branch matches an unnamed non-abstract class. Registering the kind changes no finding on any corpus, and no
example can distinguish it from not registering. Under "the emitted output is the contract" that is not
enough to change 21 emitted bytes for.

Reverted. The example's comment and the dropped-guard allowlist now say what the silence proves — an outcome,
not a mechanism — so the next reader does not build the same argument on it.

#### Verification

No behaviour change: two comments. Suite 930/930, PHPStan 0, emit-all unchanged.

### Thirty-three findings each way, and every pair one line apart

`returnTypeCoverage` on Laravel's `Support` and `Database` trees: **3782 agree, 33 only-original, 33
only-port**. Equal counts either way is a shape worth reading before a cause is guessed at, and here it is
the whole answer:

    only-original  .../Eloquent/Collection.php:698        only-port  .../Eloquent/Collection.php:699
    only-original  .../Concerns/GuardsAttributes.php:46   only-port  .../Concerns/GuardsAttributes.php:47

Checked mechanically rather than by eye: taking every only-original site, adding one to its line, and
comparing the set to the only-port set gives an exact match, with nothing left over on either side. All 33
are attributed methods — `#[\Override]` on nine `Collection` methods, `#[Initialize]` on the `Concerns`
traits.

`ReturnTypeDeclarationCollector` writes `$missingTypeLines[] = $node->getLine()` on the function-like, and
php-parser's start line for an attributed method is the attribute's. The port anchored on
`$method->nameLocation`, which is the `public function` line. The two coincide exactly where there is no
attribute — which is every fixture in this repository, and why the anchor read that way for as long as it
did.

Anchoring on `$method->location` closes all 33: on `Illuminate\Database\Eloquent` the metric goes to **1218
agreements with nothing either side**. The nullability goes with it — `nameLocation` is null for a closure
and needed a fallback, while a declaration always has a location.

#### The gap that let it through, named rather than closed

`returnTypeCoverage` appears in **no test file**. `paramTypeCoverage`, `constantTypeCoverage` and
`declareCoverage` each have an `Aggregates*Test` comparing the port's findings against PHPStan's *by line* on
a fixture; the returns metric has only `CountsReturnsLikeTheCollectorTest`, which compares totals. Counts
agree — `ACCEPTED_DIVERGENCE` records `returns` at a 0.0 ceiling, 18307 of 18307 — and an anchor that is one
line out does not move a count.

So the suite stayed green through both the defect and the fix, and this change is demonstrated by the corpus
differential rather than pinned by CI. Building `AggregatesReturnCoverageTest` on the pattern the other three
follow, with an attributed method in its fixture, is the follow-up; it is named here rather than done because
it is a test class and its own fixture, not a line.

#### Verification

Suite 930/930, PHPStan 0 errors, emit-all unchanged across all three targets — the anchor is runtime, and the
runtime ships as a package. No census line moves and no count moves.

### Pinning the return-type anchor, and two guesses the fixture corrected

The previous commit fixed an anchor the suite could not see: `returnTypeCoverage` had no line-level test, so
the port reported every attributed method a line below the original and nothing caught it. This is
`AggregatesReturnCoverageTest`, built on the pattern its three siblings follow — four fixture files, findings
compared with PHPStan's by `line: message`.

**Both tests fail on the mutation.** Restoring `nameLocation ?? location`:

    agrees_with_the_real_rule    'Attributed.php' => 23 expected, 24 actual
    counts_and_skips             '24: Out of 4 possible ...' does not start with "23: "

So the anchor is pinned twice over: once against PHPStan's own answer, and once against the line the fixture
names.

#### The fixture corrected two things it was written to demonstrate

**The closure does not count.** `Anonymous.php` holds a closure with no return type, put there because the
collector's node type is `FunctionLike` and a closure looked like it would be counted. The run says 4
possible, not 5, and PHPStan agrees at 4 — the aggregate walks the codebase's *method* list, so a closure
never reaches it. The file is kept for that, stated as a measurement rather than as the reason it was
written.

**And the nullable fallback was unreachable, not defensive.** The previous commit's message said the
`nameLocation ?? location` fallback "goes too, since a declaration always has a location and only a closure's
name is missing". The case it named is one that loop never sees: it iterates metadata methods, and a method
always has a name. Removing the fallback was right, and the reason given for it was not.

The fixture earns its four files on measured grounds now: a declared return type that is counted and never
reported, a plain untyped method where both anchors coincide, an attributed one where they do not, and a
closure that is counted by neither engine.

#### Verification

Suite 933/933, up from 930 — the three new tests. PHPStan 0 errors: `proc_open()` is disallowed by
configuration and this test spawns both engines like its three siblings, so it joins the same scoped
exception rather than taking a new baseline entry. Emit-all unchanged; no census line moves and no count
moves.

### The fourth Laravel lead was the trait case again, one link further out

`forbiddenStaticClassConstFetch` reported 7 findings PHPStan does not, against 86 agreements. All seven sit
in traits — `BroadcastsEvents`, `HasFactory`, `MassPrunable`, `Prunable`, `SoftDeletes` — so the first guess
was the cause already pinned two commits ago: PHPStan reaches a trait body only through a using class.

Six fit it directly, with no user in the analysed paths. **The seventh did not.** `BroadcastsEvents` has one
user, so by the check used earlier its finding was unexplained.

It is the same cause, and the check was too coarse. `BroadcastsEventsAfterCommit` — the only thing using
`BroadcastsEvents` — is *itself a trait*, and nothing in scope uses it. The chain never arrives at a class,
so PHPStan analyses neither body and the silence is identical.

So "has a user" has to mean a using *class*, transitively. Counting `use` statements does not see the
difference, and counting them is what nearly left one finding attributed to nothing.

The fixture now holds that shape: `UsedOnlyByATrait` declares a method, `NothingUsesThisOne` uses that trait
and is used by nothing. `TraitMethodHookDivergesTest` asserts three mago-only entries instead of two — one
mechanism at three depths: reported once against once per user, no user at all, and a user chain that stops
at another trait.

Nothing to fix. The port analyses the file it is given, and this is the third face of a divergence already
recorded as deliberate. What is worth having is the fixture, so the next attribution of a port-only finding
in a trait does not have to re-derive that a trait is not a user.

#### Verification

No behaviour change — one fixture pair of traits and the assertions that read them. Suite 933/933, PHPStan 0,
emit-all unchanged.

### The last Laravel lead, and what the callable check actually answers

`noDynamicName`'s 15 port-only findings: 9 sit in traits with no using class, already pinned. The remaining
six were characterised two commits ago as "PHPStan's callable check accepts function-name literals and the
port's does not". That is true of two of them and wrong about the other four.

Probed rather than reasoned, on the five shapes a callable arrives in:

| written | mago's inferred type | `typeIsCallable` |
|:--|:--|:--|
| `@param Closure $cb` on an untyped parameter | `CallableType` | true |
| `Closure $cb` natively | `CallableType` | true |
| `mixed $x` under `if (is_callable($x))` | `CallableType` | true |
| `@param list<Closure>` iterated | `CallableType` | true |
| `foreach (['mb_strtolower', 'ucfirst'] as $f)` | two constant `ScalarType`s | **false** |

> **Superseded** by *The callable check was reading a rendering, and the flag was there all along* at the end
> of this file. The `is_callable()` row here probes `mixed`, which narrows to a `CallableType`; a `string`
> narrows to a `callable-string`, which this table never looked at and the port answered `false` for.

So the port's check is not missing a case for docblocks, for `is_callable()` narrowing, or for element
types — mago answers all four. The one shape it answers false for is a constant string naming a function,
which is `Pluralizer.php:93` and `:94` and nothing else in the fifteen.

The other four are each a place mago's inference does not reach as far as PHPStan's, and they are four
different places rather than one:

- `Connection.php:736` — `@param (\Closure(): array{query: string, ...}[]) $callback`, a parenthesised
  closure signature with a trailing `[]`.
- `Benchmark.php:27` — `$callback` is the parameter of a closure passed to `Collection::wrap(..)->map(..)`,
  so its type comes through a generic.
- `Migrator.php:857` — `is_callable($argument)` narrowing a `foreach` variable over `...$arguments`, where
  the same narrowing on a parameter does work.
- `CanBeOneOfMany.php:113` — `$closure` assigned in a branch above and called under `isset($closure)`.

#### Why the closable one is not closed here

`Types::typeIsCallable(?Type $type)` takes a type and nothing else. Answering "a constant string naming a
function the codebase knows" needs the codebase, so the signature gains a context — and that signature is
called by name from every emitted plugin that asks the question. Two findings on one corpus does not pay for
changing a shipped helper's shape and every call site that carries it.

Recorded instead, with the table, because the characterisation it replaces was mine and was wrong in both
directions: it named a cause that covers two of six, and it implied a missing check where there is none.

#### Verification

No behaviour change. Suite 933/933, PHPStan 0, emit-all unchanged.

### Most of the differential's noise is one configuration asymmetry

Four rounds of reading differential output have subtracted the same blocks by inspection each time — the two
complexity thresholds, the constant-coverage minimum, and on Laravel a 366-entry `declareCoverage` block
where the port reports nothing at all. They are one cause, and it is in the harness rather than in the port.

`CorpusDifferential` writes a neon that **includes this repository's own `phpstan.neon.dist`**, so PHPStan
runs at this project's thresholds:

    type_coverage: { return: 100, param: 100, property: 100, constant: 0, declare: 100 }

The port's side is built from the same rule list and constructed with **no arguments** —
`new \Transpiled\DeclareCoverageRule()` — so every plugin carries the package default instead. Hence the
shape of each block: `declare: 100` makes PHPStan report all 366 files that lack `declare(strict_types=1)`
while the port's default reports none; `constant: 0` makes PHPStan silent where the port's default reports 18;
and the complexity rules carry 40 against this project's 80, which is the 52 entries whose two messages the
report already prints side by side.

On the Laravel run that is **436 of the 551 only-port and 402 only-original entries** — the large majority of
what a reader has to subtract before the real divergences are visible.

#### Why it is not fixed here

`ConsumerParameters::argumentsFor()` exists to do exactly this: it reads the consumer's parameter dump and
passes matching values into the emitted plugin's constructor. Two things stop it.

- It matches `(true|false)` only, so an integer threshold is skipped.
- The names do not correspond. The emitted plugin takes `$required`; the consumer's key is `declare`, nested
  under `type_coverage`. Lining them up needs the *provenance* — which container parameter each constructor
  argument came from.

That provenance is recorded. `Transpiler` writes `configured[$name]['parameter']` when it resolves a rule's
default from the package neon. It does not reach the harness: the emitted manifest carries identifiers and
messages, and aggregates are not in it at all.

So the fix is to surface that mapping from the emit output and read it in `ConsumerParameters` — a change to
test support with a clear shape and no product surface. It is named here rather than started because the
tick that found it had already spent itself on the diagnosis, and because a half-threaded parameter would
make the runs *less* readable rather than more.

`paramTypeCoverage`'s 423 is not part of this: that is the reflection-extension over-count
`ACCEPTED_DIVERGENCE` records at a 1.11% ceiling.

#### Verification

No code change. The numbers above are read from the committed differential output and this repository's own
`phpstan.neon.dist`; the constructor call is quoted from the sandbox worker the last run wrote.

### Aligning the thresholds, and the finding the mismatch was hiding

The previous commit named the asymmetry and left it: PHPStan ran at this repository's thresholds while every
port plugin was constructed with no arguments. Closing it took three pieces.

**The emitted plugin now says where its argument came from.** `@param float $required PHPStan's
`%type_coverage.declare%`` sits beside the constructor. That is worth having on its own — the default in a
generated plugin is the *package's*, so a consumer at their own threshold has to pass one, and until now the
plugin gave them no way to learn which of their options it is. `ConsumerParameters` reads the same line.

**`argumentsFor()` reads that line rather than matching the argument's own name.** The two differ wherever
the option is nested, and every aggregate is: matching on `required` finds nothing, and the path resolves.
Numbers as well as booleans, and the older name-matching path stays for a plugin emitted before the line
existed.

**And a `-1` line is read as line 1.** That is PHPStan saying it has no position: `DeclareCoverageRule` asks
about the file, so it reports with no line and prints `-1`, while a plugin has to anchor somewhere.

| | before | after |
|:--|:--|:--|
| `complexity.classLike` / `functionLike` | 13 and 39 only-port | absent — thresholds now 80 and 20 on both sides |
| `typeCoverage.declareCoverage` | 0 agree, 366 only-original | **366 agree, 0, 0** |
| totals | agree 7597, only-original 402, only-port 551 | **agree 7996, only-original 3, only-port 466** |

Three only-original entries remain on the whole run, and both causes are already recorded:
`forbiddenArrayMethodCall`'s two and `noDynamicName`'s one.

The declare block is the part worth reading twice. Aligning the threshold alone moved it from *366
only-original and 0 only-port* to *366 and 366* — the same 366 files, PHPStan at `-1` and the port at `1`,
agreeing on every file and matching on none. The configuration mismatch had been hiding a comparison the
harness could not express, and fixing one exposed the other rather than fixing it.

#### What is still not aligned, and it is not what the last commit said

`constantTypeCoverage`'s 18 only-port survives, and the reason corrects the previous commit. That commit said
`constant: 0` in this repository's config made PHPStan silent where the port reported. The value is right and
the key is not: `type-coverage` declares **two** parameters per metric — `constant_type`, defaulted 99, and
`constant`, an "alias to avoid typos" defaulted null — and its `Configuration` object prefers the alias when
set. `$aggregate->threshold` names the non-alias half, so the port is now constructed at 99 where PHPStan
runs at 0.

Four metrics have that pair. Resolving it means the transpiler recording an alias chain rather than one
parameter, which is a change to what a rule's threshold *is* and not to how it is passed. Left here with the
package's own neon quoted, because a partial alias table would be the half-threaded parameter the previous
commit declined to write.

#### Verification

Suite 933/933, PHPStan 0. Thirteen emitted files change and every changed line is the `@param` docblock; the
reviewed `ParamTypeCoverageRule` snapshot is updated for it. No census line moves and no count moves.

### The alias chain, and the differential with no configuration left in it

The previous commit left four metrics misaligned and named the reason: `type-coverage` declares two
parameters per metric — `constant_type` at 99 and `constant`, an "alias to avoid typos", at `null` — and its
`Configuration` reads `$this->parameters['constant'] ?? $this->parameters['constant_type']`.

`ConfigurationObject::pathsFor()` already returned both, in fallback order, and said so in its docblock.
`AggregateRule::threshold()` threw one away: it took the first path with a *numeric* default, and an alias
declared `null` never has one. Right about the default, wrong about the consumer — someone who writes
`constant: 0` has set the alias, and a plugin carrying `constant_type` never sees it.

So `AggregateRule` records the paths rather than a path, the emitted `@param` line names them in order —
``PHPStan's `%type_coverage.constant%` or `%type_coverage.constant_type%``` — and `ConsumerParameters` takes
the first the consumer sets. A consumer who sets neither falls through to the package default, which is what
they would have got anyway.

    ConstantTypeCoverageRule(required: 0)      PropertyTypeCoverageRule(required: 100)
    DeclareCoverageRule(required: 100)         ReturnTypeCoverageRule(required: 100)
    ParamTypeCoverageRule(required: 100)

`constantTypeCoverage` leaves the divergence list, and with it the last configuration-caused block:

| run | agree | only-original | only-port |
|:--|--:|--:|--:|
| before the thresholds were touched | 7597 | 402 | 551 |
| thresholds and the `-1` line | 7996 | 3 | 466 |
| the alias chain | **7996** | **3** | **448** |

#### What is left is all traced but one

    paramTypeCoverage    423 only-port   the reflection-extension over-count, at a 1.11% ceiling
    noDynamicName         15 only-port   9 unused traits, 6 places mago's inference stops short
    staticConstFetch       7 only-port   unused traits, one through a trait-to-trait chain
    rector.noClassReflectionStaticReflection   3 only-port   not traced
    forbiddenArrayMethodCall               2 only-original   not traced
    noDynamicName                          1 only-original   not traced

Six entries across two identifiers have no cause written down. Everything else on a 367-file corpus is
either a recorded divergence or a measured ceiling.

#### Verification

Suite 933/933, PHPStan 0. Five emitted files change and every changed line is the `@param` chain; the
reviewed `ParamTypeCoverageRule` snapshot is updated for it. No census line moves and no count moves.

### `__FUNCTION__` is a value PHPStan folds and mago does not

Two of the six untraced entries were `forbiddenArrayMethodCall`'s only-original findings, and three more
turned out to need no work: `rector.noClassReflectionStaticReflection`'s three sit in `HasFactory` and
`ReadsClassAttributes`, traits with no using class in the analysed paths, which is already recorded.

Both `forbiddenArrayMethodCall` sites are the same line written twice:

    array_map([$this, __FUNCTION__], $value)     Grammar.php:233 and SqlServerGrammar.php:1040

The rule asks whether the second array element is a constant string naming a method that exists. PHPStan
resolves a magic constant to its value; mago's inferred type does not fold one. Probed over the same array
written three ways, which separates the rule's test from the value behind it:

    [$this, __FUNCTION__]     constantStringAt = NULL
    [$this, 'quoteString']    constantStringAt = 'quoteString'
    [$this, __METHOD__]       constantStringAt = NULL

So the literal test is right and the value is absent. `ConstantStrings::at()` now answers `__FUNCTION__`
from the declaration it sits in, and the corpus closes: **2 agree, 0, 0**, with `only-original` at 0 across
the whole `Illuminate\Database` subtree.

#### Two things the fix had to get right, and one it deliberately does not

**The nearest function-like, closures included.** `Declares::enclosingFunctionName()` walks *past* a closure,
because `$scope->getFunction()` does — that was a defect fixed earlier in this session. `__FUNCTION__` does
not: PHP gives a closure's own name there, not the method around it. So a closure answers null here, and both
engines stay silent — PHPStan reads `'{closure}'`, which names no method, and null fails the caller's own
literal test.

**The wrappers.** The subject a rule hands over is the array *element*, and the chain is
`ArrayElement > ValueArrayElement > Expression > MagicConstant` — probed, after two attempts that looked for
an `Expression` child of the element and found none, because the element's child is the `ValueArrayElement`.
The match is by text as well as by kind, so a subject that merely *contains* a magic constant is not read as
one.

**`__METHOD__` and `__CLASS__` stay null.** Both mean something else inside a trait — PHP resolves them
against the using class at runtime — so answering them from the declaration would guess which of two
questions a rule is asking. Null is what they answered before.

#### Verification

The pair gained a `__FUNCTION__` case and the mutation is the fold: without it the port loses that line and
the pair fails. Suite 933/933, PHPStan 0, emit-all unchanged — the fold is runtime, and the runtime ships as
a package. No census line moves and no count moves.

That leaves **one** entry on the Laravel run with no cause written down: `noDynamicName`'s single
only-original.

### The last entry: a destructuring reassignment the two engines type differently

`noDynamicName`'s single only-original finding, at `QueueFake.php:214`:

    $this->assertPushed($job, function ($job, $pushedQueue) use ($callback, $queue) {
        ...
        return $callback ? $callback(...func_get_args()) : true;
    });

`$callback` is `@param callable|null` and then reassigned in a list destructuring —
`[$job, $callback] = [$this->firstClosureParameterType($job), $job]` — inside an `instanceof Closure`
branch, and captured by the closure below.

**Reduced to a 27-line file that reproduces it**, and then asked of each engine rather than reasoned about,
because reasoning got it wrong twice: first I predicted PHPStan would find the type callable and skip, then
that its union would carry `Closure` rather than `string`.

| | type for `$callback` at the call | callable? |
|:--|:--|:--|
| PHPStan, `dumpType()` | `(callable(): mixed)\|string\|null` | no — `isCallable()->yes()` fails, so it reports |
| mago, atomics | `CallableType\|NamedObjectType` | yes — the port skips |

PHPStan keeps a `string` alternative through the destructuring; mago does not, and what it keeps is callable
throughout. The rule's guard is `isClosureOrCallableType()`, so each engine answers its own type truthfully
and they disagree about the type.

Not closable in the port. `Types::typeIsCallable()` matches `Type::isCallable()->yes()` on every outcome —
that was traced two commits ago — so the port asks the right question and gets a truthful answer about a
narrower inference.

#### Every entry on that run now has a cause

    paramTypeCoverage    423 only-port   the reflection-extension over-count, at a 1.11% ceiling
    noDynamicName         15 only-port   9 unused traits, 6 places mago's inference stops short
    staticConstFetch       7 only-port   unused traits, one through a trait-to-trait chain
    noClassReflection...   3 only-port   unused traits
    noDynamicName          1 only-orig   this one

Nothing on a 367-file corpus is unexplained. Four of the causes are recorded divergences, one is a measured
ceiling, and the rest are places mago's type inference reaches differently from PHPStan's — in both
directions, which is worth saying: `Pluralizer.php` is mago inferring less than PHPStan and `QueueFake.php`
is mago inferring more.

#### Verification

No code change. Both types above are dumped output, not readings of the source; the reduction that produced
them is a file PHPStan reports on and the port does not.

### All three corpora, re-read with the thresholds aligned

The php-parser and rector runs were measured *before* the configuration asymmetry was closed, so their
numbers were stale. Re-read:

| corpus | files | agree | only-original | only-port |
|:--|--:|--:|--:|--:|
| `nikic/php-parser/lib` | 270 | 1693 | 0 | 0 |
| `rector/rector/src` | 489 | 246 | 1 | 0 |
| `laravel/framework` `Support` + `Database` | 367 | 7998 | 1 | 448 |

`rector/rector/src` went from 657 only-port to **none**: all of it was the threshold mismatch, and the one
only-original is the phar-resident `PHPStan\Type\StaticType` parent named as unclosable.
`nikic/php-parser/lib` went from 410 only-port to none and is now exactly clean.

**9937 agreements against 450 divergences, and each of the 450 has a written cause:**

    423   paramTypeCoverage      the reflection-extension over-count, at its 1.11% ceiling
     15   noDynamicName          9 unused traits, 6 places mago's inference stops short
      7   staticConstFetch       unused traits, one through a trait-to-trait chain
      3   noClassReflection...   unused traits
      1   noConstructorOverride  a parent class that lives only inside phpstan.phar
      1   noDynamicName          a destructuring mago types more narrowly than PHPStan

Four of those causes are divergences this repository records as deliberate, one is a measured ceiling, and
three are places the two engines' type inference or class resolution differ — in both directions.

Five defects came out of these runs and are fixed: a `noConstructorOverride` false positive on a
twice-declared class name, a `paramTypeCoverage` under-count from reading ancestry by name, five findings
missed inside anonymous classes, a return-type anchor one line out on every attributed method, and
`__FUNCTION__` not folding to its value.

#### Verification

No code change. Every number is from a differential run in this session; the three sandboxes are separate and
each run reads the consumer's own configuration on both sides.

### A fourth corpus: 914 files, five divergences, three traced

`nesbot/carbon/src` — chosen for idioms the other three do not have, and the tightest run yet:

| corpus | files | agree | only-original | only-port |
|:--|--:|--:|--:|--:|
| `nesbot/carbon/src` | 914 | 1806 | 4 | 1 |

**`noProtectedClassStmt`, one only-original**, at `MessageFormatterMapper.php:42` —
`protected function transformLocale(?string $locale): ?string`. The class is
`final class MessageFormatterMapper extends LazyMessageFormatter`, and `LazyMessageFormatter` is declared
**twice**, in two files under `vendor/nesbot/carbon/lazy/`, each inside a conditional:

    MessageFormatterMapperStrongType.php   abstract class LazyMessageFormatter implements MessageFormatterInterface
    MessageFormatterMapperWeakType.php     abstract class LazyMessageFormatter implements ..., ChoiceMessageFormatterInterface
                                               abstract protected function transformLocale(?string $locale): ?string;

The rule skips a protected method whose name the parent declares. Only the weak-type variant declares
`transformLocale`, so the answer depends on which declaration each engine's index kept: the port skips, so
it has the weak-type one; PHPStan reports, so it does not. The same shape as `TokenPolyfill` two corpora
back — one name, two conditional declarations — except these sit in separate files and the two engines
resolve them differently rather than one of them losing a body.

**`paramTypeCoverage`, two only-original**, at `TranslatorImmutable.php:24` and `:40`. The chain is
`TranslatorImmutable extends Translator extends LazyTranslator extends AbstractTranslator`, and
`LazyTranslator` is the same kind of doubly-declared name.

**The first explanation for that pair was wrong, and it is worth writing down why.** It looked like the
variants differing: the strong-type one implements `TranslatorStrongTypeInterface` and the weak-type one does
not. But `AbstractTranslator` — which *both* variants extend — declares `__construct` at line 98 and
`setLocale` at line 323, so the collector's LSP guard should find an ancestor method whichever variant is
chosen, and both engines should skip. PHPStan does not. So the port's ancestry reaches `AbstractTranslator`
and PHPStan's does not, and *why* PHPStan's stops is not established here. Naming the doubly-declared class
is as far as the evidence goes.

**Two entries are not traced at all**: `noDynamicName` at `Rounding.php:130` (only-original, a call through a
variable holding a function name — the opposite direction from `Pluralizer.php`, where PHPStan was the silent
one) and `CarbonInterval.php:3624` (only-port, `$instance->$unit`).

#### Where the four corpora stand

    nikic/php-parser/lib          270 files   1693 agree   0 / 0
    rector/rector/src             489 files    246 agree   1 / 0
    laravel Support + Database    367 files   7998 agree   1 / 448
    nesbot/carbon/src             914 files   1806 agree   4 / 1

11743 agreements against 455 divergences, and 453 of them have a written cause. The two that do not are both
on this corpus and both named above.

#### Verification

No code change. Every number is from a differential run in this session, each in its own sandbox and reading
the consumer's configuration on both sides.

### The callable check read the wrong union rule, in both directions

`nesbot/carbon`'s untraced only-original was `Rounding.php:130`, which calls `$function(..)` where
`$function` is declared `callable|string $function = 'round'` — a native union. `Types::typeIsCallable()`
answered true on the **first** callable atomic, so the port stayed silent where the rule reports.

**The first fix was wrong, and the corpus said so within one run.** Requiring *every* atomic to be callable
— the rule `typeIsBoolean()` follows, and traced there to `UnionType`'s `lazyExtremeIdentity` — closed
Carbon's entry and opened seven on Laravel:

    laravel noDynamicName    only-port 15  ->  22
    carbon  noDynamicName    only-orig  1  ->   0

`Builder::findOr(..., ?Closure $callback = null)` calls `$callback()` with no null guard, so mago's type is
`Closure|null` and counting the null made the port report where PHPStan does not.

The caller settles it, and reading it was what the two directions were pointing at.
`CallableTypeAnalyzer::isClosureOrCallableType()` is four lines:

    $unwrappedNameStaticType = TypeCombinator::removeNull($nameStaticType);
    if ($unwrappedNameStaticType->isCallable()->yes()) { return true; }

**Every atomic except null, and at least one.** `removeNull` discards a null from the union and nothing else,
so `Closure|null` is exempt exactly as `Closure` is, and `callable|string` is not exempt at all. Both corpora
agree at that reading:

    carbon   1806 agree, 4 only-orig, 1 only-port   ->   1807 agree, 3 only-orig, 1 only-port
    laravel  7998 agree, 1 only-orig, 448 only-port  ->  unchanged

#### Pinned from both sides

The pair now carries both halves, and each mutation is caught by the other's file:

| mutation | what fails |
|:--|:--|
| back to *any* atomic | the bad example loses line 37, the `callable\|string` call |
| count the null in *every* | the good example gains line 54, the `?Closure` call |

A single-sided fixture would have accepted one of the two wrong readings, which is how this shipped: the
existing pair had a `callable` and a `Closure` parameter, both single-atomic, and neither can tell the three
rules apart.

#### Verification

Suite 933/933, PHPStan 0, emit-all unchanged — the check is runtime. `nesbot/carbon` now has one untraced
entry rather than two: `CarbonInterval.php:3624`, `$instance->$unit`, only-port.

### The last untraced entry is reachability, and it applies to every rule

`nesbot/carbon`'s remaining only-port was `CarbonInterval.php:3624`:

    if (PHP_VERSION_ID !== 8_03_20) {
        $instance->$unit += $value;      // 3618 — both engines report this

        return;
    }

    self::setIntervalUnit($instance, $unit, ($instance->$unit ?? 0) + $value);   // 3624 — port only

Both lines are the same dynamic property fetch in the same method, `incrementUnit()`, which is not a magic
accessor. In that one file PHPStan reports **13** dynamic names and the port 14, so the rule is working on
both sides and 3624 is the only difference.

PHPStan decides `PHP_VERSION_ID !== 8_03_20` from the constant — always true for any analysed version but
that one — so the `return` always fires and everything after it is never analysed. A node hook has no
reachability analysis and fires on the node regardless.

**Proved with a control, not read off the file.** One class, two methods, the same
`return $subject->$name;` in each, and the only difference is a guard PHPStan can decide:

    Reach.php:13   reached                                           agree
    Reach.php:28   after `if (PHP_VERSION_ID !== 8_03_20) { return; }`   only-port

Nothing in the port can close this. Constant-condition reachability is an analyser's job, and the plugin API
hands a hook every node in the file. Worth recording as a *general* cause rather than one rule's: it applies
to every emitted rule, and any consumer whose code guards on `PHP_VERSION_ID`, `PHP_OS_FAMILY` or a
`define()`d constant will see port-only findings behind those guards.

An earlier attempt to read PHPStan's side directly went wrong and is worth one line: a hand-written neon
registering `NoDynamicNameRule` alone reported *nothing* on the file, which read as PHPStan finding none. It
was the config — the rule takes a `CallableTypeAnalyzer` the package's own neon wires, and the differential
includes that neon. The rule reports 13 there.

#### Every entry on all four corpora now has a cause

    nikic/php-parser/lib          270 files   1693 agree   0 / 0
    rector/rector/src             489 files    246 agree   1 / 0
    laravel Support + Database    367 files   7998 agree   1 / 448
    nesbot/carbon/src             914 files   1807 agree   3 / 1

11744 agreements against 454 divergences, each with a written cause: one measured ceiling, four recorded
divergences, and the rest places the two engines resolve a class, infer a type, or reach a statement
differently.

#### Verification

No code change. The control is a two-method file run through the differential; both numbers above are from
that run.

### The parameter over-count is 7.4% on a vendor tree, not 1.11%

`paramTypeCoverage`'s 423 port-only findings on Laravel are the largest remaining block, and the shipped note
reads "Over-counts the original by up to 1.11%". The messages carry both totals, so the question is
answerable:

    Illuminate Support + Database    367 files    original 4568 possible / 1482 typed    port 5779 / 1696
    Illuminate, whole tree          1694 files    original 17635 / 5026                  port 18945 / 5259

**+1211 of 4568 on the subset (26.5%), +1310 of 17635 on the whole tree (7.4%).** The note was measured on two
Laravel *applications* — +81 of 13694 and +37 of 11428 — and it says so, but "up to 1.11%" reads as a bound,
and a consumer pointing the plugin at their vendor directory is seven times outside it.

**One hypothesis tested and refuted.** The natural guess was unused-trait multiplicity: PHPStan reaches a
trait only through a using class, so a trait whose users sit outside the analysed paths is counted by the port
and not by the original. Widening the corpus from 367 files to 1694 gives most of those traits their users —
and the *ratio* fell from 26.5% to 7.4% while the absolute over-count barely moved, +1211 to +1310. Scope
changes the denominator, not the surplus. Whatever the 1310 declarations are, they are counted in both scopes.

Nor is it the reflection extensions the note names: larastan is not installed here, so nothing is answering
`hasMethod()` from a factory or auth model.

`run-coverage-setdiff.php` is the instrument for naming the declarations, and the one attempt made with it
was uninformative for a reason worth writing down: pointed at a single trait file, both engines count zero,
because a trait with no using class in scope is counted zero times by *both*. Naming the 1310 needs a file
where the port over-counts on its own.

> **Superseded.** The instrument was the wrong one: bisecting `Illuminate` by directory put +1261 of +1310 in
> three directories of 38, and the cause is `@mixin`. See *"`@mixin` was the +1310"* below.

So the cause is not established, and the note now says that rather than implying the 1.11% covers it. The
figure ships inside the emitted plugin, and `AggregatesTypeCoverageTest` asserts both numbers rather than
one — a single assertion on "up to 1.11%" is what let the narrower figure stand as though it were general.

#### Verification

Suite 933/933, PHPStan 0. One emitted file changes and the change is the note; the reviewed snapshot is
updated for it. No census line moves and no count moves.

### The README kept a total two commits older than the runs it points at

The README's verification section read:

    Four vendor trees read 11743 agreeing against 455 divergences, and all but two have a traced cause.

Both halves were true when written and neither was true when read. `bd7fbc0` traced the first of the two
untraced entries — Carbon's `callable|string` call — by fixing it, and `9de66ca` traced the second to
reachability behind `PHP_VERSION_ID`, which no plugin can close. That moved the totals to 11744 against 454
and left nothing untraced, which is the sentence the two commit messages already carried.

The defect count was stale in the other direction. "Five in the last round" was written at `2e54196` and
listed at line 4956: the `noConstructorOverride` false positive, the `paramTypeCoverage` under-count, the
anonymous-class misses, the return-type anchor, and `__FUNCTION__`. `bd7fbc0` is a sixth — a behaviour change
in `Runtime\Types` that the fourth corpus found — and it came out of a later run than those five, so the
count and the "last round" cannot both stay. The README now says six across the last two rounds.

Nothing here is a new measurement. It is the README catching up with runs already recorded above, which is
the failure mode a number in prose has: the run moves and the sentence does not.

#### Verification

No code change and no test reads the README, so there is nothing to run. Both figures are read from the run
recorded at line 5110 of this file, and the defect list from line 4956.

### `@mixin` was the +1310, and PHPStan answers it from core rather than from larastan

The last step said the parameter over-count's cause was not established and named the instrument. The
instrument was the wrong one. What settled it was bisecting `Illuminate` by directory, which nobody had
done because the note's story — reflection extensions — predicted the over-count would be spread everywhere:

    Database    251 files   original 3098   port 4288   +1190
    Redis        16 files   original  117   port  172     +55
    Pagination   18 files   original  105   port  121     +16
    the other 35 directories                              +0

+1261 of +1310 in three directories out of 38, and 35 at exactly zero. That is not a property of the corpus;
it is three files' worth of one shape.

`Redis` was the smallest, so it went first. `PredisClusterConnection` counts 0 against 1, and the one
declaration is `keys(string $pattern)`. Nothing in its ancestry declares `keys` — checked, both the parent
chain and `Illuminate\Contracts\Redis\Connection`. What its ancestry does carry is `@mixin \Predis\Client`
on `PredisConnection` and `@mixin \Redis` on `Connection`.

**The first hypothesis was wrong and one control said so.** predis is not installed here, so the obvious
reading was that an unresolvable `@mixin` makes the guard fire. A control with `@mixin \Predis\Client` on the
parent counts 3 against 3 — PHPStan skips nothing. The mixin that matters is `\Redis`, which resolves because
ext-redis is loaded on this machine, and `Redis::keys()` exists.

So the cause is `MixinMethodsClassReflectionExtension`, which is in **PHPStan core** and not in larastan.
`ClassReflection::hasMethod()` answers for every method a `@mixin` target has, the collector's LSP guard
skips such a method entirely, and mago publishes `ClassLikeMetadata->mixins`. It was reproducible all along.

Five controls, each predicted before it ran:

| control                                        | PHPStan | port before | port after |
|:--|--:|--:|--:|
| `mixin-on-ancestor` — target declares it       |       3 |           5 |          3 |
| `mixin-absent` — the same, `@mixin` removed    |       5 |           5 |          5 |
| `documented-mixin` — target `@method`s it      |       1 |           3 |          1 |
| `mixin-chain` — two links                      |       3 |           5 |          3 |
| `mixin-unresolvable` — target does not resolve |       5 |           5 |          5 |

`documented-mixin` needed nothing extra: `Codebase::methodExists()` already answers for a `@method` line,
which is why a `@method` on a plain parent had never diverged. `mixin-chain` is why the walk is transitive —
`Relation` is `@mixin Builder` and `Builder` is `@mixin Query\Builder`, and following one link answered 5.
Both mutation-checked: disabling the follow fails exactly the three mixin rows and no others, and restricting
it to depth one fails only `mixin-chain`.

#### What is left is one declaration, and it is a stub gap

    Illuminate, whole tree   1694 files   original 17635   port 17636   +1

`Redis` alone still reads +3, and the three parameters are `PhpRedisConnection::hscan()`. Mago carries
`\Redis` too — probed by declaring each name against `@mixin \Redis` and reading which the guard skipped: it
knows `scan`, `sscan` and `zscan`, and not `hscan`. So the residue is a mixin target whose metadata is
missing a method the runtime has, which no plugin can close from this side.

That makes the figure machine-specific in a way worth saying out loud: without ext-redis loaded, PHPStan
resolves nothing for `\Redis`, skips nothing, and the over-count on `Illuminate` would be larger while the
port stayed the same. `mixin-extension-stub` pins the divergence at 1 against 4 and skips itself where the
extension is absent, rather than passing for the wrong reason.

The other two metrics were measured on the same three directories before deciding they were out of scope:
`properties` 416/46/33 and `returns` 3113/120/154, delta zero on every one. The property collector's guard
reads `hasProperty()`, which the mixin extension also answers, so the zero is a measurement rather than an
assumption.

#### The four corpora, and what the fix did to them

    nikic/php-parser/lib          270 files   1693 agree   0 / 0
    rector/rector/src             489 files    246 agree   1 / 0
    laravel Support + Database    367 files   7998 agree   1 / 25
    nesbot/carbon/src             914 files   1807 agree   3 / 1

**11744 agreements against 31 divergences**, down from 454. `paramTypeCoverage` on Laravel goes from 423
port-only to 2000 agreements with none either side. Agreements do not move, which is the arithmetic this
predicts: the fix removes findings the port should never have reported, and a removed false positive is not
a new agreement.

The 25 left on Laravel are the traced ones — `noDynamicName` 15, `forbiddenStaticClassConstFetch` 7,
`noClassReflectionStaticReflection` 3 — and carbon's `paramTypeCoverage` 2 only-original were there before
this change, unmoved by it.

#### One claim in the file was false, and this step found it by reading around the fix

`TypeCoverage`'s docblock said the collector's `@param callable` skip is "**not** reproduced ... a known gap
rather than a silent one". `DeclaredParameters::countable()` calls
`Declarations::declaresCallableParameter()`, which matches the original's `'@param callable'` substring
exactly — one space, so Laravel's `@param  callable` fires in neither — and `docblock-callable` controls it.
The sentence was wrong for as long as the filter has existed, and it is the kind of wrong that survives:
it reads as a caveat, so nobody checks it.

#### Verification

Suite 939/939 after the snapshot update, PHPStan 0 with no new baseline entry, and pint clean on every file
this touched. (`vendor/bin/pint --test` also names `src/Translator.php`, `src/SourceIndex.php` and four
fixtures; the fixtures are `pint.json` exclusions reached only by naming them, and the two sources are
unmodified by this change, so the run that names them is reading HEAD's copy of them.) Emit-all across `php`,
`analyzer` and `linter` over the four corpus packages plus `tests/Fixtures/Rules`: 213 files each side,
and `diff -r` names one emitted file — `ParamTypeCoverageRule.php`, lines 19-29, which is the note — plus the
`--out` path the four `mago.toml.snippet`s embed. The baseline was built by copying the three changed sources
aside, restoring them from HEAD, emitting, and copying back; a `git worktree` was tried first and was wrong,
because its `vendor` symlink autoloads this repository's `src` and both runs then read the same code.

### The same premise failed twice, and the second one no corpus contains

`@mixin` closed the parameter over-count because `ClassReflection::hasMethod()` is answered by a core
reflection extension. That is not a fact about the coverage collector. It is a fact about `hasMethod()`, and
`Support::methodExists()` — whose docblock says "which is `ClassReflection::hasMethod()`" — is the path
**every emitted rule** takes for the same question. So the audit was: which shipped rules ask it, and does the
mixin gap reach them.

Thirteen emitted rules call `methodExists()` or `typeHasMethod()`. Two of them ask it about a parent class,
and both are wrong in the presence of a mixin, in **opposite directions**. One probe file, one line, both
rules:

    tests/Fixtures/probe-mixin/Subjects.php:15
      symplify.noProtectedClassStmt            only-port      -> a false positive
      symplify.parentMethodVisibilityOverride  only-original  -> a false negative

`NoProtectedClassStmtRule` skips a protected method the parent also declares, reading
`$parentClassReflection->hasMethod($name)`, so PHPStan skips a method a `@mixin` supplies and the port
reported it. `PreventParentMethodVisibilityOverrideRule` needs the parent method's *visibility*, so the port
found no parent method at all, took the `continue`, and said nothing where PHPStan reports.

A second probe settled a property the fix depends on, predicted before it ran: **a mixin is inherited.** With
the `@mixin` on a grandparent and the question asked of the middle class, both rules behaved exactly as in
the direct case. So the seed is the class plus its declared ancestry, not the class alone.

`Runtime\Mixins` now holds one walk for both callers. `Mixins::declaringMethod()` tries
`getDeclaringMethod()` first and walks mixins only when that finds nothing, so nothing that already agreed
can change; `Reflect::methodExists()`, `Reflect::parameterAt()` and `Members::reflectedMethodVisibility()`
route through it, and `DeclaredParameters::throughMixins()` is now three lines over the same
`Mixins::targetsOf()`.

`Reflect::declaringClassName()` is deliberately left alone. PHPStan's `getDeclaringClass()` for a
mixin-provided method names the mixin class, so following through would be more faithful — and it would
change what every rule gating on a declaring class decides, on a corpus where the question does not arise.
Named rather than done.

#### The corpora are unchanged, and that is the point of reporting them

    nikic/php-parser/lib          270 files   1693 agree   0 / 0
    rector/rector/src             489 files    246 agree   1 / 0
    laravel Support + Database    367 files   7998 agree   1 / 25
    nesbot/carbon/src             914 files   1807 agree   3 / 1

11744 against 31, identical to the run before this change. **Neither defect occurs in 2040 files of vendor
code.** The differential did not find them and cannot confirm them; the audit found them and the example
pairs are the evidence. So the corpora here are the regression check — the fix touches a path thirteen rules
use and moved nothing that agreed — rather than the demonstration.

That is worth separating, because a run that reports "no change" is exactly what a fix nobody needed also
reports. What distinguishes them is the mutation check: emptying the mixin walk fails the good example of
`NoProtectedClassStmtRule` and the pair of `PreventParentMethodVisibilityOverrideRule`, in the two directions
above, and nothing else in 564 gate cases.

#### Verification

Suite 939/939, PHPStan 0 with no new baseline entry, pint clean. Emit-all across `php`, `analyzer` and
`linter` over the four corpus packages plus `tests/Fixtures/Rules`: 213 files each side, and `diff -r` names
**no emitted file at all** — only the `--out` path the four `mago.toml.snippet`s embed. A Runtime change
should move zero emitted bytes, and this one does. Baseline built by the copy-aside route again.

### A literal string naming a function is callable, and the port said no for every string

Two of Laravel's `noDynamicName` port-only findings are `Illuminate/Support/Pluralizer.php:93` and `:94`:

    $functions = ['mb_strtolower', 'mb_strtoupper', 'ucfirst', 'ucwords'];
    foreach ($functions as $function) {
        if ($function($comparison) === $comparison) {

PHPStan's type for `$function` there is a union of four constant strings, `ConstantStringType::isCallable()`
says yes for each, so `CallableTypeAnalyzer::isClosureOrCallableType()` exempts the call and the rule
declines. `Types::isCallableAtomic()` matched only a `CallableType` and a `Closure` object, so the port
reported — on every dispatch table a consumer writes, not only on these two lines.

The exemption's clauses were read out of `phpstan.phar` rather than inferred from the name, because only its
`Yes` exempts and it has three ways of not saying yes: a function name is a plain existence check;
`Class::method` needs the class known, the method present **and static**, because
`PhpVersion::supportsCallableInstanceMethods()` is `versionId < 80000`; and an unknown class or a missing
method on a non-final one is `Maybe`, which reports.

Six shapes, each predicted before the run and each now agreeing:

| shape                                             | PHPStan | port before |
|:--|:--|:--|
| a literal naming a function                        | silent  | reports     |
| a union of them from a `foreach` over a list       | silent  | reports     |
| `'Class::staticMethod'`                            | silent  | reports     |
| `'Class::instanceMethod'`                          | reports | reports     |
| `'NoSuchClass::whatever'`                          | reports | reports     |
| a name that is no function                         | reports | reports     |

#### Two wrong instruments, each found by probing rather than by reading

**`StringType->callable` is not this question.** The SDK publishes exactly that flag on the string type, and
reaching for it is the obvious first move. Written that way, the clause changed no finding on a probe holding
four shapes it should have closed — and it did not fire for a `@param callable-string` either, so it has no
control here and was dropped rather than shipped unexercised.

**A literal string is not a `StringType` atomic.** A probe printing the atomics for `$function(..)` answered
`ScalarType kind=String refinement=StringType`, and the type *renders* as plain `string` in both the literal
and non-literal case. So the value lives on the scalar's refinement, which is what `constantStringsOf()`
already read for a whole type; `literalStringOfAtomic()` is the one-atomic form of the same read, because the
callable question is asked per atomic and a union has to answer for each.

**`MetadataFlags::STATIC` reads false for a `public static function`.** The constant exists, `1 << 32`, and
`flags->contains()` answered false on a control where the method was found. `FunctionLikeMetadata` carries a
dedicated `public readonly bool $static`, which is right. That makes three occasions this repository has recorded a
field existing being mistaken for a field answering — `PHPVersion::$id`, whose integer is packed differently
from PHPStan's, and `getConstant('PHP_EOL')`, which answers by bare name and not inside a namespace, are the
other two. The probe that caught this one printed the lookup and the flag on the same line.

#### The emitted signature changes, deliberately

`Support::typeIsCallable()` had no context to ask the codebase with, so it takes one now, and the Translator
emits `Support::typeIsCallable($context, ..)`. One emitted rule calls it, so emit-all across the three
targets names exactly one file and one line — the added argument.

    nikic/php-parser/lib          270 files   1693 agree   0 / 0
    rector/rector/src             489 files    246 agree   1 / 0
    laravel Support + Database    367 files   7998 agree   1 / 23
    nesbot/carbon/src             914 files   1807 agree   3 / 1

11744 agreements against **29** divergences, from 31. The 13 `noDynamicName` port-only findings left on
Laravel are 10 in traits with no analysed user — a recorded divergence — and three engine-level type
differences, each read at its site: `Connection.php:736` is a `@param (\Closure(): ..)` docblock,
`Migrator.php:857` is `is_callable()` narrowing a variable, and `Benchmark.php:27` is a closure parameter
typed only through `Collection::map()`'s generics. None of the three is a port bug.

#### Verification

Suite 939/939, PHPStan 0 with no new baseline entry. Emit-all: 213 files each side, one emitted file
differing by one argument, plus the `--out` path in four snippets. Mutation-checked: forcing the literal to
null fails the good example at all three of its new lines and leaves the bad example's ten findings agreeing,
which is both directions of the clause in one run. `vendor/bin/pint --test` also names `src/Translator.php`,
unmodified here beyond that one argument and already listed at HEAD.

### Finishing the `hasMethod` family: one more live defect, and one hypothesis the control refuted

Two audits had already paid, so the third asked the remaining sites the same question. `ACCEPTED_DIVERGENCE`
itself had nothing left to re-ask — four of its five metrics carry a zero ceiling and the fifth is now +1 —
so the audit went to the other places the runtime answers a reflection question.

**`Types::typeHasMethod()` is the port of `$type->hasMethod($m)->yes()`, and it read `methodExists()`
directly.** `ForbiddenArrayMethodCallRule` reports `[$object, 'method']` when the method *exists*, so a
mixin-supplied name made PHPStan report and the port stay silent. Three shapes in one probe, predicted first:

    [$base, 'mixedInMethod']     only-original   a false negative
    [$base, 'ownMethod']         agree (both report)
    [$base, 'noSuchMethodHere']  agree (both silent)

Routing it through `Mixins::declaringMethod()` closes the first and leaves the other two. That is the third
defect of this shape, after the false positive in `NoProtectedClassStmtRule` and the false negative in
`PreventParentMethodVisibilityOverrideRule`, and like those two it is latent: the four corpora read 11744
against 29 both before and after.

#### The property collector looked like a fourth and is not

`PropertyTypeDeclarationCollector::isGuardedByParentClassProperty()` asks `$parent->hasProperty($name)`, and
`MixinPropertiesClassReflectionExtension` is right there in `phpstan.phar` beside the methods one. The
inference was that the port's property guard has the same gap.

It does not, and the control says so twice over. A class whose parent carries `@mixin` of a class declaring
`public string $shared` counts **2 against 2** — PHPStan reports the untyped `$shared` rather than treating
it as guarded. Running PHPStan on the same fixture at level 8 says why: `Access to an undefined property
ProbeMixinProp\PropBase::$shared`. So the mixin supplies nothing for the guard to find.

And the discriminating control, because "the mixin is not resolving in this file" would explain the same
result: adding a *method* to the same mixin target and reading `$base->sharedMethod()` from the same file
raises no error at all. The mixin resolves; it resolves for methods and not for this property. So the
extension existing was not the extension answering — the same mistake as `MetadataFlags::STATIC` one step up,
made about a class rather than a field, and the only reason it did not ship a change is that the control ran
before the fix.

#### One near-miss worth recording, caught by a guard test rather than by care

`vendor/bin/pint` on the example directory rewrote `array($this, 'handle')` to `[$this, 'handle']` in
`BadArrayCallable::legacyCallable()` — the exact case that file's own docblock says pint destroys, which is
why the file sits in `pint.json`'s `notPath`. Naming the directory on the command line bypasses that.
`KeepsTheShapeAFixtureExistsForTest` failed with "no longer contains array($this, 'handle'), so the case it
exists to exercise is gone and its pair passes for nothing", which is the whole point of that test. Restored,
and the lesson is to run pint the way the project runs it rather than pointed at a path.

#### Verification

Suite 939/939, PHPStan 0 with no new baseline entry, pint clean. Emit-all across `php`, `analyzer` and
`linter`: 213 files each side, no emitted file differing — only the `--out` path in four snippets, as a
Runtime change should be. Mutation-checked: putting `methodExists()` back fails exactly the new line of
`BadArrayCallable.php` and nothing else in 564 gate cases. Four corpora unchanged at 11744 against 29.

### The linter target had no check at all, and a mutation shows the whole suite misses it

The guidelines have named this since they were written: "The test suite runs the PHP target only, so the
analyzer and linter branches have no check in it", with the standing answer being to emit all three targets
by hand and `diff -r` after every step. That works, and it is not a check — it runs when someone remembers to
run it. Every step in this session has done it manually.

Half of it was already automated and nobody had said so: `TranspilesToRustTest` pins three `analyzer`
snapshots. The `linter` target had none.

The two Rust targets share the body and nothing else. For the same rule the analyzer emits a `Provider` and a
hook method; the linter emits a `LintRule` with its own config struct, a `RuleMeta`, a `targets()` and a
`check()` that destructures the node kind first. So a body change shows in both and a scaffold change shows
in one — which is exactly the shape a shared snapshot cannot cover.

`tests/Fixtures/expected-lint` now holds the same three rules the analyzer test pins, byte-identical to what
the CLI writes (checked against the emit-all tree rather than assumed), and `TranspilesToLintTest` compares
them.

#### Sized by mutation, in two directions

The obvious mutation is the one the guidelines quote — `$reportSpan`'s `with_message("here")`. It fails
**both** Rust tests, so it does not size the new one: the analyzer snapshots already caught it.

The mutation that does is linter-only. Changing `Category::BestPractices` to `Category::Correctness` in the
linter scaffold:

    the whole suite minus this test    939 tests, 939 passed
    this test alone                    4 tests, 3 failed

**A 939-test suite passes on a changed emitted byte.** That is the gap, measured rather than described, and
it is now closed for the scaffold as well as the body.

The class also asserts four properties apart from the byte comparison — that the file implements `LintRule`
rather than `Provider`, carries a `check()`, reports under the rule's identifier, and holds no PHP outside
the example fields. A snapshot compared only whole says nothing about *why* it is right, and updating one to
make a run green is a single keystroke.

`good_example` and `bad_example` are `"<?php\n"` in these snapshots, because the transpiler is called without
`--examples`. That is the API path a consumer calling the class directly takes, so it is pinned rather than
worked around.

#### Verification

Suite 943/943, up from 939 by the four new tests. PHPStan 0, pint clean on the new file. No `src/` change, so
there is nothing for an emit-all diff to compare — the mutation above is the evidence, and it was reverted
from `src/Emitter.php` by copy-restore rather than `git checkout`.

`composer validate-gitattributes` fails, and it failed before this change: the managed block is missing
`.cache/phpstan-dogfood/` and `.cache/phpstan-emitted/` that the validator expects. `.gitattributes` is
boost-managed, so the fix belongs in the sync source rather than in the file. Not touched here, and recorded
so the next reader does not read it as this change's doing. `tests/` is already `export-ignore`d, so the new
fixture directory adds nothing to the published archive.

### The analyzer scaffold is watched after all, and one branch of it is unreachable

Last step closed the linter gap, so this one asked the same question of the analyzer target: the snapshots
exist, but nobody had measured what a change to them would be caught by. "A snapshot exists" and "the suite
catches a change" are different claims, and the linter case had just shown the second can be false.

Mutating `ProviderMeta::new(.., "generated")` in the node-hook scaffold fails all three analyzer snapshots,
so that half is genuinely covered. The measurement is the answer here rather than a fix — and it is worth
having, because the same edit to a *different* line of the same file is invisible:

    Emitter.php:840  the node-hook scaffold      3 of 3 analyzer snapshots fail
    Emitter.php:817  the AnalysisHook scaffold   943 of 943 tests pass

#### Why the second one is invisible, which is not the reason it looked like

The obvious reading is a snapshot gap of the kind the linter had. It is not. **No rule in the installed
corpus reaches that branch at all.**

The five type-coverage aggregates are the rules that produce `trait === 'AnalysisHook'`, and they never
arrive: `Transpiler::aggregate()` builds a PHP template of its own and returns it under the `rust` key, so
the Rust scaffold in `Emitter` is not on their path. Both Rust targets also refuse them outright — `early
return from a helper that is not a boolean literal` at line 23 — which was measured before the template was
read, and either fact alone is enough.

That leaves three rules whose node type is `CollectedDataNode` and which are not aggregates. All three refuse
on both Rust targets, each for its own unrelated reason:

    NewOverSettersRule                    condition outside the vocabulary: ->isEnabled
    WriteNamedArgumentManifestRule        unknown local $file
    NarrowPublicClassMethodParamTypeRule  assignment value outside the vocabulary

So the branch is dead in practice rather than dead by construction, and what would make it live is one of
those three refusals closing. That is recorded on the branch itself, pointing at the census as the signal:
a `CollectedDataNode` rule moving REFUSE to EMIT is when it needs a snapshot before anyone trusts it.

No test asserts the unreachability. One would fail on progress rather than on regression, and the census
already reports the move that matters.

#### Verification

Suite 943/943, PHPStan 0, pint clean. The only change is a comment, so there is nothing for an emit-all diff
to move; both mutations above were reverted by copy-restore. The first mutation of this step was also the
wrong instrument and is worth naming: `Emitter.php:817` and `:840` hold the same two lines of Rust, so
picking the first `ProviderMeta::new` a grep returns tests the branch nobody reaches.

### What the suite actually watches, one token at a time

Three ticks of this now: the linter target had no check, the analyzer target turned out to have one, and this
step finished the map by mutating a token in every remaining emission path and reading which tests fail.

| emission path            | mutated token                          | what fails                |
|:--|:--|:--|
| php node hook            | the `PluginDefinition` description     | 21 `TranspilesToPhpTest`  |
| php whole-project pass   | the same line in the other template    | 1 `TranspilesToPhpTest`   |
| php aggregate template   | `{DESCRIPTION}` in `Transpiler`        | 1 `AggregatesTypeCoverageTest` |
| analyzer node hook       | `ProviderMeta::new(.., "generated")`   | 3 `TranspilesToRustTest`  |
| linter rule              | `Category::BestPractices`              | 3 `TranspilesToLintTest`  |
| analyzer whole-run hook  | the same `ProviderMeta` line           | **nothing** — unreachable |

Every reachable path is watched. That was not knowable from reading the tests: two of these paths are two
templates in one file that differ by a few lines, and the php scaffold splits into a node-hook and a
whole-project form that only one snapshot in twenty-two exercises.

#### The guideline said the opposite, and two of its sentences were measurably false

`.ai/guidelines/baseline.md` — the source `boost sync` reads into `CLAUDE.md` and `AGENTS.md` — said "The
test suite runs the PHP target only, so the analyzer and linter branches have no check in it", and offered
"a one-token change to `$reportSpan` alters five `.rs` files and nothing the suite sees" as the reason.

The first was already wrong before this session: `TranspilesToRustTest` has pinned three analyzer snapshots
for as long as it has existed. The second is wrong today and was measured rather than argued — that exact
change fails six tests, three analyzer snapshots and three linter ones.

The instruction those sentences justify is still right, for a different reason, and the correction says which:
the snapshots read 22 of 58 fixture rules on the php target and 3 on each Rust one, while the corpus emits
138, 42 and 33 files. A change that moves only a corpus rule's shape moves no snapshot. So emit all three
targets and `diff -r` anyway — because the snapshots are narrow, not because they are absent.

The copy-aside baseline recipe is now written down there too. This session built a `git worktree` for that
job first and got an empty diff for the wrong reason: the worktree's `vendor` symlink autoloads this
repository's `src`, so both runs read the same code.

#### Verification

Suite 943/943, PHPStan 0. Six mutations, each reverted by copy-restore before the next; `git status` shows no
`src/` or `tests/` change, so the only edit is the guideline and the two files `boost sync` generates from
it. The sync was run deliberately and its diff read line by line before committing — `wrote=2, unchanged=83,
deleted=0`, and the diff in each generated file is the one paragraph. That check is not ceremony: a sync run
by a composer hook once deleted and regenerated both files inside an unrelated commit.

### Four more stale numbers in the guidelines, and one that was right

The last step found a guideline paragraph carrying two false claims, so this one checked the rest of the
figures in `.ai/guidelines/`. Each is a `grep` away, which is the whole point.

| claim                                              | measured                        |
|:--|:--|
| baseline holds **33** entries                      | 32 (`grep -c 'identifier:'`)    |
| covering **58** errors                             | 58 — right                      |
| `Translator` scores **1827**                       | 2337                            |
| `Transpiler` **169**                               | 192                             |
| `Support` is a facade over **eleven** classes      | the paragraph then lists twelve, and `src/Runtime` holds 38 files |
| census covers **129** rules in **four** packages   | 190 rules across seven          |

The error count being right is the ordinary case and the reason this kind of drift survives: staleness
arrives one figure at a time, so a paragraph half-checked reads as checked. The complexity figures are the
sharpest of them — the guideline's own next sentence says a rising number there is the cost of coverage
rather than a regression, which is exactly why nobody re-read the numbers while they rose by 28% and 14%.

The `eleven`/twelve mismatch was internal to one sentence and had nothing to do with time: the list beside
the number has always had twelve names in it.

Where a figure will go stale again the correction prints the command beside it rather than a fresher number
— the entry and error counts, `ls src/Runtime`, the baseline's own `complexity.classLike` entries, and the
census's own version list. `phpstan-baseline.neon` is not gitignored, so all of these are answerable from a
checkout without running anything.

One claim in `dependencies.md` was checked and holds: `rector/type-perfect` is still absent from
`composer.json`.

#### Verification

No code change. `composer sync-ai` reported `wrote=2, unchanged=83, deleted=0`, and the diff in `CLAUDE.md`
and `AGENTS.md` is the six edited paragraphs and nothing else — read line by line, because a sync run by a
composer hook once swallowed 429 lines into an unrelated commit. Suite and analysis untouched by a
documentation-only change; the last full run in this session was 943/943 with PHPStan at 0.

### The performance table had no instrument in the repository

Every other figure this project publishes has its instrument committed beside it — `run-corpus-differential.php`,
`run-coverage-corpus.php`, `run-coverage-setdiff.php`, the census generator. The README's performance table
did not. It was produced by `internal/dogfood-laramago/bench.py`, and `internal/` is gitignored, against a
project that is not in this repository. A reader could not repeat it, and nothing re-measured it when the
runtime changed — this session alone added codebase lookups to three hot paths.

`tests/Support/run-benchmark.php` is that instrument. Four rows, wall and CPU, best of `--runs` with the
spread beside it, both engines reading the consumer's own configuration the way the differential does.

On a corpus this repository actually has:

    nikic/php-parser/lib, 270 files, 80 emitted rules, n=3

                                     wall       CPU
      mago, engine only             3.84s     3.76s   spread 0.36s
      mago + the transpiled rules   5.79s     7.17s   spread 0.13s
      PHPStan, cold result cache    2.69s     9.35s   spread 0.08s
      PHPStan, warm result cache    0.74s     0.71s   spread 0.11s

**The rules add 1.95s wall and 3.41s CPU**, which is the marginal cost and the number the totals never give.
Against cold PHPStan the port is 2.2x *slower* on wall clock and 1.3x cheaper on CPU; against warm PHPStan it
is 7.8x slower and 10x more CPU.

#### This does not correct the README, and saying why is the point

The README reports 20 rules over 1090 files with the engine alone at 0.10s. This run is 80 rules over 270
files with the engine alone at 3.84s — a different corpus, a different rule count, and an engine baseline
38 times apart. Replacing one with the other would be the baseline error `measurement.md` warns about, in the
direction that happens to flatter nothing: a number swapped for a number measured against something else.

What can be said without a second measurement is narrower and still worth writing: the published table is not
reproducible from a checkout, and on the corpus that is, the engine dominates and the port is not faster than
PHPStan. Deciding what the README should carry needs both figures side by side, which is a next step rather
than this one.

#### Two instrument bugs, both caught by the number looking wrong

- **The cold row was not cold.** PHPStan's `tmpDir` comes from the consumer's own configuration, which the
  generated one includes, so clearing `$sandbox/phpstan-cache` cleared a directory nothing wrote to. Cold and
  warm printed 0.76s and 0.75s, which reads as "the result cache buys nothing" rather than as a broken
  harness. The benchmark now writes its own `tmpDir` and owns it: 2.69s against 0.74s.
- **`--packages=` takes names without `vendor/`.** `CorpusDifferential` prepends it, so the first run refused
  every package and threw. That one announced itself.

#### Verification

PHPStan 0 — `proc_open()` is forbidden by this project's own configuration, and the benchmark is added to the
same scoped exception the fires-gate and the differentials sit in, for the same reason: what two engines cost
is a property of running them. Pint clean. No `src/` change, so no emitted byte moves.

### What the rules cost, measured three times on one corpus

The benchmark existed but the README still carried a figure nothing could reproduce, so this step measured
the same corpus at three rule counts. One corpus, one machine, `n=3`, only the packages varying:

| packages                        | emitted | engine only | with rules | rules add            |
|:--|--:|:--|:--|:--|
| `cognitive-complexity`          |       2 | 3.78s / 3.80s | 4.06s / 4.67s | +0.28s wall, +0.87s CPU |
| all but `type-coverage`         |      75 | 3.88s / 3.74s | 4.58s / 5.73s | +0.70s wall, +1.99s CPU |
| all four                        |      80 | 3.84s / 3.76s | 5.79s / 7.17s | +1.95s wall, +3.41s CPU |

**Five rules cost more than the other seventy-five.** Going from 75 to 80 adds 1.21s wall and 1.44s CPU, and
those five are `type-coverage`'s whole-codebase aggregates — the ones that walk every class rather than
firing per node. Going from 2 to 75 adds 0.42s wall for 73 more per-node rules.

That was predicted before the run in the direction it came out, and it settles the shape: a fixed host cost
of roughly a quarter-second, a small per-node-rule cost, and an aggregate cost in its own class.

#### It also explains the figure that could not be reproduced

The README's old table reported the engine alone at 0.10s where this corpus reads 3.84s, and a marginal cost
of +0.15s wall for 20 rules where 75 per-node rules cost +0.70s here. Both gaps have the same two causes and
neither is the port: the engine baseline tracks the *resolution set*, and this repository's `includes` is its
whole vendor tree; and the 20 rules that table measured are the ones `VERIFICATION.md` describes as one hook
row each for twenty node types, which is the cheapest shape there is.

So the old numbers were not wrong. They described a small project running trivial rules, and were read as a
property of the port. The README now carries the measurement a reader can repeat, names the corpus in the
same sentence, and says plainly that on it the port is 2.2x slower than a cold PHPStan on wall clock while
1.3x cheaper on CPU — and slower on both than a warm one.

#### What was not checked

The per-package table in `## What it can translate` — 99 of 169 portable — was not re-verified here. The
census counts 107 EMIT of 190, and the two are reconcilable if "portable" means the rules each package
*registers*, which is what the sentence beside the table says. Reconcilable is not verified, and saying so is
cheaper than a wrong count: it is one run of the status command away for whoever needs it.

#### Verification

README 1275 words after the rewrite, trimmed to 1227 against the `readme` skill's ~1200 ceiling — prose
only, across five sections, with no table, example or caveat cut. No code change, so nothing to emit or
diff; PHPStan and the suite were last green at 943/943 in the previous step and this one touches neither.

### The coverage denominator was the sum of the table, not what the tool says

Last step published a README that left one figure unverified and said so. This step ran the check.

    php bin/phpstan-to-mago --status

Every per-package row matches the README exactly — symplify 59 of 89, hihaho 6 of 7, type-coverage 5 of 10,
cognitive-complexity 2 of 3, strict-rules 22 of 45, phpunit 4 of 13, deprecation-rules 1 of 2. The total does
not:

    runs: 99 of 209 portable rules (target: php)

The README said **99 of 169**. 169 is the sum of the table's own `portable` column, and the tool counts two
more installed packages that the table does not list: `spaze/phpstan-disallowed-calls` at 0 of 38 and
`composer/pcre` at 0 of 2. Forty rules in the denominator, none in the numerator.

The direction matters. 99/169 is 59% and 99/209 is 47%, so the omission read in the flattering direction —
and the sentence beside it told the reader to run `--status`, which prints the other number. A claim that
disagrees with the command printed next to it is the easiest kind to catch and had gone unchecked anyway.

#### One thing the fix does not say, because a survey and a status run disagree

`spaze/phpstan-disallowed-calls` reads 0 of 38 in `--status` and **15 emitted of 38** when surveyed
directly. Both are right and they answer different questions: the survey transpiles every rule class in the
package, while `--status` counts the rules *this project registers*, and this project's neon includes register
generic disallowed-call rules configured through parameters rather than the 38 classes. `composer/pcre` is 0
either way — both its rules refuse on `instanceof FullyQualified`.

So "0 of 38" is not "nothing in this package is portable", and the README does not claim it is: it says the
two packages are in the denominator and not the table, which is the fact `--status` supports. Naming the
survey figure there would have been the same mistake in the other direction.

#### Verification

README 1242 words after the correction, trimmed back to 1225 against the ~1200 ceiling — prose only, in the
collapsed vocabulary block and three sentences elsewhere, with no table row, example or caveat removed. The
`--status` run is the whole evidence and it is one command; no code change, nothing to emit or diff.

### Two rule packages a checkout has and the census never looked at

`--status` counting 209 where the census counts 190 raised the question the last step did not ask: what is in
the difference. Forty rules in two packages, and the census's own header called itself "one line per rule in
the packages this repository installs", which those two are.

They are there for different reasons, and both were traced rather than assumed:

- `spaze/phpstan-disallowed-calls` is a direct dev dependency — `composer why` says
  `sandermuller/phpstan-to-mago dev-main requires (for development)` — and `phpstan.neon.dist` includes three
  of its neons. This project runs it on itself.
- `composer/pcre` ships two rules and is here for none of that: `composer why` says
  `composer/xdebug-handler 3.0.5 requires composer/pcre`.

**The first draft of this correction got that wrong**, and the wrongness is the ordinary kind: it said both
packages are "installed for this project to run on itself", which is true of one and invented for the other.
Two `composer why` calls settled it, and the sentence had already been written before either was run.

#### What is behind the difference, sized rather than adopted

    php bin/phpstan-to-mago --survey vendor/spaze/phpstan-disallowed-calls/src
    emitted: 15, refused: 23 (target: php)

    php bin/phpstan-to-mago --survey vendor/composer/pcre/src
    emitted: 0, refused: 2 (target: php)     both on `instanceof FullyQualified`

So 15 rules translate today and no census line watches them. Adding a corpus package is a decision the
guidelines describe as deliberate — a dev dependency installed so CI resolves what a contributor does — so
the census now says where the line is instead of moving it.

**`--status` and `--survey` disagree about spaze on purpose, and the census header does not repeat the
figure.** `--status` reads 0 of 38 because it counts what this project *registers*, and this project's neons
register generic configured rules rather than those classes; the survey transpiles every class in the
package. Both answers are right for their own question, which is why the header quotes the survey and names
it as one.

#### Verification

Suite 943/943, PHPStan 0, pint clean. The census's own alarm did the work: every edit to the header failed
`TracksUpstreamDriftTest` with the diff, and the file was replaced from the `.actual` beside it each time —
three times, because the first draft was wrong about `composer/pcre` and the second needed a paragraph break.
No rule line moved in any of them, which is the check that this is a header change and not a corpus change.

### The 15 that emit do not emit, and the census header said so for a day

The plan for this step was to run the differential over the 15 `spaze/phpstan-disallowed-calls` rules that
last step reported as translating, on the argument that "they emit" is not "they agree". The differential
refused to start:

    The consumer has none of the configured rule packages installed, so there is nothing to
    transpile: spaze/phpstan-disallowed-calls

The message is misleading — the package is installed, and the condition behind it is `emitted === []` — but
the fact under it is real. A plain emit run:

    php bin/phpstan-to-mago --target=php --out=… vendor/spaze/phpstan-disallowed-calls/src
    emitted: 0, refused: 38 (target: php)

**Zero, against the survey's 15.** The cause is documented on `Transpiler::transpile()` and is the whole
point of that docblock: survey mode *assumes a hook exists* for a node type with no mapping, so it can report
what a body would need behind its first structural blocker. 17 of spaze's 38 rules hook `Stmt\Echo_`,
`Stmt\Break_`, `Stmt\Goto_`, `Stmt\Global_`, `Stmt\Unset_` and the like, none of which the vocabulary maps.
`EchoCalls` alone: `no hook mapping for node type PhpParser\Node\Stmt\Echo_`.

So the answer to "would adopting the package be worth proposing" is no, and it is not a close call: it would
add 38 rules to the census denominator and none to the numerator.

#### The mistake is the one this repository names most often, made against its own warning

Last step's census header said "a survey emits 15 of spaze's 38, and nothing here watches them for drift",
next to a sentence about adopting a corpus package being a decision. Every word of that is true and the
paragraph is wrong: it invites a reader to size a package from a survey figure, which
`VERIFICATION.md` already records going wrong — "A survey reporting 4 emitted where a real run emitted 3
looked like leniency in the survey. It was the target." The docblock on the function I called says the same
thing in the same words.

The figure was labelled `survey` and that was not enough. What made it misleading was putting it where a
reader is deciding, without the emit figure beside it. The header now leads with the emit run, gives the
survey figure as the contrast, and says what the gap is.

#### Verification

Suite 943/943, PHPStan 0, pint clean. The census alarm caught each header edit and the file was replaced from
its `.actual`; no rule line moved. The emit figures are three runs — the package, `composer/pcre`, and
`EchoCalls` alone for the named refusal — and the 17 is `grep -c` over the emit output, not an estimate.

### One error message named the wrong failure, and the README opened with a mechanism

Two things this session tripped over, fixed together.

**`CorpusDifferential::emit()` printed "The consumer has none of the configured rule packages installed"
whenever nothing emitted.** Two different failures end at that line — a package that is absent, and a package
whose every rule refuses — and the message named only the first. Reading it about
`spaze/phpstan-disallowed-calls`, which is installed, has 38 rules found, and refuses all 38 on a missing
hook, sends a reader to check the vendor directory and then to doubt the path. It now states both counts:

    Nothing to transpile from spaze/phpstan-disallowed-calls: 0 of them are not installed,
    and the rest yielded 38 refusal(s) and no emission.

    Nothing to transpile from no/such-package: 1 of them are not installed, and the rest
    yielded 0 refusal(s) and no emission.

Both branches run. Stating both counts is also one branch fewer than choosing between them, which matters
here: the first version used a ternary and took the class from 80 to 81 against its complexity limit, so
PHPStan refused it. The `sprintf` is both the clearer message and the one that fits.

**Two usage docblocks documented `--packages=vendor/one`.** `CorpusDifferential` prepends `vendor/` itself,
so that spelling refuses every package. It cost a run here and a run in the benchmark last week; both lines
now read `--packages=one/rules`.

#### The README, audited against the `readme` skill

- **The opening named a mechanism, not a problem.** It read "Transpile PHPStan rules into Mago analyzer
  plugins", then explained why a rule object cannot travel. The reader's situation comes first now: you run
  Mago and still run PHPStan, because your conventions exist only as PHPStan rules.
- **Nothing in the opening may contradict the Performance section**, and the obvious problem-first sentence —
  *PHPStan is the slow part of your loop* — would have. This repository's own benchmark says the port is
  slower than a warm PHPStan on the corpus it publishes. The opening claims portability, which is what the
  measurements support.
- **1225 words to 1204**, against a ~1200 ceiling, prose only. The flag list moved from a code block to a
  three-row table — occasional-tier reference material, and two of the five flags were dropped to `--help`.
- Structure re-checked rather than assumed: 68 words before the first code block (limit ~80), longest
  paragraph 66 words (limit ~100), no line over 110 columns. The prose-majority sections that remain are the
  caveat and concept ones the skill exempts.

#### Verification

Suite 943/943, PHPStan 0 with no new baseline entry, pint clean. Both differential failure branches were run
rather than reasoned about. No `src/` change, so no emitted byte moves; every README figure in the edited
sections was measured earlier in this session and none was restated from memory.

### There is no cluster left in the census, measured three ways

The porting side has been quoted as "no remaining lever" since `02b8a3a`, on a count of needs. This step
tested that from three other directions, because a claim resting on one measurement is the shape this file
keeps recording as wrong.

**By hook.** 8 refusals in the whole census name a missing hook, and all 8 name a *different* node type —
`Stmt\For_`, `Stmt\Expression`, `Param`, `Expr\Cast`, `Expr\BinaryOp`, `ClassConstantsNode`, `BooleanAndNode`,
`BooleanOrNode`. One hook, one rule, every time. (`spaze/phpstan-disallowed-calls` has 17 in one family, but
it is not corpus and the last step measured what adopting it buys: nothing.)

**By reading the one that looked cheap.** `OverwriteVariablesWithForLoopInitRule` lists a single need behind
its hook, which is as close to a free rule as this file gets. Reading it took two minutes and killed it: it
calls `$scope->hasVariableType()`, which has no PHP rendering — measured earlier this session, it renders for
the two Rust targets only — and its `checkValueVar()` helper *recurses* on `List_` and `Array_` items, which
the census never reaches because it stops at the first obstacle. The guidelines say to rank by reading the
rule rather than counting these lines, and this is what that costs and buys.

**By what a single capability would unblock.** A need only frees a rule when it is that rule's *only* need.
22 of the 80 refused rules have exactly one, and they group like this:

     8  configuration the package never wires
     3  three different access paths — `->getType()`, `->getTraitAliases()`, a helper's method
     2  a `ClassReflection` test on a service
     9  nine distinct singletons

So one lever exists and it is the configuration cluster, which `VERIFICATION.md:826` already sized at four
real rules and left as a decision because it changes what a coverage figure counts. Everything else is one
rule at a time, and `hasVariableType` — the other candidate — appears in exactly one refused rule, which
carries four more needs including `Stmt_While`.

That is the useful shape of the answer: not "the work is hard" but "the work does not batch". A capability
here buys one rule, so it is worth building when that rule is worth having, and the census is the wrong
instrument for finding out which one that is.

#### Verification

No code change. Every figure is a parse of the committed census plus one read of a vendor rule; the
`hasVariableType` rendering claim is from this session's own measurement rather than restated from the
census.

### mago 1.47.4 to 1.47.5 closes one divergence, and the corpora say which

The installed binary was one patch behind. `composer update carthage-software/mago` moves it to 1.47.5, which
`composer.json`'s `^1.47.1` already allowed, so no tracked file changes — `composer.lock` is gitignored here.

Suite 943/943 and PHPStan 0 on the new binary, and the fires gate runs the real `mago`, so that is 564 rule
pairs re-checked against it rather than a version bump taken on trust. The four corpora:

    nikic/php-parser/lib          1693 agree   0 / 0     unchanged
    rector/rector/src              246 agree   1 / 0     unchanged
    laravel Support + Database    7998 agree   1 / 22    was 1 / 23
    nesbot/carbon/src             1807 agree   3 / 1     unchanged

**11744 against 28**, from 29. The one that closed is
`Illuminate/Database/Eloquent/Relations/Concerns/CanBeOneOfMany.php:113`, and it is one of the three
engine-level type differences catalogued two steps ago as "not a port bug":

    if ($aggregate instanceof Closure) {
        $closure = $aggregate;
    }
    …
    if (isset($closure)) {
        $closure($subQuery);      // NoDynamicNameRule reported here, and no longer does
    }

The narrowing has to survive from the assignment, through a conditionally-defined variable, to a read guarded
by `isset()`. 1.47.4 did not carry it and 1.47.5 does, so `typeIsCallable()` now answers yes and the rule
declines exactly as the original does. Traced to those two sites rather than inferred from the line number.

This is a controlled comparison by construction: same corpus, same port, same configuration, one variable.
It is also the first time a divergence in this file has closed without a change to this repository, which is
worth knowing about the remaining 28 — some of them are waiting on the other engine.

#### Verification

Suite 943/943, PHPStan 0, four corpora re-run. No `src/` change and no emitted byte moves; the README's
divergence count moves from 29 to 28. Two other upgrades are available and are not taken here, because they
are majors and a decision: `pestphp/pest` 4 to 5 (with `pest-plugin-arch`) and `phpunit/phpunit` 12 to 13.
`composer outdated` also marks `mrpunyapal/rector-pest` and `symplify/phpstan-extensions` abandoned.

### Re-reading the three engine-level divergences on 1.47.5, one recorded cause was wrong

A divergence closed on a version bump, so the causes written for the others were worth re-measuring rather
than assuming only the count moved. A probe plugin over the three files, printing what mago infers for each
dynamic call's name and what `Types::typeIsCallable()` answers for it:

    Connection.php     $callback()    type=array      typeIsCallable=false
    Migrator.php       $argument()    type=string     typeIsCallable=false
    Benchmark.php      $callback()    type=mixed      typeIsCallable=false

All three still diverge, and two of the recorded causes hold: `Migrator.php` reads `string` because mago does
not narrow on `is_callable()`, and `Benchmark.php` reads `mixed` because the closure parameter is typed only
through `Collection::map()`'s generics.

**The third was recorded imprecisely.** It said "a parenthesised `@param (\Closure(): ..)` docblock", which
names the spelling without saying what goes wrong. The type is `array` — the closure's *return* type, not the
closure. `Connection.php` settles it inside one file, three calls, one variable name:

    :704  @param  \Closure(): TReturn  $callback                                   callable
    :710  @param  \Closure(): TReturn  $callback                                   callable
    :736  @param  (\Closure(): array{query: string, …}[])  $callback               array

Same engine, same call shape, same file; only the parentheses differ. So mago resolves a parenthesised
closure type to what the closure returns, and the rule's exemption then asks whether an `array` is callable
and correctly says no. That is a mago bug rather than a port gap, and naming it that precisely is what makes
it reportable — filing it upstream is a decision, not something to do from here.

The other nine only-port findings are the trait-without-an-analysed-user divergence, which is a property of
PHPStan's traversal rather than of mago, so a mago release cannot move them and none did.

#### Verification

No code change. The probe is a throwaway plugin in the scratch directory, reading `Support::expressionType()`
and `Types::typeIsCallable()` — the same two calls the emitted rule makes, so it answers the rule's question
rather than a similar one. The three-call comparison inside `Connection.php` is the control: two spellings
that work and one that does not, with everything else held.

### The parentheses were not the cause, and a seven-line control says what is

Last step named a mago bug precisely enough to report, so this step built the minimal reproduction anyone
filing it would need. It did not reproduce:

    /** @param  \Closure(): int    $a */   →  callable
    /** @param  (\Closure(): int)  $b */   →  callable

Both read `callable`. The parentheses are not the trigger, and the sentence written yesterday — "mago
resolves a parenthesised closure type to what the closure returns" — is wrong. Laravel's line 736 carries
parentheses *and* something else, and one site cannot say which half matters.

Seven spellings, one variable at a time:

    \Closure(): int                    callable
    (\Closure(): int)                  callable
    \Closure(): list<int>              callable
    \Closure(): array{q: string}       callable
    \Closure(): int[]                  array
    (\Closure(): int[])                array
    \Closure(): array{q: string}[]     array

**The trigger is the trailing `[]`.** A generic array return is fine and an array *shape* return is fine;
`T[]` is not. mago binds the suffix to the whole `\Closure(): T` rather than to `T`, so the parameter is an
array of closures instead of a closure, and the rule's exemption then correctly declines to call an array
callable.

`mago analyze` reports nothing on any of these seven, so nothing surfaces without asking for the inferred
type. That is worth carrying into a report: the symptom is silence, not a diagnostic.

#### Two corrections in two days on one line of vendor code

The first reading called it a docblock-parsing quirk without naming the fault. The second named the
parentheses, from three call sites in one file — a real control, and still the wrong half, because all three
of Laravel's spellings that differ also differ in the suffix. Only a file written to vary one thing at a time
separated them, and it took seven rows because the first two refuted the standing answer without replacing
it.

The general lesson is the one this file already carries and this is a clean instance of: a comparison inside
found code controls what that code happens to vary. `Connection.php` varies parentheses and suffix together,
so it can rule things in and never out.

#### Verification

No code change. The reproduction is the seven-method file above, run under a probe reading
`Support::expressionType()` and `Types::typeIsCallable()` — the same two calls the emitted rule makes. Each
row is one docblock differing from its neighbour in one token, and the file is small enough to paste into an
upstream issue as it stands.

### Controls for the other two engine-level divergences, and a check on the five false negatives

Two site-read causes have turned out imprecise in as many days, so the remaining engine-level entries got the
same treatment: a file that varies one thing at a time.

> **Superseded** by *The callable check was reading a rendering, and the flag was there all along* at the end
> of this file. The three rows below were read with a probe printing `(string) $type`, and
> `ScalarType::__toString()` renders only the kind — so the `viaIsCallable` row's `string|callable` is a
> narrowed `callable-string` rendered as `string`. mago narrows on both spellings; the port did not read the
> flag. The conclusion drawn here, that this is a mago narrowing gap, is wrong.

**`is_callable()` narrowing**, `Migrator.php:857`. Three methods, one union parameter, one guard each:

    unguarded         string|Closure, no guard        string|callable   both engines report      agree
    viaIsCallable     if (is_callable($b))            string|callable   PHPStan declines         only-port
    viaInstanceof     if ($c instanceof Closure)      Closure           both engines decline     no finding

Every row predicted before the run and every one met. mago narrows on `instanceof` and not on
`is_callable()`, PHPStan narrows on both, and the rule asks `isClosureOrCallableType()` — so each engine
answers truthfully about its own inference. The `instanceof` row is what makes this a narrowing gap rather
than "mago does not narrow": it does, on the other spelling.

That is the second of the three reduced to a file small enough to hand to someone. The third,
`Benchmark.php:27`, is a closure parameter typed only through `Collection::map()`'s generics and has not been
reduced — a generics-inference gap needs the generic call chain around it, and a repro that carries Laravel's
`Collection` is not a minimal one.

#### The five false negatives, re-checked

Across all four corpora the port is silent where the original reports exactly five times:

    carbon    MessageFormatterMapper.php:42     noProtectedClassStmt    a parent declared twice, per PHP version
    carbon    TranslatorImmutable.php:24, :40   paramTypeCoverage       the same resolution chain
    rector    SimpleStaticType.php:13           noConstructorOverride   a parent inside phpstan.phar
    laravel   QueueFake.php:214                 noDynamicName           mago infers *more* than PHPStan

All five carry a written cause already. Four are class-resolution facts about a corpus, which no engine
release can move, and the fifth is inference — so it was the one worth re-measuring on 1.47.5, and the probe
says `callable|Closure` with `typeIsCallable=true`, matching the `CallableType|NamedObjectType` recorded when
it was first traced. The cause holds and the finding is unchanged.

Worth keeping in view: these are the entries where a consumer loses a real finding, and there are five of them
against 11744 agreements. Four are the port declining to guess about a class it cannot resolve, which is the
behaviour this repository asks for.

#### Verification

No code change. The narrowing control is three methods in one file, run through the differential inside this
repository and removed afterwards; the type figures come from the probe reading the same two calls the
emitted rule makes. Predictions were written before each run, in the message and in the file.

### 19 of the 28 divergences are one deliberate behaviour, and the headline hid that

Every only-port finding across the four corpora, attributed to its rule and its file:

    ReadsClassAttributes    6    trait, no user in the analysed paths
    SoftDeletes             5    trait, no user
    HasFactory              4    trait, no user
    ManagesTransactions     1    trait, no user
    MassPrunable            1    trait, no user
    Prunable                1    trait, no user
    BroadcastsEvents        1    trait, used only by a trait nothing uses
    ---                    19
    Connection.php:736      1    a `\Closure(): T[]` docblock mago reads as `array`
    Migrator.php:857        1    no `is_callable()` narrowing
    Benchmark.php:27        1    a closure parameter typed through `Collection::map()`'s generics
    CarbonInterval:3624     1    a destructuring mago types more narrowly than PHPStan

Checked rather than assumed: `grep` for a `use` of each trait inside the analysed paths returns nothing for
six of the seven, and for `BroadcastsEvents` returns exactly `BroadcastsEventsAfterCommit` — itself a trait
that nothing in scope uses. PHPStan reaches a trait body only through a using *class*, so it never analyses
any of these files, and the port does.

With the five only-original findings, that is 19 + 4 + 5 = 28.

**So two thirds of the divergence count is one documented behaviour**, recorded as not-a-defect at line 4372
of this file: the port analyses the file it is given. A consumer pointing it at a directory of traits gets
real findings that PHPStan declines to look for. Calling that a divergence is right; letting it sit in the
same total as an inference gap is what misleads, because the aggregate reads as 28 disagreements about the
same kind of thing.

The README now splits the number where it states it. That is the whole change — no behaviour moves, because
the 19 are not a defect and reproducing PHPStan's blind spot would mean building a guard into every emitted
rule to report *less* on code the consumer asked about.

#### Verification

No code change. The attribution is a parse of this run's differential output, one line per finding, and the
trait claim is seven `grep`s over the analysed paths rather than a reading of the recorded cause. README
1204 to 1213 words, the added clause paid for by trimming five sentences elsewhere.

### A fifth corpus, and what its clean run does not prove

Four corpora had all been read to exhaustion, and a green run over material already mined is the weakest
evidence available. `phpunit/phpunit` is the fifth: 1003 files, a test framework rather than a library or an
application, and the one corpus here whose own rule package — `phpstan/phpstan-phpunit` — targets the shape
of code it contains.

    vendor/phpunit/phpunit/src   1003 files   561 agree   0 / 0

**Zero divergences**, and the agreements are spread rather than piled on one rule — 18 rules produce
findings:

    128 explicitInterfaceSuffixName      13 forbiddenStaticClassConstFetch
    124 requiredInterfaceContractNamespace 10 explicitTraitSuffixName
    111 requireExceptionNamespace          9 complexity.classLike
     69 requireAttributeNamespace          8 noDynamicName
     35 explicitAbstractPrefixName         8 phpunit.noAssertFuncCallInTests
     18 noClassReflectionStaticReflection  4 noProtectedClassStmt
     16 complexity.functionLike            3 parentMethodVisibilityOverride
                                           2 foreachCeption, 1 each ×3

Five corpora now read **12305 agreeing against 28 divergences**, and the 28 are unchanged: this corpus adds
none.

#### The reading that would have been wrong

`noDynamicName`, `forbiddenStaticClassConstFetch` and `noClassReflectionStaticReflection` carry every one of
the 19 trait divergences on Laravel, and here they agree 8/8, 13/13 and 18/18. The tempting sentence is that
this supports the trait explanation.

It does not, and the check takes one command. `phpunit/src` *does* contain traits with no user in scope —
`ProxiedCloneMethod`, `StubApi`, `Method`, `MockObjectApi`, `DoubledCloneMethod` — so the shape is present.
What is absent is anything for those rules to report inside them: `grep` counts zero `protected` members and
zero static self-const fetches across all five. A divergence needs a finding to diverge about, and there was
none available.

So the clean run is strong evidence of general agreement on a corpus nobody here wrote, and **silent** on the
trait question. Recording which of those two it is matters more than the zero does.

#### Verification

No code change. One differential run, its per-rule table read rather than its total, and two `grep`s over
`phpunit/src` to find out whether the clean result discriminates. README's corpus sentence moves from four
trees and 11744 agreements to five and 12305; the divergence count and its split are untouched, because this
run added nothing to either.

### The third gap reduces after all, and it is the combination that loses the type

Two steps ago this said `Benchmark.php:27` "has not been reduced — a generics-inference gap needs the generic
call chain around it, and a repro that carries Laravel's `Collection` is not a minimal one". Wrong on both
counts: it reduces, and `Collection` is not needed.

The first attempt — a `@template` box with a `map()` taking `Closure(TValue): TReturn`, and the inferred
parameter called directly — read `callable` and did not reproduce. Adding the shape Laravel actually has, an
inner closure capturing the parameter with `use`, did. Five rows, one variable at a time:

    $callback($item)   declared Closure parameter, inside map()          callable
    $callback()        template-inferred parameter, called directly      callable
    $callback()        template-inferred parameter, captured by `use`    mixed
    $callback()        declared Closure parameter, called directly       callable
    $callback()        declared Closure parameter, captured by `use`     callable

**Neither half loses the type on its own.** Template inference survives a direct call (row 2) and a declared
type survives the same capture (row 5); only the two together drop to `mixed`. Rows 2 and 5 are what make
this a statement about the combination rather than about either feature, and both were predicted before the
run.

So all three engine-level divergences now have a minimal reproduction with no Laravel in it: a `\Closure(): T[]`
docblock read as `array`, no `is_callable()` narrowing, and a template-inferred closure lost across a `use`
capture. Each is reportable as it stands, and filing remains a decision.

#### The trait explanation was already controlled, which the last entry did not say

Yesterday's fifth-corpus entry noted the clean `phpunit` run is silent on the trait question. True, and
incomplete in a way worth correcting: the question is answered elsewhere.
`TraitMethodHookDivergesTest` runs both engines over `tests/Fixtures/TraitDivergence` and asserts
`AnUnusedTrait::inUnusedTrait` and `UsedOnlyByATrait::inChainedTrait` on the mago side and not PHPStan's,
with five non-trait shapes agreeing as the control.

So the 19 trait divergences rest on a test in the suite rather than on a reading of a corpus, and a reader of
that entry alone would conclude otherwise.

#### Verification

No code change. Each row is one method differing from its neighbour in one thing, measured with the probe
that reads the same two calls the emitted rule makes. The trait claim is a read of the assertions in
`TraitMethodHookDivergesTest`, which runs in the suite.

### An abandoned package was hiding a real type hole

`composer outdated` marks two dev dependencies abandoned. `symplify/phpstan-extensions` is the one that can
go without a decision: everything this repository used it for is in `symplify/phpstan-rules`, which is
already installed and auto-registered. Its `phpstan-extensions.neon` declares the same
`errorFormatter.symplify` the `phpstan-simplified` script asks for, and both scripts still run after the
removal — checked, because a missing formatter fails at the CLI rather than in analysis.

Removing it turned PHPStan red:

    src/Options.php:79  Parameter #7 $status expects string|null, string|false|null given

That is not a regression from the removal. It is an error the package was suppressing: among the extensions
it registered is one typing `getcwd()`, `dirname()` and `realpath()` as always `string`, and the line is

    $status = getcwd() === false ? '.' : getcwd();

which reads as guarded and is not — the second call is a fresh one, so it is `string|false` again. With the
extension installed, `getcwd()` never had a `false` to carry, so the shape was invisible. One call fixes it.

No test: forcing `getcwd()` to fail is not something a test can do here, and the guidelines say to skip a
test where the error is a narrowing fact rather than a reproducible fault. What makes it worth writing down
is the mechanism — **a type extension that lies in the safe direction hides every bug of that shape**, and
this repository installs it as a dev dependency rather than shipping it, so nothing downstream was affected.

`mrpunyapal/rector-pest` is the other abandoned one and is left alone. Its successor is a *new* require, and
the guidelines say a dependency is not added without approval; `rector.php` already guards its set list with
`class_exists`, so nothing breaks while the decision waits.

#### Verification

PHPStan 0 on both scripts, suite 943/943, pint clean. The removal is one line of `composer.json`; the fix is
one statement split in two. `composer.lock` is gitignored here, so a checkout resolving the tree afresh is
what CI does anyway.

### Ten baseline errors were a stale docblock and a guard behind a call

The last step found a real error under a suppressor, so the same question went to the other suppressor in
this repository: `phpstan-baseline.neon`, 32 entries covering 58 errors, of which 15 are the documented
complexity debt and 17 are type errors nobody had re-read.

**`Runtime\Support`, one entry, and the highest-stakes one in the file** because the runtime ships inside
every emitted plugin: `Cannot access property $text on Part|null`.

    return self::isInt($part) ? (int) $part->text : null;

Safe at runtime the whole time — `isInt()` returns true only for a `Part` — and invisible to PHPStan,
because a guard behind a call narrows nothing. The same shape as `getcwd() === false ? '.' : getcwd()` one
step ago, which is why it is worth naming as a shape: *reads as guarded, is guarded, and the analyser cannot
see it*. Making the check local fixes it without adding one.

**`ModuleEmitter`, nine errors, all one cause.** Its `module()` carried two docblocks:

    /**
     * @param mixed[][] $rules
     */
    /**
     * @param list<array{name: string, trait: string, …}> $rules
     */
    public static function module(array $rules): string

The precise one is dead — the stale `mixed[][]` wins — so every `$rule['name']` and `$rule['module']` read
as `mixed`. `lintModule()` had the same `@param mixed[][]` with no replacement at all. Deleting one docblock
and typing the other cleared all nine.

    baseline    32 entries / 58 errors  ->  28 entries / 48 errors

#### Verification

PHPStan 0, suite 943/943, pint clean. Emit-all across `php`, `analyzer` and `linter`: 213 files each side and
`diff -r` names no emitted file — only the `--out` path in four snippets. That check matters more than usual
here, because `ModuleEmitter` *writes* the Rust module and registration files, so a docblock change in it is
exactly where a silent output change would hide.

### Two more, and the reason the next four are a different job

`Emitter::emit()` declared its hook row as `array<string, string>|array<string, null>|array<string, bool>`.
`Vocabulary::HOOKS` — the only thing that ever fills it — is typed precisely, ten keys with four optional.
Under the lossy spelling `$hook['extra'] ?? ''` and `$hook['classOnly'] ?? false` both read as `bool|string`,
which is the whole of this file's non-complexity baseline. Copying the real shape onto the parameter cleared
both with no code change.

    baseline    28 entries / 48 errors  ->  26 / 46

That is ten errors in two commits, none of which needed a line of logic changed: a stale docblock winning
over a precise one, a guard the analyser could not see, and a parameter typed weaker than its only caller.
Worth naming as a class — **most of what sat in this baseline is a description problem, not a code problem** —
because it is also why the entries survived: nothing about them looks like a bug when you read the code.

#### `ExampleReader` is where that stops

The remaining four are one shape and they do not yield to a docblock. PHPStan reports `$unit` as
`array{file: string, header: list<string>, lines: list<string>, open: int}|array{lines: non-empty-list<string>}`
— the second arm is what `$unit['lines'][] = $line` leaves behind on a variable that is also assigned `null`.
The `$render` closure carries the right `@param` and PHPStan does not apply it there.

The fix that would work is structural: lift the parsing loop into a method returning
`list<array{file: …, header: …, lines: …, open: int}>`, which gives the closure a declared shape to receive
and cuts `forRule()`'s cognitive complexity, currently baselined at 36 against a limit of 20. That is a
refactor of the method that feeds the linter target's examples, so it wants its own step and its own
byte-for-byte check rather than being folded into a docblock pass.

Not done here, and named rather than left as a bare baseline entry.

#### Verification

PHPStan 0, suite 943/943, pint clean. Emit-all across `php`, `analyzer` and `linter`: 213 files each side,
`diff -r` names no emitted file. `Emitter` is the class that writes them, so that check is the one that
matters for this change.

### `ExampleReader`, where the baseline needed a code change after all

Four entries, one shape, and the last one in this file that a docblock could not answer. `forRule()` parsed
every example file inline, built each unit as an array, mutated it with `$unit['lines'][] = $line`, and
handed it to a `$render` closure whose `@param` PHPStan did not apply.

Two extractions and one restructure:

- `unitsIn(string $path): list<array{file: string, header: list<string>, lines: list<string>, open: int}>`
  gives the shape a declared boundary instead of an inline `@var`.
- `render(array $unit): string` is the closure as a method, so its `@param` is one PHPStan reads.
- **The open unit is three variables rather than one array being mutated.** That is what actually fixes it:
  extraction alone still left `Method unitsIn() should return list<array{…}> but returns
  list<non-empty-array<'file'|'header'|'lines'|'open', int|list<string>|string>>`, because appending to a
  shaped array widens the whole shape. The array is now built once, where the unit closes.

    baseline    26 entries / 46 errors  ->  22 / 41

Five errors: four type errors and `forRule()`'s cognitive complexity, which was baselined at 36 against a
limit of 20 and is now under it. That entry went without being aimed at — the extraction that gave PHPStan
its shapes is the same one that split the method.

#### The check the usual emit-all would have missed

This is the class that reads example files for the linter target's `good_example` and `bad_example`, and the
standard byte-for-byte run does not pass `--examples`, so those fields are `"<?php\n"` in it. A refactor here
is invisible to the check this repository runs by default.

    linter target, --examples=tests/Fixtures/examples, five packages
    33 files each side, diff -r clean

Run before and after with the sources copied aside and restored from HEAD, the same way every emit baseline
here is built.

#### Verification

PHPStan 0, suite 943/943, pint clean. The standard emit-all across all three targets names no emitted file
either. Ten of this session's fifteen cleared errors needed no code change; these five did, and the
difference is worth the sentence: a description can be wrong about code that is right, and mutation of a
shaped array is code the description cannot rescue.

### The Mago floor the README states, tested rather than inherited

`composer.json` requires `^1.47.1` and the README says generated plugins run under 1.47.1 or later. That
claim predates this session, and this session added two new SDK reads to the runtime — `ClassLikeMetadata->mixins`
and `FunctionLikeMetadata->static`. If either arrived after 1.47.1, every consumer resolving the floor would
break, and nothing here would have said so: the dev dependency installs the *latest* match.

So it was installed at the floor and run:

    mago 1.47.1
    ClassLikeMetadata::$mixins = true
    FunctionLikeMetadata::$static = true
    PHPStan 0    suite 943/943

The suite is the part that settles it rather than the reflection check, because the fires gate starts the
real binary for every emitted rule — 564 pairs against 1.47.1's own analyzer, not against its class shapes.
Restored to `^1.47.1` afterwards, which resolves 1.47.5 again.

The README needs no edit for this. The claim is what it already says; what changed is that it is now
measured, and a version floor is exactly the kind of statement that quietly stops being true when a runtime
grows a new field.

#### The README pass

Audited against the `readme` skill rather than only re-read. Structure holds: 68 words before the first code
block against a limit of ~80, longest paragraph 66 against ~100, no line over 118 columns. Every figure in it
was measured this session — five corpora at 12305 against 28, `--status` at 99 of 209, the per-package table,
the benchmark rows. 1213 words to 1209 against a ~1200 ceiling, prose only, nothing structural removed.

Nothing in the README states a baseline figure, so this session's fifteen cleared errors need no change
there. That is the right split: the baseline is contributor detail and `CLAUDE.md` carries it.

#### Verification

PHPStan 0 and suite 943/943 on both 1.47.1 and 1.47.5. `git status` clean after the restore, and
`composer.json` is untouched because the constraint already allowed both.

### Sixteen casts that die on a computed name, and the refusal that said the wrong thing

The largest remaining baseline entry was one message at count 16: `Cannot cast PhpParser\Node\Expr|
PhpParser\Node\Identifier to string`. Every site is the same idiom — `(string) $node->name` used to compare a
name — and it is not a description problem. `Identifier` has `__toString()` and `Expr` does not, so the cast
is a fatal on any node whose name is computed.

Reproduced before touching anything, which is what turns 16 baseline lines into a defect:

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->{'value'} > 3) { …

    REFUSE  DynamicNameComparisonRule: Object of class PhpParser\Node\Scalar\String_
            could not be converted to string

The transpiler survives — the `Error` is caught and reported as a refusal — so the visible damage is a
refusal naming a PHP type error instead of the construct. This file already says a refusal naming the wrong
obstacle is how work gets sized wrongly, and that is what this was.

`identifierName()` answers null for a computed name, the sixteen sites ask instead of cast, and the same
fixture now refuses with `numeric comparison outside the vocabulary (line 38)` — the refusal the vocabulary
meant to raise.

#### The regex converted 25 sites and nine of them were fine

Rewriting every `(string) $x->name` in the file was the obvious move and it was wrong: nine more sit where
the name is already known to be an `Identifier`, and giving those a `?string` pushed a null into
`Emitter::snake()`, `strtolower()` and three array keys — six new errors, measured by doing it and reading
them. Only the sixteen PHPStan named are converted.

One of the sixteen did need a decision rather than a mechanical swap. `freshName()` builds the name of a
generated local, and its `Variable` arm already falls back to `'value'` for a computed name; the
`PropertyFetch` arm now does the same, which is the existing answer rather than a new one.

    baseline    22 entries / 41 errors  ->  20 / 24

The class complexity entry moves 2337 to 2338, which is the helper. The guidelines call a rising number
there the cost of coverage; this one is the cost of a fix, and it is one point.

#### Verification

PHPStan 0, suite 944/944 — the new test is the sixteenth site's refusal message, asserted as text because the
outcome was a refusal either way and only the message says which. Emit-all across `php`, `analyzer` and
`linter`: 213 files each side, `diff -r` names no emitted file, which is the claim that matters here — no
rule in the four corpus packages writes a computed property name, so nothing there ever reached these casts.

### A shipped PHPStan failure, and the same fatal one shape over

**The last commit claimed PHPStan 0 and did not have it.** The fixture it added,
`DynamicNameComparisonRule`, reads `$node->{'value'}` on a node with no such property — which is the point of
the fixture — and `phpstan.neon.dist` excludes such fixtures by path. This one was not in the list, so the
analyser reported `Access to an undefined property PhpParser\Node\Stmt\ClassConst::$value` and the commit
went out red.

The sequence is the whole lesson: PHPStan ran clean *before* the fixture existed, and after adding it only
the test filter, the suite, the emit-all and pint were run. The guidelines say to run the command in the
current message rather than from memory, and this is what it costs — the claim was true when it was measured
and false when it was written. Excluded now, with the reason its siblings carry.

#### The array-key errors are the cast, again

Four entries at one shape, `Possibly invalid array key type PhpParser\Node\Expr|string`, all in one
condition:

    $value->var instanceof Variable
    && ($this->context->locals[$value->var->name]['kind'] ?? null) === 'arg'

`Variable::$name` is `string|Expr` — `$$x` makes it an `Expr` — and an `Expr` as an array key is
`Illegal offset type`. Same class as the sixteen `(string) $node->name` casts a commit ago: a guard that
reads as if it had already established a string, in a file where the idiom `instanceof Variable &&
is_string($subject->name)` is already written three lines away.

One `is_string()` in the condition clears all four, and it is the guard the neighbouring arm uses.

    baseline    20 entries / 24 errors  ->  19 / 20

Both complexity figures move with it — the class 2338 to 2339, `bindLocal()` 74 to 75 — which is one
condition each.

#### Verification

PHPStan 0, run after every edit including the last. Suite 944/944. Emit-all across `php`, `analyzer` and
`linter`: 213 files each side, no emitted file differs. No fixture this time: unlike the cast, a dynamic
*variable* name inside a rule body is refused well before this line, so there is nothing to reproduce and the
guard is the neighbouring arm's, not a new judgement.

### The baseline is complexity only

    baseline    19 entries / 20 errors  ->  14 / 14

Every remaining entry is a cognitive-complexity figure. The six type errors that were left went four ways,
and only one of them was a description problem:

- **`inlineMethod()`'s `$uses`** had no value type, so assigning it to `TranslationContext::$useMap`
  (`array<string, string>`) failed. Typed from what its four callers pass.
- **Two callers passed `$expr->getArgs()`** where `list<Arg>` is declared. php-parser types that
  `array<Arg>`; the two other callers in this file already wrap it in `array_values()`, which is a no-op at
  runtime because the array is a list. Now all four do.
- **A raw string pushed into `list<Stm>`.** `Backend::render()` takes a `Stm`, so
  `$this->context->lines[] = "{$pad}}\n\n"` is a `TypeError` waiting for anything that renders that range
  again. Nothing has hit it because the `renderRange()` above happens to run first. `block-close` renders
  `"{$pad}}\n\n"` in both backends at this indent — checked in `PhpBackend` and `RustBackend` before
  swapping, and the emit-all diff is what proves the bytes.
- **An `isset()` that was never a question.** `isset($spec[2])` guarded a third element declared `2?: string`
  in `Vocabulary::REFINEMENTS`. Rewriting it as `?? null` moved PHPStan from "might not exist" to
  **"always exists and is not nullable"**, which is the answer: the constant has one `fields` row and both of
  its specs carry the selector. The guard is gone, and the declared shape now says `2: string` because that
  is what the data is.

That last one is worth the space. The same line was reported two contradictory ways within a minute, and the
contradiction is the information: the first message is about the docblock, the second about the constant's
real contents, and only the second is a fact about the program.

#### Verification

PHPStan 0, suite 944/944, and emit-all across `php`, `analyzer` and `linter` at 213 files each side with no
emitted file differing — which is the check the `block-close` swap needed, since it changes what is pushed
rather than what is rendered. Class complexity moves 2339 to 2337: the conditional came out.

`src/Translator.php` remains the one file `pint --test` names, as it did before this change and as
`CLAUDE.md` records.

### The guideline this session corrected is stale again, from this session's own work

`.ai/guidelines/baseline.md` was corrected this morning: 33 entries became 32, and the paragraph gained the
two commands that produce the figure. Six commits later the file holds 14 entries and 14 errors, all
complexity, so the corrected number is wrong again — by the same hand that corrected it.

The fix is not a third number. The paragraph now says what is *structurally* true — the baseline holds
nothing but complexity — and leaves the count to the commands already printed beside it. A structural claim
goes stale only when the structure changes, which is a different rate.

It also records what emptying it taught, because that is the part a reader can use:

- **Most of 58 errors to 14 was a description problem.** A stale docblock winning over a precise one, a guard
  behind a call, a parameter typed weaker than its only caller, an `isset()` on an offset that always exists.
  None of them reads as a bug, which is exactly why they sat there.
- **The rest were real and none had ever fired.** Sixteen `(string) $node->name` comparisons fatal on a
  computed name, an `Expr` as an array key, a raw string in a `list<Stm>`. Each needed a fixture written for
  it, because no rule in the corpus reaches those lines — so a green suite said nothing about them.

That second bullet is the reusable part: a baseline entry can be the only evidence that a line is both wrong
and unexercised, and the two facts hide each other.

#### Verification

PHPStan 0. `composer sync-ai` reported `wrote=2, unchanged=83, deleted=0` and the diff in `CLAUDE.md` and
`AGENTS.md` is the one paragraph, read before committing. The tally in the first draft was wrong — it said
"twenty-four errors cleared, nineteen of them" from memory rather than from the commits — and is replaced by
the two figures that are checkable, 58 and 14.

### `composer qa-check` was red, and nothing in this session had run it

Every step here has run PHPStan, the suite, pint on the files it touched and the emit-all diff. It had not
run the project's own gate, which is five steps and starts with one nobody had checked:

    rector process --dry-run    17 files changed
    pint --test                 src/Translator.php
    phpstan-simplified          passing
    validate-gitattributes      failure
    test                        passing

**Rector wanted 17 files and pint one.** Some of that backlog predates this session — `AggregateRule`,
`PhpBackend`, `Runtime\Deprecations`, `Runtime\Loops` and three tests were never touched here — and some of
it is mine: the census header string this session added uses `\'` where `SimplifyQuoteEscapeRector` wants
`"..."`. Running the tools the project runs is the only way that shows up.

Both applied, and the check that makes it safe is the one `CLAUDE.md` already names for exactly this: pint and
rector have rewritten `src/Transpiler.php` wholesale before, and the snapshots proved the output untouched.

    emit-all, three targets   213 files each side, diff -r clean
    PHPStan 0                 suite 944/944

21 files changed, 163 insertions, 179 deletions, and no emitted byte moves.

#### The gitattributes failure was two thirds local state

`validate-gitattributes` wanted two entries the managed block does not carry: `.cache/phpstan-dogfood/` and
`.cache/phpstan-emitted/`. Recorded earlier in this file as an upstream gap, which was half right.

`lean-package-validator` builds its expectation from the directories that *exist on disk*.
`.cache/phpstan-dogfood/` was a stale artefact of an old dogfood run — deleting it dropped that expectation
outright, which is the measurement that separates "the block is missing an entry" from "this checkout has a
directory a clean one does not".

What remains is real and small: `.cache/phpstan-emitted/` exists because `composer phpstan-emitted` is a
documented script in this repository, so **running a documented script turns the project's own gate red**.
Removing the directory makes the gate green and the next run of that script makes it red again. The durable
fix is upstream in `sandermuller/package-boost-php`, which generates the managed block from a fixed list
rather than from the cache directories the project's own configs create. `.gitattributes` is boost-managed,
so it is not fixable from here by hand.

#### Verification

`composer qa-check` green, all five steps, run as the project runs it rather than tool by tool. The emit-all
diff is what licenses the formatting: 213 files each side across `php`, `analyzer` and `linter`, and no
emitted file differs.

### The reformatting moved nothing the snapshots could not see

The emit-all diff licensed the rector and pint pass, and it licenses exactly one thing: what the *generator*
writes. Four of the reformatted files were `src/Runtime/` — `Types`, `TypeCoverage`, `Loops`,
`Deprecations` — which is the code that runs *inside* an emitted plugin. No snapshot covers that, because a
snapshot compares generated text and the runtime is a library the generated text calls.

So the corpora were re-run, all five, against the reformatted tree:

    nikic/php-parser/lib   1693 agree   0 / 0
    rector/rector/src       246 agree   1 / 0
    laravel Support+Db     7998 agree   1 / 22
    nesbot/carbon/src      1807 agree   3 / 1
    phpunit/phpunit/src     561 agree   0 / 0

**12305 against 28, every figure identical to the run before the formatting.**

The five aggregate metrics were checked separately, because `Runtime\TypeCoverage` is one of the files rector
touched and the differential compares findings rather than totals — a metric can agree on every reported site
and still count differently underneath:

    parameters   Illuminate, 1694 files   original 17635 / port 17636   +1, the `hscan` residue
    returns, properties, constants, declares   delta +0 on Illuminate/Database

Nothing moved. That is the expected answer and it was worth the runs anyway: the suite's fires gate exercises
the runtime for 564 rule pairs, and these five corpora exercise it over 4123 files of vendor code.

#### Verification

No code change. Five differential runs and five coverage runs, each compared against the figure recorded for
it earlier in this file rather than against a memory of it.

### The gate's fragility was fixable here after all

Last step recorded `.cache/phpstan-emitted/` as an upstream problem: `composer phpstan-emitted` is a
documented script, running it creates that directory, and `lean-package-validator` then wants a
`.gitattributes` entry the boost-managed block does not carry. True, and it stopped one question short.

`lean-package-validator` enumerates one level below an ignored root. A directory nested *deeper* is invisible
to it — tested before changing anything, by creating `.cache/phpstan/emitted` and running the validator
against it:

    .cache/phpstan-emitted/     expected an entry     failure
    .cache/phpstan/emitted/     no entry expected     success

So `phpstan-emitted.neon`'s `tmpDir` moves one level down, and the cache is still its own rather than shared
with the main config. `composer phpstan-emitted` now runs without reddening the gate, with no change to a
boost-managed file and nothing waiting on another repository.

**"The fix is upstream" was the right shape and the wrong conclusion**, and the difference is one experiment:
the constraint was never "the block must list every cache directory", it was "the validator looks one level
down". Naming the mechanism instead of the symptom is what made the alternative visible.

    composer qa-check   exit 0, all five steps

#### Verification

The whole gate, run as the project runs it: rector 0 changed, pint clean, `phpstan-simplified` 0,
`validate-gitattributes` valid, suite 944/944. `AnalysesTheEmittedPluginsTest` passes, which is the test that
spawns PHPStan with the config whose `tmpDir` moved.

### "Not built" was built the same day, and I relayed it twice as an open decision

Line 872 of this file says of the unwired-configuration cluster: *"Not built. It changes what a coverage
figure counts … and that denominator is a decision rather than a measurement."* That was `da98469`. The
commit after it, `039c5f0` on the same day, is *"Read a rule's configuration off the project that registered
it"* — and it built exactly the thing.

`Transpiler::takeConsumerValue()` exists, `Transpiler::$consumerConfiguration` is set by `Cli` from
`--from-config`, `RegisteredRules` captures `arguments` off the constructed rule objects, and the docblock on
`takeConsumerValue()` states the case in the note's own words: *"This is for the rules a package registers
nowhere, where the consumer is the only place values exist."*
`AsksPhpstanWhichRulesAreRegisteredTest::test_the_emitted_plugin_carries_what_the_project_configured()` is
the test.

The denominator worry dissolved rather than being decided: the tool has two modes and always did. A package
run refuses the rule, a `--from-config` run carries the consumer's value, and the two counts answer different
questions — which is what `--status` and `--from-config` are documented to mean.

**This session reported that cluster to the user twice as four rules blocked on a decision.** Both times from
this file, both times without checking whether the note was still true. A dated record of a measurement stays
valid; a sentence about what is *not built* expires the moment someone builds it, and nothing marks the
difference when you are reading.

What is genuinely unmeasured is narrower and worth stating as such: whether those four particular rules emit
under a consumer that registers them has not been run here, because no project in this repository registers
them. The mechanism is tested on a fixture; the four are inferred from the mechanism.

#### Verification

No code change. Two `git log -S` runs order the note and the feature, and the ancestry check confirms the
note came first. The feature's own test passes in the suite.

### Sweeping this file for claims that expire

One stale "not built" was found by accident, so the rest of the file was swept for the same shape:
`Not built`, `left open`, `decided against`, `not attempted`, `is the instrument`, `no plugin can close`.

Nine matches, and the encouraging part is that seven had already been superseded by a later section, which
is this file's convention working:

- `Left open rather than folded into this commit` — the next entry opens with *"Last commit left
  `paramTypeCoverage` open as 'a different defect ... new information'. It was neither."*
- `the number is not reproduced by anything I could build` — two lines later: *"That number is traced now,
  and it was the instrument."*
- `Not closable in the port` (QueueFake) — re-measured on mago 1.47.5 this session and still true.
- `Not a defect to fix` (the trait divergence) — still the position, and `TraitMethodHookDivergesTest`
  asserts it.

Two were not, and both misled a reader — me. The unwired-configuration cluster, corrected in the entry above
this one, and `run-coverage-setdiff.php` named as *the* instrument for the +1310 when the answer turned out
to be a directory bisect. Both now carry a `> **Superseded.**` pointer at the claim itself.

#### The convention was working and unwritten

Appending a correction is what this file does, and nothing said so. A reader arrives by `grep`, lands on a
sentence, and has no way to know a later section revisits it — which is exactly how one sentence became two
reports to the user of a decision that had already been made.

The header now states it: append-only, later beats earlier, and a superseding entry leaves a pointer behind.
That is cheaper than editing the record, which would destroy what makes it a record.

#### Verification

No code change. Nine `grep` matches read in place, four confirmed still true, two given pointers, three
already carrying their own correction in the following paragraph.

### The consumer-configuration path adds nothing here, and the reason is the corpus

The last entry said the unwired-configuration mechanism is built and tested on a fixture, and that whether
the four real rules emit under a consumer registering them was *inferred from the mechanism* rather than run.
It is one command, so it was run.

    php bin/phpstan-to-mago --from-config=. --target=php

    458 rules registered, 339 of them PHPStan's own, 119 to carry across 119 files
    emitted: 34, refused: 85

Then the same question asked properly — is any of those 34 a rule the *package* run refuses?

    comm -23  (from-config emissions)  (every package's own emissions)
    (empty)

**Nothing.** Every rule this project's configuration carries also emits from its package alone, so the
consumer-value path unlocks zero rules here. That is the predicted answer and now a measured one, and the
cause is the corpus rather than the mechanism: this repository registers none of the eight rules whose only
blocker is a constructor parameter the package never wires.

So the record for that cluster is now three sentences rather than one: the mechanism exists, a fixture proves
it carries a project's values, and the one consumer available here cannot exercise it. Whoever has a project
registering `NoUnsafeRequestDataRule` or `ForbiddenNewArgumentRule` can settle the four in a single run.

#### A cross-check that fell out of it

Emitting every installed rule package one at a time and sorting the names gives **107**, which is exactly the
census's `EMIT` count. Two paths that share no code above the transpiler — a census generated by a test, and
nine CLI runs — agree on the number. That is worth more than either figure alone, and it was free.

#### Verification

No code change. One `--from-config` run, nine package runs, one `comm`. The 107 is `sort -u` over the CLI
output and `grep -c '^EMIT'` over the committed census.

### The twenty the survey adds nothing to, counted

`Before the descent, 28 of 80 refused rules said only what their emit run already said; 21 do now. What is in
that 21 has not been read rule by rule` — that sentence has stood since the descent landed, and the count is
a parse of the committed census rather than a reading of twenty rules.

It is 20 today, not 21, because the census has moved since. The split:

     8  configuration the package never wires
     8  inside the body
     2  a PHPStan service
     1  the rule delegates its findings to a helper that builds them
     1  a value the vocabulary cannot read

**12 of the 20 refuse before the body is reachable at all.** That is the useful half: no body-level
capability moves them, so a ranking built from what their bodies need would count twelve rules that no such
work reaches. The file's guess — "some refuse inside one expression, and some before any statement is
reached" — was right in shape and is now a number.

The 8 that are in the body do not cluster either. Two are access paths (`->getType()`,
`->getTraitAliases()`), two are assignment values built from a `find()` or a search filter, one is a helper's
`if` shape, one a missing node predicate, one a `foreach` guard body, one a helper call on an injected
collaborator. Eight rules, eight capabilities — the same answer the census gave from every other angle this
session.

#### What this was worth

Nothing here changed behaviour, and the reason to do it anyway is that "has not been read rule by rule" is a
sentence a reader treats as a *gap*, and it was not one: the census already held the answer, and the answer
agrees with everything else measured about this corpus. A parse turned an admission into a figure in one
command.

#### Verification

No code change. One parse of `tests/Fixtures/expected/census.md`, counting rules whose whole `needs` list is
their first refusal, bucketed by what that refusal names. The eight body-level ones are printed in full above
rather than summarised, because "they do not cluster" is a claim a reader should be able to check.

### The callable check was reading a rendering, and the flag was there all along

Three days of this file describe `Migrator.php` as a **mago** narrowing gap: "mago narrows on `instanceof`
and not on `is_callable()`". A peer session ran the shipped binary against the same construction and
contradicted it — mago's own `invalid-argument` message names the narrowed type, and it is `callable-string`.

The instrument was the fault, and it is one line of the SDK:

    final class ScalarType implements AtomicType
    {
        public function __toString(): string
        {
            return $this->kind->value;
        }
    }

**A `callable-string` renders as `string`.** So does a `numeric-string`, a `non-empty-string` and a literal —
`ScalarType::__toString()` returns the kind and drops the refinement entirely. Every reading in the superseded
entries came from a probe printing `(string) $type`, which cannot distinguish a narrowed string from an
un-narrowed one. The six-row table sent to that peer is retracted whole, not only its conclusion.

Re-measured at the atomics, same six methods, same node position, with `Types::typeIsCallable()` answering
beside the flag:

    mixed $a                CallableType                            port true
    string $b               ScalarType(callable=TRUE)               port FALSE
    string|Closure $c       ScalarType(callable=TRUE)|CallableType   port FALSE
    string|int $d           ScalarType(callable=TRUE)               port FALSE
    callable|string $e      ScalarType(callable=TRUE)|CallableType   port FALSE
    mixed $f, is_string     ScalarType(callable=false)              port false

`mixed` is the one row a rendered name gets right, and it is the only row the earlier probe table looked at —
which is why that table concluded the port was not missing an `is_callable()` case. The `is_string` row is the
control: the flag discriminates rather than standing true everywhere.

So there was never a mago bug here. `Types::isCallableAtomic()` reached the `StringType` refinement already
and read only `literalValue` off it, never `callable`. One clause fixes it.

#### What it closes, before and after, on the same trees

    Laravel Support + Database   337 files   agree 7798   1 / 24  ->  1 / 23
    nesbot/carbon/src            914 files   agree 1924   1 /  1  ->  1 /  1

One finding removed, `Migrations/Migrator.php:818`, and nothing else moves across 1251 files: no agreement
lost, no new only-original. The removed finding does not become an agreement, because PHPStan reports nothing
there either — an exemption on both sides is silence on both sides.

Against the recorded five-corpus figures that makes it **12305 agreeing against 27**, split 19 traits / 3
inference gaps / 5 the port misses. The agreement count is unchanged for the reason above, measured rather
than assumed.

#### The shape worth carrying

Four wrong causes have now been published in this file and to that peer, and all four are one shape: a
mechanism that explains the measurement, asserted before the convention it rests on was checked. This one
adds the sharper version — **the measurement itself can be an artifact of the instrument, and a source read
cannot catch that.** Both refuted findings survived reading the analyser's source and died to one run of the
shipped binary.

The rule that follows is narrow enough to act on: where a value will be compared or branched on, read the
*model*, never a rendering. A rendering is a lossy projection chosen for a human, and `__toString()` on a
type is the most tempting one in this codebase.

#### Verification

Test before fix, and the failing run is recorded: `ReadsTheCallableStringRefinementTest` asserted the flag
green and the predicate red on all four rows. Mutation-checked by dropping the `->callable` read, which turns
the `is_string` control true and fails. Full suite 946/946, PHPStan 0, pint clean, baseline still 14 entries
and 14 errors with no new complexity entry. Emit-all across php, analyzer and linter — 127, 34 and 25 files —
byte-identical apart from the `--out` path the snippet embeds, which is what a runtime change should be.

### Finding 3 was never a divergence, and eight rows plus the real site say so

The third engine-level entry has stood as "a template-inferred closure lost across a `use` capture", reduced
to five rows and offered as reportable. A peer session could not reproduce it. Re-measured at the atomics,
because the entry above proves a rendering is not evidence, it does not survive in any form.

Eight rows, one generic container, one thing varied at a time, both engines on the same file:

    A  self<Closure> element, read directly        PHPStan Closure   mago CallableType
    B  self<Closure> element, through `use`        PHPStan Closure   mago CallableType
    C  declared Closure, read directly            PHPStan Closure   mago CallableType
    D  declared Closure, through `use`            PHPStan Closure   mago CallableType
    E  mixed element, read directly               PHPStan mixed     mago MixedType
    F  mixed element, through `use`               PHPStan mixed     mago MixedType
    G  `iterable<array-key, TWrap>|TWrap`, direct  PHPStan mixed     mago MixedType
    H  the same union, through `use`               PHPStan mixed     mago MixedType

**The `use` capture does nothing.** Every pair of rows differing only in the capture agrees with itself — A/B,
C/D, E/F, G/H — so the mechanism named in the earlier entry is not a mechanism. What moves the answer is the
element type, and rows G and H carry Laravel's own annotation from
`Collections/Traits/EnumeratesValues.php:127-130`, where the template appears bare and as an element in one
union. Both engines bind it to `mixed` there. That is agreement, not a gap.

Then the real site rather than a model of it, `Illuminate/Support/Benchmark.php`:

    mago      $callback() inside measure()   MixedType,   typeIsCallable false
    mago      $callback() inside value()     CallableType, typeIsCallable TRUE
    PHPStan   the same statement in measure() mixed

The `value()` row is the control: it carries `@param (callable(): TReturn) $callback` and mago reads it, so
the `mixed` next to it is not mago failing to read docblocks. **PHPStan reads the same `mixed`.** Whatever
made the recorded run report on one side and not the other, it is not that one engine's inference reaches
further than the other's — at that statement they reach the identical answer.

And on the trees available now the site is not a divergence at all: `Benchmark.php` appears in both the
before and after Laravel runs only as *same site, different message*, which the instrument counts as
agreement, and never as only-port.

#### What is retracted and what is unknown

> **Narrowed** by *The Benchmark unknown, narrowed to one place it cannot be* below. The version branch is
> closed for the call site as well as the annotation, and the site is an agreement today rather than quiet on
> both sides — both engines report there. Read that entry for what is left unknown.

Retracted: the cause, and the claim that the construction is reportable. The five-row table it rested on is
withdrawn with the six-row one above it.

**Unknown, and marked as such:** why the recorded run produced an only-port finding there. Two candidates and
no evidence separating them — the recorded corpus is a different Laravel version, so its
`Collection::wrap()` annotation may have bound the template differently; or the attribution of that finding
to this line was wrong when it was written. Settling it needs the exact tree the recorded run used, which
this repository does not hold. It is not settled by anything above, and the sentence stays until it is.

So of the four engine-level divergences this file has carried, two are filed upstream by a peer session on
measurements that held, one turned out to be this repository's own defect, and one was never a divergence.
That is the whole of them; none is left open as a mago bug awaiting a report.

#### Verification

No code change. Two runs over one eight-row subject — the real `mago` binary through a probe reading
`Type::$atomicTypes`, and PHPStan's own `dumpType()` at the identical positions — plus the same pair over an
unmodified copy of `Benchmark.php` resolved against a real Laravel tree. Row A/B, C/D, E/F and G/H pairings
were the prediction and each was written before its run. The `value()` reading is a control inside the same
file rather than a separate fixture, so it cannot come from a different configuration than the `measure()`
reading beside it.

### The Benchmark unknown, narrowed to one place it cannot be

The entry above left "why the recorded run reported on one side only" unknown, with two candidates: the
recorded Laravel version bound the template differently, or the attribution was wrong. A peer session closed
the first on the annotation text. Checked here independently and extended, because the annotation is not the
only thing that could have moved:

    12.65.0  12.69.1  13.7.0  13.17.0  13.26.1  13.29.0   `wrap()` docblock identical in all six
    12.65.0  12.69.1  13.7.0           13.29.0            `measure()` identical, `$callback();` at line 27

Six local Laravel trees for the annotation and four for the call site. `wrap()`'s signature gained `...$args`
in 13, and its docblock did not change. **The recorded `Benchmark.php:27` is this exact statement**, so the
version branch is closed for the site and not only for the annotation it resolves through.

Then the direct measurement the entry above should have made, one rule and one file rather than a corpus:

    port      Transpiled\NoDynamicNameRule   Benchmark.php:27  symplify.noDynamicName
    original  Symplify ... NoDynamicNameRule Benchmark.php:27  symplify.noDynamicName

**Both engines report there, same identifier, same message.** So the site is an agreement today — not a
divergence that closed by going quiet on the port side, which is what "never as only-port" left open above.
The port still reports; PHPStan now reports with it.

That inverts which side has to have changed. The recorded finding was only-port, so the port reported then as
it does now, and it is PHPStan's answer that differs between then and now. `composer.json`'s `phpstan/phpstan`
constraint has not moved across the range, and `composer.lock` is gitignored here, so which version was
installed for the recorded run is not recoverable from this repository.

**Still unknown, and now the only branch left:** whether PHPStan declined at that statement in the version
installed then, or the finding was attributed to the wrong line when written. Nothing here separates those.
What is no longer unknown is the part that mattered — both engines read `mixed` at that statement and both
report on it, so no version of this can be an inference gap between them.

#### Verification

Two `grep` sweeps over six and four installed Laravel trees, printed above rather than summarised. Then one
transpile of `NoDynamicNameRule` alone, run through the real `mago` binary over an unmodified copy of
`Benchmark.php` resolved against a real Laravel tree, and the original rule registered alone in a PHPStan
config over the same copy. One rule and one file on both sides, so neither answer can come from another
rule's finding at a nearby line — which is what made the corpus reading above weaker than it looked: an
agreement is counted and not printed there, so "never as only-port" cannot distinguish "both report" from
"neither reports". It was the former.

### PHPStan 2.2.13 is 13% cheaper cold, and the warm row is not separable

The README's performance table was a 2.2.12 measurement. Re-measured on 2.2.13, and because a single pair of
runs makes the *version* comparison n=1 however many times each row is repeated, the pair was run twice in
alternating order. `vendor/nikic/php-parser/lib`, 270 files, 80 emitted rules, n=3 per row per run.

    row                            2.2.13 (a, b)      2.2.12 (a, b)     read
    mago, engine only     CPU      3.87  3.95         3.82  3.98        control, interleaved
    mago + 80 rules       CPU      7.21  7.34         7.27  7.55        control, interleaved
    PHPStan cold          CPU      8.64  8.83         9.80  10.21       no overlap, -13%
    PHPStan cold          wall     2.74  2.78         2.88   3.13       no overlap,  -7%
    PHPStan warm          CPU      0.87  0.88         0.72   0.81       not established
    PHPStan warm          wall     0.89  0.90         0.76   0.89       not established

**The two mago rows are the control, and they are what makes the cold delta readable.** PHPStan's version
cannot change them, so a machine drifting under the comparison would show up there. It does not: both
versions' mago readings interleave, within 4%.

**Cold holds. Warm does not, and the reason is worth stating rather than rounding away.** Both 2.2.13 warm
readings sit above both 2.2.12 readings, so the direction is *slower*. But 2.2.12's own two runs differ by
0.09s on a 0.72s baseline — 12%, larger than the 0.06s gap to 2.2.13's pair — and the widest wall spread in
any row measured all session was 0.43s on one of those warm rows. n=2 against that noise establishes
nothing. Reported as not established rather than as a small regression.

#### The machine was contended, and by something not ours

Load average ran 3.6 to 9.9 throughout, first from this session's own differential and suite runs and later
from an IDE at 105% CPU. There was no idle window to wait for. That is exactly the case the measurement
guidelines name — CPU and counts survive contention, wall clock does not — so the README now says to read
the CPU column and prints the load with it.

What contention cannot do is manufacture the cold separation. It inflates both versions, and the mago
controls show it inflated them by the same few percent while cold CPU moved by 13%.

#### What the README says now

All four rows are the minimum across the two 2.2.13 runs, so they come from one configuration rather than
being assembled from the best of each. The marginal cost is restated in CPU alone at **3.34s**, and the
comparison against a cold PHPStan moves from 1.3x to **1.20x cheaper**.

Two claims were **deleted rather than carried forward**: the wall-clock marginal cost, and the split of it
into "five aggregates are 1.21s, the other 75 rules 0.70s". Both belonged to the 2.2.12 run. The split is not
re-derivable from this instrument, and keeping it beside a changed total would have left three numbers that
no longer add up.

#### Verification

Four full benchmark tables, two per version, alternating 2.2.13 / 2.2.12 / 2.2.13 / 2.2.12 so drift over the
session cannot align with the version. Each row best-of-three with its wall spread printed. `composer.lock`
is gitignored here, so the installed version is not tracked and CI resolves 2.2.13 on its own; the local tree
was returned to 2.2.13 after the last control run, checked with `phpstan --version` rather than assumed.

### The unmapped tail does not cluster on PHPStan's interfaces

A peer session ranked the remaining coverage work and asked one question worth a query: do the unmapped
methods cluster by declaring class? If most sit on `Scope`, `ClassReflection` or `Type`, mapping an
interface's surface systematically beats per-method work and the ceiling changes shape.

It does not. Every method call named in a census `needs:` line, by receiver:

    collaborator ($this->x->)   17
    $scope->                     7
    $node->                      6

Seventeen of thirty are on **collaborators a rule package wrote for itself** — `$this->standard->
prettyPrintExpr()` (php-parser's printer, 3), `$this->reflectionProvider->getFunction()` (3),
`$this->classConstructorTypesResolver->resolveClassConstructorNamesToTypes()` (2), then `testMethodsHelper`,
`repositoryClassResolver`, `seePhpDocTagNodesFinder`, `phpDocResolver`, `parentClassMethodNodeResolver`,
`fileTypeMapper`, `dataProviderHelper` and `phpVersion` at one each. Different class, different package, one
or two rules apiece. `Scope` is the only real interface cluster, and it is seven calls over five methods.

So the tail is linear, and most of it is not "map a method" but "resolve a call into a package's own
collaborator" — the family already measured at line 4372 of this file, where cross-class resolution was
implemented on the theory that it was a whole package's blocker and **changed the count by zero**.

Stated standing: 30 calls with a parseable receiver out of 45 distinct methods named. The other 15 appear in
needs text that names no receiver, and are unresolved. A heavy `Scope` skew among those would shift the
picture; nothing here rules it out.

#### And the blocker set was already whole, which retires the enabler above it

The peer's first item was multi-blocker reporting, on the premise that every ranking either side holds is a
first-blocker histogram. Not here — the census has printed the full set per refused rule since the descent:

    80 refused rules, 269 distinct need entries, mean 3.36

     0 needs   2 rules       4 needs  14 rules
     1 need   22 rules       5 needs   6 rules
     2 needs  13 rules       6 needs   6 rules
     3 needs   8 rules       7 needs   7 rules
                            11 needs   1 rule
                            15 needs   1 rule

**22 of 80 are single-need.** A count of rules blocked by exactly one unsupported *method* is a different
number on a different axis — a rule blocked by one method plus a statement kind is single-method and not
single-need — and the two are not interchangeable in a ranking.

#### What the same exchange did not settle

Two of the five items are not measurement questions and are recorded as open rather than answered.

**Partial emission.** A rule covering three of four operand reads fails
`EmittedRuleFiresTest::test_the_emitted_plugin_agrees_with_phpstan`, which is an `assertSame` over the
example pair — but only *if the pair exercises the fourth*. So emitting partial rules needs a gate exemption
or an example pair that avoids the missing read, and the second is an example curated to hide a gap. Refusing
stays the answer, on the invariant's own argument: a plausible-but-wrong rule is the failure mode to design
against because you would trust it. `ACCEPTED_DIVERGENCE` is metric-level for the related reason — a metric's
bound is checkable, a rule's is "sometimes misses".

**Selection by transpilability rather than by value.** Conceded, and it is the one item neither session can
currently measure. Every candidate either of us ranked is ranked by how few vocabulary entries it needs, not
by whether anyone relies on the rule. `--from-config` is the nearest instrument — it reports what a project
actually registers, which is a usage signal — and it has been run against one project. Several would be an
answer; nobody has collected them. Recorded because it makes every other item on that list an optimisation
of a number whose relationship to usefulness is unestablished.

#### Verification

No code change. Two parses of the committed census: one extracting every `$receiver->method()` in a `needs:`
line and bucketing by receiver kind, one counting distinct needs per `REFUSE` block. The `Refusal::$permanent`
and gate-granularity claims are reads of `src/Refusal.php`, `src/PackageCoverage.php:139` and
`tests/Unit/EmittedRuleFiresTest.php` rather than inferences from their names.

### The message capability is one rule, and the fix that showed it nearly said sixty

A peer session ranked `could not find the reported message` as the highest-leverage capability in the tool,
from a first-blocker count of 12 across the installed packages. The needs pass could not check that, because
it never records a refusal that *ends* the pass — everything stepped over is collected and whatever finally
stops it was discarded. So the family appeared as a need exactly zero times.

Fixed, and the first version of the fix was wrong in the worst available direction.

**Recording the terminal refusal unconditionally added the label to most refused rules in the corpus.** The
message a rule reports is built by a statement, so any rule with a stepped-over statement reaches the end
without one and terminates on that same refusal. It is a third artefact of stepping over a statement, beside
`unknown local $x` and `outside a loop`, which this pass already filters for the identical reason.

Had it shipped, the family would have gone from 0 needs to roughly 60 and read as the largest capability here
by a wide margin — **confirming the peer's ranking, with a number, from the instrument built to stop exactly
that kind of ranking.** They could not have distinguished it from a real result. Neither could a reader of
this file.

Conditioned on the pass having stepped over nothing — where nothing can have removed the message — it adds
**two lines**, and both are rules that carried a reason and no needs line. Those two were the only such
entries in the file, which is the consistency check the unconditional version failed and the conditional one
passes.

#### What the sizing then says

    message family, as a first blocker, 9 installed packages   12   (11 of them spaze)
    message family, as a need, the 7 census packages            1

So it is one rule where needs data exists, and the 12 is real but concentrated in a package the census does
not cover. `LockedCorpus::PACKAGES` is seven packages by design — spaze is in the census's denominator and
out of its table because it emits nothing — so sizing spaze's eleven means changing what that file is for,
which is not a measurement decision.

Recorded because the number will be quoted, and because "12" and "1" are both true of the same capability on
different axes. Print the axis next to the count.

#### The peer's own corrections, for the record

`Vocabulary::HOOKS` maps **39 distinct `NodeKind` cases of 227**, not the 62 first reported — that figure came
from an unscoped grep catching `'kind'` keys in three other tables. 17% of the node surface rather than 27%.
Their two structural findings hold and are checked in the enum: ten kinds carry a `*Construct` suffix
(`IssetConstruct`, `PrintConstruct`, `ExitConstruct` and so on), so a name-based lookup from `Expr\Isset_`
misses all ten, and `Else` is two cases rather than one.

And the bound on what mapping those buys: 17 of spaze's 38 refuse at the hook and the other 21 refuse
elsewhere, so every kind mapped moves that package from 0 emitted to **at most** 17 and plausibly fewer. No
number until it is run.

#### Verification

Test before fix, failing on the right assertion — `ClassAttributeRequiresPhpVersionRule` reports the message
as a need — and the unconditional version was caught by the census alarm rather than by review. Suite
947/947, PHPStan 0, pint clean, and emit-all across php, analyzer and linter byte-identical apart from the
`--out` path, which a change to a measurement instrument should be. The `HOOKS` figures are a read of
`Vocabulary::HOOKS` through the autoloader rather than a grep, after a grep is what produced the wrong one.

### A hook row is not a rule, measured on the one that looked cheapest

The hook-mapping family is the largest first-blocker group across the installed packages — 25 of 124 refusals
— and a peer session established that every `NodeKind` it names already exists in the SDK enum, calling the
work "table entries against an enum that already has the cases". That is true and it is not the same as a
rule emitting, which they said and which is now measured.

`OverwriteVariablesWithForLoopInitRule` was the cheapest case in the corpus: one rule, one kind, and `For`
sits in the enum beside `Foreach` and `While`, which are already mapped. Adding the row:

    before   no hook mapping for node type PhpParser\Node\Stmt\For_
    after    no mapping for ->init on a hook-node

**The rule still refuses.** The hook was never the whole blocker; it was the first one, and behind it is a
field mapping for the three child slots of a `for` — which needs a `Support` helper, a PHP rendering and a
Rust one, not a table row. So the family's cost is a hook row *plus* whatever each rule reads off the node,
and the second half is invisible until the first is done. Nobody should quote a rule count for this work.

#### Why the row stayed anyway

It changes exactly one census line, and that line now names the obstacle that is actually there. A refusal
naming a solved obstacle is how work gets sized wrongly, which is this file's oldest recurring lesson.

The row is also exercised rather than asserted. No emitted plugin uses it, so its `trait`, `method` and
`kind` strings would never have been loaded by mago and a wrong one would sit undetected — the same silence
as a rule that emits and never runs. A probe registering `NodeKind::For` against a file holding all three
loop forms:

    For       for ($i = 0; $i < 3; $i++) { echo $i; }
    Foreach   foreach ($xs as $x) { echo $x; }
    While     while (false) { echo 1; }

`Foreach` and `While` are the control: both are mapped and shipping, so a run where only they appeared would
mean the target list works and `For` does not. All three dispatch. The `trait` and `method` values are copied
verbatim from the three `StatementHook` rows already in the table.

#### A side finding, and a one-line fix

`--status` writes `phpstan-to-mago/index.md` and `index.html` under `--out`, which defaults to the current
directory. That is documented behaviour and the README tells a reader to run it — but neither file was
gitignored here, so following the README in this repository left two untracked files. Named in `.gitignore`
rather than the directory, per the rule that an exclude should name what you own.

#### Verification

Census diff read before it was accepted: one line, the refusal text. Emit-all across php, analyzer and linter
over five packages plus the fixtures, byte-identical apart from the `--out` path — no rule's output moved,
which is what a row nothing emits with should do. Suite, PHPStan and pint below. The dispatch probe is the
running binary rather than a reading of the enum, because the enum is what was already known.

### mago 1.47.6 fixes the compound-assignment operand, and the six now cost a floor rather than a policy

`#2311` was filed by a peer session and fixed the same day, released in 1.47.6. Verified here against the
external index the CLI does not expose, which is the only instrument that can see it — one probe file, both
versions, nothing else changed:

    row                              1.47.5        1.47.6
    $a /= $b   right operand         int|float     null|int
    $c += $d   right operand         int           null|int
    $g / $h    right operand         null|int      null|int      control, binary form
    $e = $f ?? 0  right side         int           int           control, plain assignment
    $u = $g / $h  whole expression   int|float     int|float     control, the result type

Both compound rows now answer the operand's **own** type and match the binary control exactly. The three
controls do not move, which is what separates "the compound-assignment read was fixed" from "the probe reads
types differently now" — and the last control matters most: `int|float` is still what the *expression*
produces, so the fix did not remove that type, it stopped the operand read returning it.

#### What it changes, and what it does not

The partial-emission question is retired rather than decided. The objection was that a rule covering three of
four operand reads is a plausible-but-wrong rule, and the fires gate's `assertSame` over the example pair
leaves only a gate exemption or an example curated to hide the gap. On 1.47.6 there is no fourth read to
miss.

The refusal is one table row — `Vocabulary::KINDS_WITHOUT_OPERAND_TYPES = ['Assignment' => 1]` — so clearing
it is an edit, not a feature. **What is not cheap is the consequence.** An emitted plugin that reads an
`Assignment` operand is correct on 1.47.6 and silently wrong on 1.47.1 through 1.47.5, where the same read
answers the expression's result type. The README's `1.47.1 or later` is a shipped claim, and six rules that
need 1.47.6 either raise that floor for everyone or need a per-rule statement the manifest has no field for.

That is a versioning decision, not a measurement, and it is left open here deliberately. Adopting 1.47.6 also
moves the differential baseline: the release carries roughly fifteen other analyzer fixes — narrowing, isset
roots, reconciliation precision, foreach array types — several of which could move corpus findings either
way. None of that is measured, and the bump should be measured before it is taken rather than after.

#### Verification

Both runs are the same probe file, the same worker and the same `mago.toml`, differing only in the installed
binary, with `mago --version` read on each side rather than assumed. The pin was restored to 1.47.5
afterwards and the version re-read to confirm it. `composer.lock` is gitignored here, so nothing tracked
records either version and no committed file changed for this entry.

### The 1.47.6 bump moves nothing that ships, and the zero has a control

The peer session that filed `#2311` expected its release to be "noisier than this one fix" — roughly fifteen
other analyzer changes in 1.47.6, several of which could move corpus findings either way. Unmeasured, and
that is the thing standing between the fix and a decision, so it was measured rather than expected.

Four differential runs, two corpora, one version each, the same trees and the same consumer configuration:

    Laravel Support + Database   337 files   1.47.5   agree 7798   1 / 23
                                             1.47.6   agree 7798   1 / 23
    nesbot/carbon/src            914 files   1.47.5   agree 1924   1 /  1
                                             1.47.6   agree 1924   1 /  1

**Identical on every axis**, not only in total: the per-finding lists diff clean and so do the per-rule rows,
so nothing moved position and nothing cancelled out. Equal counts hiding a compensating pair is the failure
this checks for, and it is not what happened.

#### Why the zero is evidence here, when agreement on zero usually is not

Two tools reporting nothing is equally consistent with "nothing moved" and "the instrument never looked", and
this file's own rule says so. Two things separate them.

**The instrument is known-sensitive at a granularity of one.** Earlier in this session the same instrument on
the same two corpora moved 24 to 23 only-port on Laravel and stayed put on carbon, for a one-line runtime
change. A single finding is detectable, and was detected.

**And the compound-assignment fix is invisible to this measurement by construction**, which is the honest
qualifier rather than a hedge. The differential runs emitted rules; no emitted rule reads an `Assignment`
operand, because the six that would are refused for exactly that reason. So the fix cannot show up here, and
the zero is a statement about the *other* fifteen changes — which is the thing that was unknown.

#### What this does and does not settle

Settled: adopting 1.47.6 does not disturb any of the 99 rules that currently emit, across 1251 files.

Not settled, and still not a measurement: the floor. A plugin reading an `Assignment` operand is correct on
1.47.6 and silently wrong on 1.47.1 through 1.47.5. Six rules needing 1.47.6 either raise `1.47.1 or later`
for every consumer or need a per-rule version statement the manifest has no field for. That remains a
versioning decision with a semver consequence, and no run changes it.

#### Verification

`mago --version` read before each pair and after the restore rather than assumed, and the pin is back at
1.47.5. Findings compared three ways — total, per-finding list, per-rule row — because the first alone cannot
see a compensating move. `composer.lock` is gitignored, so no tracked file records either version.

### The cheapest rule in the largest refusal family is unreachable, and the needs list could not say so

The hook-mapping family is the largest first-blocker group, and `OverwriteVariablesWithForLoopInitRule` was
its cheapest member: one rule, one kind, `For` already in the SDK enum beside two mapped siblings. Mapping the
hook moved it to `no mapping for ->init`. Reading the rule rather than the refusal shows why that is still not
the bottom.

`processNode` walks `$node->init`, keeps the `Assign` entries, and for each target asks:

    $expr instanceof Node\Expr\Variable
    && is_string($expr->name)
    && $scope->hasVariableType($expr->name)->yes()

**The rule reports only where the loop variable was already defined**, which is the whole finding — a `for`
that introduces a fresh `$i` is fine and one that clobbers an existing `$i` is not. So a port needs to ask
whether a variable is already defined at that point.

Probed rather than assumed, two methods differing in exactly that:

    $i = 'already here'   lhs=$i  type=NULL     defined before the loop
    $i = 0                lhs=$i  type=NULL     the loop's own init, same name
    $j = 0                lhs=$j  type=NULL     never defined before

All three read `NULL`, so the assignment target's type cannot separate the case that reports from the case
that does not. And the SDK exposes no query that can: `LifecycleContext` carries `phpVersion`, `codebase`,
`types` and `cancellation`, and a search of the whole `Sdk/` tree finds **no public method with `variable` in
its name at all**.

> **Corrected** by *The definedness gap is a wire, not an engine* at the end of this file. The query is
> absent from the SDK, which is what was measured; it is not absent from mago, which is what the next
> sentence went on to say.

So this rule is three capabilities deep — a hook, an iteration over `->init`, and a scope query the SDK does
not expose — and the third is upstream work rather than vocabulary work. Not marked `permanent`: an SDK addition
would move it, and `Refusal::$permanent` means a property of the rule rather than a gap in the host, with
provisional the safe direction.

#### And the needs list said one thing

    REFUSE  OverwriteVariablesWithForLoopInitRule
            no mapping for ->init on a hook-node
            needs: no iteration mapped for ->init, which resolved to a expr

One need, and the `hasVariableType` query is not in it. That is the documented lower bound working as
written — the pass steps over a refusing *statement* and reads on, but a second obstacle inside one
*expression* never appears, and `->init` fails inside an expression. It is worth restating because both this
session and a peer session have been ranking work off these lists: **a needs list is a lower bound, and it is
systematically shortest exactly where the first blocker is expression-level.**

The fix two entries above made the list complete for refusals that *end* the pass. It does nothing for this
shape, and nothing here suggests a cheap way to.

#### What this does to the hook family's cost

The family is 25 first blockers, 17 of them in a package the census does not cover. Of the 8 in census
packages, three name PHPStan virtual nodes with no mago equivalent (`ClassConstantsNode`, `BooleanAndNode`,
`BooleanOrNode`), one names `BinaryOp`, which is several kinds rather than one, and one names `Expr\Cast`,
which the enum has no case for at all — checked. This entry accounts for a sixth. **A hook row was never the
unit of work here**, and the count of rules a hook push would emit is not 25, not 8, and is not known.

#### Verification

The rule's requirement is a read of its source, quoted above rather than summarised. The three probe rows are
one file through the real binary, differing in one thing each. The SDK claim is two greps over the whole
`Sdk/` tree plus the `LifecycleContext` constructor, made after a probe rather than instead of one, because
"the API does not have X" is the claim shape this file has recorded going wrong four times.

### The definedness gap is a wire, not an engine

The entry above measured that no SDK method answers "is this variable defined here", probed that the type
read cannot stand in for it, and concluded the rule needs upstream work. The measurement holds. The word
"exist" in it does not.

A peer session read mago's Rust tree, which this repository does not vendor — the composer package ships the
PHP SDK and downloads a binary, so `crates/` is not here to check. Their finding, **reported as theirs and
not verified by me**:

- `crates/analyzer/src/plugin/context.rs` gives `HookContext::get_variable_type(&self, name: &[u8]) ->
  Option<&Rc<TUnion>>`, reading `block_context.locals`. `Some` is defined-with-a-type, `None` is not defined.
- `crates/analyzer/src/context/block.rs` carries `locals`, `variables_possibly_in_scope` and
  `possibly_assigned_variable_ids` — the definite and possible halves of PHPStan's trinary.
- They flag as unverified how `variables_possibly_in_scope` is populated and whether it matches PHPStan's
  `maybe`. The `locals`-to-`yes()` correspondence is the half the rule needs.

So mago computes local definedness and exposes it to an in-tree Rust hook. What is missing is the wire: no
requirement flag marshals locals over the extension-host protocol, which is exactly why a search of the PHP
SDK found no method with `variable` in its name.

**The measurement was right and the inference from it was not.** An absence at the boundary was written up as
an absence in the engine, and those are different claims with different consequences — one is a feature
request against an analysis engine, the other is "the data is already computed, please surface it". Same
shape as the enum error that came the other way earlier in this exchange: a fact about one layer read as a
fact about the system.

It changes nothing about the family cost. A hook row is still not the unit of work, the three PHPStan virtual
nodes still have no equivalent, `Expr\Cast` still has no case, and the rule still needs three things. What
changes is that the third is a protocol addition rather than an engine one.

#### Verification

The correction is a read of a peer session's report, and it is marked as such throughout because this
repository cannot check it: `vendor/carthage-software/mago` contains `composer/` and no `crates/`, verified.
What *is* checked here stands unchanged — the three probe rows and the two SDK searches are in the entry
above, and neither is contradicted.

### Adopting 1.47.6 was a deletion, and the six moved to their real blocker

The floor is now `^1.47.6` and `KINDS_WITHOUT_OPERAND_TYPES` is gone. What the six rules refuse with changed
rather than disappearing:

    before   a dispatch onto Binary and Assignment, where mago types operand 1 of `Assignment` as the
             value the expression produces rather than as the operand itself
    after    if statement that is not a single-statement guard, but a chain of 1 elseif and an else

That is progress of a specific kind — the refusal now names an obstacle that is present rather than one that
was fixed upstream — and it is not emission. Nothing new emits.

#### The table did not survive being emptied, and PHPStan said so before I did

The first version of this kept the table as `[]` with a docblock arguing it was "the mechanism a future kind
with the same problem needs". PHPStan rejected that in two lines: `Offset string on array{} on left side of
?? does not exist`, then `Unreachable statement - code above always terminates`. With no rows, the loop body
in `refuseAnOperatorDispatch()` cannot run.

So the whole chain went: `KINDS_WITHOUT_OPERAND_TYPES`, `OPERATOR_KINDS`, `refuseAnOperatorDispatch()`,
`operatorKindOf()` and `returnsNothing()`, each used only by the next. Keeping a table for a rule shape nobody
has is speculative generality, and the dead-code rule is what caught the argument I had already written down.

`Translator`'s complexity went **2337 to 2325** — the baseline records the smaller figure, still 14 entries
and 14 errors.

#### What the six need now, stated so it is not re-derived

    if ($node instanceof BinaryOpDiv) { $left = $node->left; $right = $node->right; }
    elseif ($node instanceof AssignOpDiv) { $left = $node->var; $right = $node->expr; }
    else { return []; }

Both arms bind the same two operand positions and everything after is kind-agnostic, so this is a plugin
registering two kinds with one binding, not a redesign. It is a translator change rather than a vocabulary
row. `internal/handoff-multi-kind-hook-is-not-a-redesign.md` covers the method-call version of this shape and
not this one.

#### A near-miss worth recording against myself

The first deletion attempt matched `s.index("    /**\n     * Refuse")`, which found an earlier docblock, and
removed **1283 lines** of `Translator.php`. Caught on the line count, reverted with `git checkout
src/Translator.php`.

That recovery was safe only because `Translator.php` held no uncommitted work of mine — the file had not been
touched this session, only `Vocabulary.php` had. The git-safety guidance in `CLAUDE.md` says exactly this:
`git checkout -- <file>` discards uncommitted work with no confirmation, and the rule is to copy a file aside
before mutating it on purpose. I got the safe case by luck rather than by checking, and the redone deletion
used explicit line numbers with a copy aside first.

#### Verification

PHPStan 0 with the baseline at 14/14. Emit-all across php, analyzer and linter over five packages plus the
fixtures, byte-identical to HEAD apart from the `--out` path — a refusal that changes wording changes no
emitted file, which is the check that the deletion removed only dead code. Drift and fires-gate suites green
at 566, full suite below. `mago --version` reads 1.47.6 and `composer.json` requires `^1.47.6`, so the floor
and the installed binary agree rather than one being assumed from the other.

### The dispatch is not "two targets and one binding", and I said it was twice

The entry above closed by describing what the six arithmetic rules need as "a plugin registering two kinds
and binding the same two operand positions in each arm — a translator change rather than a vocabulary row".
Measured before building it, that is wrong, and the reason is one table.

`Vocabulary::HOOK_KINDS[Expr::class]` is:

    ClassConstantAccess, StaticPropertyAccess, MethodCall, StaticMethodCall, FunctionCall, PropertyAccess

**Six call and access kinds, and neither `Binary` nor `Assignment`.** A rule returning `Expr::class` registers
those six, so the six arithmetic rules would emit a plugin that never fires on `$a / $b` or `$a /= $b` — the
dispatch could translate perfectly and the result would report nothing.

#### Widening the table is not free, which is the part I had not checked

Eight rules in the installed corpus return `Expr::class`: the six, plus `NoInstanceOfStaticReflectionRule`
(refused) and **`NoDynamicNameRule`, which emits and ships**. Adding `Binary` and `Assignment` to the shared
list changes what that plugin registers and therefore its emitted bytes, and makes it fire on two node kinds
whose bodies it was never read against. That is a shipped rule's behaviour, not a table row.

#### And the codebase disagrees with itself about the alternative

The alternative is deriving targets from the rule's own `instanceof` set. Two places take opposite views.

`HOOK_KINDS`'s own docblock rejects it: *"the kinds a node type covers are a fact about the type, and letting
a rule's own `instanceof` decide the registration would make the targets depend on the body rather than on
what PHPStan would have visited."*

`Transpiler::multiKindRefusal()`'s message assumes it: *"A plugin can register several targets, so the shape
is reachable — what it needs is a hook and a field mapping for each kind, and a body that reads the same
child in every branch."* A body that reads the same child in every branch is a statement about the body
deciding whether the registration is sound.

**The stated principle is also not what the table does.** PHPStan's `Rule<Expr>` visits every expression —
that is exactly the "trick to allow multiple node types" `NoDynamicNameRule` documents, and it is how the six
receive `BinaryOp` and `AssignOp` at all. So a six-kind list is already a pragmatic narrowing chosen for the
corpus, not a fact about `Expr`. The docblock describes a principle the table does not follow.

That tension is the decision, and it is a design one rather than a measurement: either `Expr` covers more
kinds for everyone, or registration may depend on a body once the body is checked to read uniformly. Nothing
here settles which, and both have consequences for rules that already ship.

#### The pattern, since this is mine

Five cost estimates in this exchange have failed on contact with the thing estimated — a peer's "table
entries against an enum that already has the cases", their message-capability ranking, my own "a hook row is
the unit of work" correction, and now my "two targets and one binding", twice: once in a commit message and
once in a reply. Each was reasoned from the shape of the code and each was wrong in the same direction,
cheaper than reality.

The one that keeps working is unglamorous: make the smallest real change and read what the tool says. Adding
the `For` row and following one rule to the bottom cost minutes and settled a family. Estimating the
dispatch from its shape cost nothing and was wrong twice.

#### Verification

`HOOK_KINDS[Expr::class]` is read from the table, and the eight `return Expr::class` rules are a grep over
the four installed packages with each outcome taken from the committed census rather than assumed —
`NoDynamicNameRule` is `EMIT` there. No code changed for this entry: it is the measurement that stopped a
change being made.

### Widening `Expr` coverage costs nothing measurable, which halves the design question

The entry above stopped a change and left a design decision open: either `Expr` covers more kinds for
everyone, or registration may depend on a body checked to read uniformly. Half of that was an assertion — I
said widening the shared list "makes `NoDynamicNameRule` fire on two node kinds whose bodies it was never
read against", which is true of the registration and says nothing about what it *reports*.

Measured, by making the change and reading the tool rather than the code. `Binary` and `Assignment` added to
`HOOK_KINDS[Expr::class]`, then reverted:

    emitted diff, NoDynamicNameRule    one line — the `getTargets()` list. Body identical.
    fires gate                         564 / 564
    Laravel Support + Database         agree 7798, 1 / 23   — identical to the baseline
    symplify.noDynamicName             agree 171, 1 / 6     — identical
    per-finding lists                  diff clean

**Zero movement on 337 real files.** The instrument's sensitivity is the same 24-to-23 control recorded
earlier this session, so a single finding would have shown.

So the false-positive cost I implied is not there on this corpus. It is not proof for every corpus, and the
honest scope is: on the one tree available, a wider `Expr` registration changes nothing a rule reports.

#### Reverted anyway, and why that is not a contradiction

Widening alone makes no rule emit — the six still need their dispatch translated. Committing it would change
a shipped plugin's emitted bytes for no functional gain, which is the one thing the emitted-output invariant
exists to stop. The change pays off *with* the dispatch or not at all, so it belongs in that commit rather
than ahead of it.

What the measurement bought is a cheaper decision: the option that looked risky is measurably free on real
code, and the argument for it is now stronger than the docblock against it. `HOOK_KINDS` says the kinds a
node type covers are "a fact about the type" — but PHPStan's `Rule<Expr>` visits every expression, so a
six-kind list is a narrowing chosen for the corpus, and adding two makes the table *more* faithful to what
PHPStan would have visited rather than less.

#### Verification

The emitted diff is one file before and after, the same rule, on the same target. The differential is the
same consumer, paths and sandbox shape as the baseline run it is compared against, and compared on the
per-finding list as well as the total, because equal totals can hide a compensating pair. `src/Vocabulary.php`
was restored from a copy taken before the edit and the tree confirmed clean, so nothing here is left in the
working state.

### spaze's rules are not permanent, and the refusal points at the wrong party

A peer session proposed marking spaze's config-driven rules `permanent`, on the grounds that a rule whose
findings come from consumer configuration cannot be derived from source by any version of this tool, with the
package's own `extension.neon` as evidence. The evidence is real — `disallowedFunctionCalls: []` defaults, a
`parametersSchema`, and factory wiring from `%disallowed*%` — and the conclusion does not follow, because
this tool has `--from-config` precisely for values that live in a consumer rather than a package.

So it was run, against this repository, which registers spaze through three of its own `includes`.

    php bin/phpstan-to-mago --target=php --from-config=.
    emitted: 34, refused: 85

`FunctionCalls` and `MethodCalls` still refuse with *"$disallowedCalls is computed in the constructor and the
package wires no configured values for this rule, so there is nothing to derive from"*. Probed further, at
the discovery layer:

    spaze rules registered by this project        38
    of those, with carried constructor arguments   0
    other rules with carried arguments           106
    other rules without                          353

**So the configuration exists, the rules are discovered, and the values do not arrive.** That is not
permanence. `Refusal::$permanent` means no vocabulary, hook or body change could ever move a rule, and here a
change to how discovery carries values would move it — which is exactly why the docblock makes provisional
the default and warns that a refusal wrongly called permanent stops someone looking. Marking these would have
stopped someone looking at a live gap.

#### And the refusal names the wrong party

*"the package wires no configured values for this rule"* is true of the package and false of the situation
under `--from-config`: this project **does** configure these rules, through spaze's own preset neons. A
reader of that line goes to `extension.neon`, finds empty defaults, and concludes correctly about the package
and wrongly about their own run.

This file's oldest recurring lesson is that a refusal naming the wrong obstacle is how work gets sized
wrongly, and this is an instance inside the instrument rather than in a rule.

**Why the values do not arrive is not established.** The obvious candidate is spaze's `factory:` wiring
against the ordinary `class:` form the 106 use, and that is a hypothesis, not a finding: it was not traced
through the discovery path and is recorded here as the next thing to check rather than the answer.

#### What this settles, and what it does not

Settles: the long-pending `--from-config` run has happened, against a project that registers 38 rules the
package-only path cannot configure. The four unwired-configuration rules are no longer waiting on a run;
they are waiting on this gap.

Does not settle: whether any spaze rule would emit once values arrive. Two of the three that carry the
package's real usage refuse on the configured value, and the third, `StaticCalls`, refuses on
`$this->disallowedMethodRuleErrors->get()` — a collaborator call, which is the family measured earlier as
changing the count by zero. So the gap being closed is necessary and may well not be sufficient, and no rule
count should be attached to it.

#### Verification

The `--from-config` run is against this repository with its committed `phpstan.neon.dist`, so the three spaze
includes are the project's own rather than added for the probe. The 38/0/106/353 figures are a direct call to
`RegisteredRules::argumentsFor()` over every discovered rule, counted rather than sampled. The `factory:`
hypothesis is marked as one because it was not traced.

### The carryable filter drops value objects, and the obvious fix does not terminate

The entry above left "why the configured values do not arrive" unknown, with `factory:` versus `class:`
wiring as the candidate. **That hypothesis is wrong**, and the mechanism is one closure.

`resources/registered-rules.php:114` reflects over the *built instance* — `$property->getValue($rule)` on an
object the container has already constructed — so how a service was wired cannot matter, which is also why
106 rules carry values through the same path. What drops them is `$carryable` a few lines above: scalars and
null pass, arrays pass only if every item passes, everything else fails. And spaze holds its configuration as
arrays of value objects:

    FunctionCalls        private array $disallowedCalls      list<DisallowedCall>
    IfControlStructure   private array $disallowedKeywords   list<DisallowedKeyword>

Each item is neither scalar nor array, so the property is dropped whole. That is the clean 0 of 38: every
spaze rule holds its config this way.

#### The fix's guard is load-bearing, measured by leaving it out

A peer session proposed descending into an object only where every one of its own properties is carryable
under the same rule, **with a depth bound**. I implemented the first half and not the bound, on the reasoning
that "every property carryable" is itself a sufficient guard.

It is not. `$carryable` recurses while *checking*, so a cycle is traversed before any verdict is reached, and
nothing in the all-properties rule stops it. The run went from roughly two minutes to **fourteen and counting
before it was killed** — no output, no error, just a graph the checker was still walking.

So the caution was not decoration. A PHPStan rule instance reaches a container, a `Scope`, a
`ReflectionProvider`, and the object graph behind those is effectively unbounded. Any version of this fix
needs a depth bound and cycle detection, and neither is optional.

`resources/registered-rules.php` was restored from a copy taken before the edit; nothing here is committed
beyond this entry.

#### And a second refusal that may name the wrong party

> **Retracted** by *The second refusal is correct, and I checked the wrong half* below. The wiring exists in
> a neon the package does not auto-include, so no default reaches a generated plugin and the refusal is
> right.

Symplify's `ForbiddenFuncCallRule` refuses with *"`$forbiddenFunctions` is a constructor parameter the
package's neon does not wire"*. The package's `config/configurable-rules.neon` wires it:

    class: Symplify\PHPStanRules\Rules\ForbiddenFuncCallRule
    arguments:
        forbiddenFunctions: ['d', 'dd', 'dump', 'var_dump']

included through `config/symplify-rules.neon`. The property is `private array $forbiddenFunctions`, a list of
strings — carryable under the *current* filter, with no descent needed.

**Marked likely, not established.** That refusal is raised on the package-source path, and which neons that
path reads has not been traced here; it may legitimately read a different file. The check that settles it is
one `argumentsFor()` call, which timed out only because it boots PHPStan and was competing with the
experiment above. If it holds, it is a second refusal naming the wrong party, in a different rule and for a
different reason from spaze's.

#### Verification

The property types are read from `ReflectionClass` against the installed package, and `DisallowedCall`'s
constructor is read the same way. The non-termination is a wall-clock observation against the same command's
own two-minute baseline from the entry above, on the same project, differing only in that closure. The
`factory:` retraction is a read of `resources/registered-rules.php`, which reflects instances and never
parses config.

### The second refusal is correct, and I checked the wrong half

The entry above called Symplify's `ForbiddenFuncCallRule` refusal *likely* wrong, because
`config/configurable-rules.neon` wires `forbiddenFunctions` with four strings. Checked properly, the refusal
is right and the check I ran was the wrong one.

`PackageConfiguration` reads the neons named by the package's `composer.json` `extra.phpstan.includes`, then
follows their own `includes:` chains. Symplify names four:

    config/services/services.neon   config/ctor-rules.neon
    config/mock-rules.neon          config/phpstan-extensions.neon

**None of them reaches `configurable-rules.neon`.** That file is included by `config/symplify-rules.neon`,
which is not in the auto-included set — a consumer opts into it. So the package ships wiring for this rule and
does not apply it by default, and there is no value a generated plugin could carry without a consumer saying
so. The refusal describes that correctly.

What I did was find the wiring, stop, and conclude the refusal was wrong — without asking whether the file
holding it is one the tool reads. That is the same shape as reading a kind out of an enum and calling the work
a table row: a fact established at one layer, carried to a conclusion about another.

The `--from-config` probe could not have caught it either, and its output says why in one line:
`ForbiddenFuncCallRule is NOT registered by this project`. This repository does not include
`symplify-rules.neon`, so the rule is not in its container at all. An instrument that returns nothing because
the subject is absent looks exactly like one that returns nothing because there is nothing to find.

#### One wording nit, left alone

The text reads *"the package's neon does not wire"*, and a package neon does wire it — just not an
auto-included one. The transpiler already distinguishes those elsewhere: `ForbiddenNewArgumentRule` refuses
with *"no neon the package ships names this rule at all"*, which is the stronger statement. So the wording
could be sharper here, and the substance is right, and changing refusal text moves census lines — not worth
it on its own.

#### Verification

The four auto-included neons are read from `symplify/phpstan-rules/composer.json`, and each was opened to
confirm none includes the chain that wires the rule. `configurable-rules.neon`'s wiring and
`symplify-rules.neon`'s include list are both quoted from the installed package rather than remembered.

### The carryable fix has no payoff for the rules that motivated it

The defect is real — configuration exists on the instance and `$carryable` drops it — and the case for fixing
it rested on spaze's two most-used rules, `FunctionCalls` and `MethodCalls`, which 18 of 21 projects in a
peer's sample configure. Read rather than assumed, both bodies are:

    // FunctionCalls
    $errors = $this->disallowedFunctionRuleErrors->get($node, $scope, $this->disallowedCalls);
    $paramErrors = $this->disallowedCallableParameterRuleErrors->getForFunction($node, $scope);
    return $errors || $paramErrors ? array_merge($errors, $paramErrors) : [];

    // MethodCalls — the same shape, one more argument
    $errors = $this->disallowedMethodRuleErrors->get($node->var, $node, $scope, $this->disallowedCalls);

**Neither body makes a decision.** Each calls two collaborator services and merges the results; every guard,
every message and every comparison lives in `DisallowedFunctionRuleErrors` and its siblings. So the value the
fix would carry is passed straight into a collaborator, and this is the family measured earlier at line 4372
as changing the count by **zero** when cross-class resolution was implemented for it.

Carrying `$disallowedCalls` therefore unblocks nothing here. The fix is necessary for those rules and nowhere
near sufficient, and its justification cannot be spaze coverage.

#### What that closes

The configuration lane is one defect, not a coverage opportunity:

- **spaze** — a real discovery defect, fix shape known (descend, depth bound, cycle detection), payoff for the
  motivating rules now measured at zero.
- **Symplify's `ForbiddenFuncCallRule`** — not a defect at all; the wiring sits in a neon the package does not
  auto-include, and the refusal says so correctly.

A peer session reached the delegation finding independently for spaze's seven control-structure rules, from
the message-refusal side. It is the same mechanism, and it holds for the two call rules as well — which means
the family covers both of spaze's refusal clusters, the 11 message refusals and the configured-value ones.

#### Verification

Both bodies are quoted from the installed package in full; neither is longer than the three lines shown. The
zero-movement claim for the collaborator family is the run recorded at line 4372 of this file, not a new
measurement — what is new is that these two rules belong to it, which is a read of their source.

### The silent gap is at the bundle level, and nothing on disk records it

A peer session asked whether the argument used to refuse a partial *rule* applies to a partial rule *set*.
Checked rather than reasoned about, on `phpstan-strict-rules` — 22 emit, 23 refuse:

    generated/manifest.json      22 keys, one per emitted rule
    entry shape                  identifier, identifiers, messages, parameters
    anything naming a refusal    nothing, anywhere in the output tree

**A consumer receives 22 rules and no record that 23 exist.** The refusals are printed to the terminal when
the command runs, and that output does not travel with the artefact — the person who installs a bundle need
not be the person who generated it, and nobody reads a plugin directory to count what is absent.

That is the same shape as the partial-rule argument, one level up. The reason a rule covering three of four
operand reads was refused is that a plugin which under-reports is a plugin you would trust; a bundle covering
22 of 45 rules under-reports in exactly that way, and the manifest that exists to describe it is silent.
`README.md` says it — *"a Mago-clean commit can still fail the deferred run, because a refused rule reports
nothing"* — so the consequence is documented in prose the consumer may never read, and absent from the file
the tooling writes.

It is arguably worse at this level for two reasons. A rule's under-reporting is bounded by one rule's subject;
a bundle's is bounded by nothing. And the per-rule case has a gate — `EmittedRuleFiresTest` proves each
emitted rule fires — while no check anywhere asserts that the *set* is complete or says what it omits.

#### What this does not settle

Whether to fix it, and how. The manifest already carries per-rule data a worker reads, so a refused list is
not obviously the right shape — a count, a names list, and a reasons list are three different artefacts with
three different maintenance costs, and one of them duplicates the census. Recorded as a hole rather than a
plan.

Nothing here changes what is emitted. It is a gap in what is *reported* about what is emitted.

#### Verification

One emit run of `phpstan-strict-rules` on the `php` target, its `manifest.json` decoded and keyed, and a
case-insensitive search of the whole output tree for any word naming a refusal. The 22-of-45 figure is that
run's own count, and matches the census.

### An upper bound on what a triage pass would drop, from data already collected

A peer session proposed inverting the architecture: run the cheap engine to pick candidate files, then run
PHPStan only on those. Rule-level fidelity stops mattering — a false positive costs one wasted PHPStan file,
and only a **false negative** is unsound, because PHPStan never sees the file and the finding is not late, it
is gone.

For the variant where the transpiled rules do the picking, that is already measured. A false negative is a
file where PHPStan reports and the port reports nothing — which is what `only-original` counts:

    Laravel Support + Database   337 files    1 only-original   QueueFake.php:167          NoDynamicNameRule
    nesbot/carbon/src            914 files    1 only-original   MessageFormatterMapper:42  NoProtectedClassStmtRule

Neither file carries an `only-port` finding either. So **at most 2 of 1251 files would be wrongly skipped**,
about 0.16%.

**Upper bound, not a rate, and the instrument is why.** The differential prints divergences and only *counts*
agreements, so a file with an agreement elsewhere in it would still be selected by the triage pass and is
invisible in this output. If either of those two files agrees somewhere, the true figure is lower — possibly
zero. Getting the exact number means printing agreement locations, which this instrument does not do.

That limitation is the same one that made "never appears as only-port" unable to separate *both report* from
*neither reports* a few entries above. Same instrument, same silence, and worth naming twice because it has
now bitten two different questions.

#### What it does not cover

Only the transpiled-rules variant. The peer's actual proposal was a *type-based* prefilter using mago's own
analysis, which is a different selector with a different false-negative set, and nothing here measures it.
Their prior work says a kind-based prefilter fails because a few rules reach every file; whether types change
that is open and untested.

#### Verification

Both figures are read from the 1.47.6 differential runs recorded earlier in this file, not re-run — the same
output whose totals and per-finding lists are quoted above. The "neither file carries an only-port finding"
claim is a grep for each basename across both runs' finding lists.

### Two triage predicates, two very different soundness figures

The entry above bounded a triage pass at **0.16% of files** wrongly skipped. A peer session then measured
**11.4%** and killed the idea's cheap form. Both are right, and they are not the same experiment — a reader
who quotes one for the other gets the architecture question backwards.

    predicate                                     selector                    false-negative files
    "a transpiled rule found something here"      PHPStan's own rules, ported  <= 2 of 1251   0.16%
    "Mago's native analysis found something here" Mago's own diagnostics        4 of 35      11.4%

**The selector is the whole difference.** The transpiled rules *are* PHPStan rules, so their finding set
tracks PHPStan's by construction — that is what the corpus differential exists to measure, and 12305
agreements is what tracking looks like. Mago's native diagnostics check different things, so there is no
reason for their finding set to cover PHPStan's, and it does not.

The peer's figures, reported as theirs and not reproduced here: `nesbot/carbon/src`, 914 files, PHPStan level
9 with carbon's own autoload against Mago 1.47.5 defaults — 35 flagged files against Mago's 475, 439 skipped
(48% of the corpus), 4 of the 35 never analysed, 6 of 1118 errors lost.

**The file figure is the soundness one**, on the argument I gave them: a skipped file's finding is gone rather
than late, so 48% saving for 11.4% blindness is not a trade a correctness tool takes.

What it kills is the cheap instantiation, not the idea. A sound version needs a predicate provably
conservative over PHPStan's rule set; Mago's diagnostics are not one, and the 0.16% row is not a candidate
either — it is an *upper bound from an instrument that cannot see agreements*, on a selector that presupposes
the rules are already ported, which is the thing the architecture was meant to avoid needing.

#### The instrument note, because it is the fourth of these

Their first run of the same experiment reported **0.0%** false negatives. It was computed against a PHPStan
set of one file, because large PHPStan stdout is wrapped and truncated in this environment, and the truncated
form looks like complete output. The fix was `--generate-baseline`, which writes to disk and bypasses stdout:
931 entries, 1118 errors, 35 files.

A 0.0% and an 11.4% out of one experiment an hour apart, separated only by noticing that 35 files cannot be
1 file. Same shape as `(string) $type` rendering a `callable-string` as `string`: the instrument was lossy
and the loss looked like a result.

A second artefact they hit is worth carrying too: narrowing the corpus to one directory to shrink the output
made every finding a `trait.unused`, because the using classes were no longer analysed. **Changing the corpus
changed what PHPStan reports about it** — a shape this file has recorded from the other direction, where
traits with no user in scope are 19 of 28 divergences.

#### Verification

The 0.16% row is this repository's own 1.47.6 runs, already recorded above. The 11.4% row is a peer's
measurement on a corpus this repository also uses, reported here and **not reproduced** — no run behind it on
this side. The distinction between the two predicates is a statement about what each selector is, checkable
against the differential's own design rather than against either number.

### Every corpus-resolution divergence resisted minimisation, and each for a different reason

The divergence harness shipped with three cases. Two of the three were written to reproduce a recorded
divergence and **record agreement instead**, and a fourth was abandoned before it was written. That is not
three unlucky attempts — the failures are the same class of divergence failing four different ways.

    QueueFake.php                mechanism absent      the construct agrees once reduced
    MessageFormatterMapper.php   mechanism agrees      both engines report both guarded branches
    SimpleStaticType.php:13      direction inverted    an unresolvable parent silences PHPStan too
    TranslatorImmutable.php      cause never traced    "the same resolution chain" is not a mechanism

**The inverted one is the sharpest.** A peer session measured, from `phpstan-src`, that a rule reading an
unresolvable parent gets a valid `ClassReflection` whose `getParentClass()` is null, so
`fast_has_parent_constructor()` is false and the rule returns `[]` — PHPStan goes *silent*. But
`SimpleStaticType.php:13` is only-**original**: PHPStan reports and the port does not, because
`PHPStan\Type\StaticType` lives inside `phpstan.phar`, which PHPStan reads and mago does not open. So the
mechanism is not "a parent nothing resolves" but "a parent one engine resolves and the other cannot", and a
synthesis making it unresolvable to both reproduces agreement. Both of us had specified the wrong subject,
and the measurement caught it before either was written.

#### The one that could have been written, and why it was not

The visibility mechanism *is* reproducible without a phar: PHPStan's `scanDirectories` and mago's
`[source] excludes` both exist, so a parent can be visible to one engine and not the other by configuration.

It was not written. The observable behaviour would match and the cause would not — the original's invisibility
is a phar mago does not open, the synthesis's is a directory the case tells mago to skip. Two consequences,
and the second is worse: if mago ever reads phars the original divergence disappears while the case keeps
passing, and a case that pins *its own configuration choice* produces a green meaning only that the exclusion
still works. That is not a fact about either engine.

#### What the harness is for, stated because three cases in it record agreement

A case that stands for the wrong thing is worse than a missing case, because it passes. So a failed
minimisation is kept, renamed to what it actually pins, and its README says plainly which original it fails to
reproduce and what the reduction drops. The next person to reduce that finding starts after the shape rather
than at it, and the original stays in this file as unreproduced rather than being quietly marked covered.

Two harness defects were caught the same way and are worth naming, because both would have produced confident
wrong records. Attribution required a leading `/cases/`, which matched PHPStan's absolute paths and not mago's
relative ones, so every case read as a false `DIVERGE` — caught by a control row both engines must report. And
a control written as a `callable` parameter is exempt from the rule under test, so the case recorded silence
on both sides and read as agreement — caught by the guard that refuses a case recording nothing.

#### Verification

Three cases in `tests/Fixtures/expected/divergences.md`, each with a control, regenerated and diffed by
`RecordsDivergencesTest`. The regression guard is mutation-checked: reverting `f62f331` flips it to `DIVERGE`.
The PHPStan-side behaviour of the unresolvable parent and of the guarded double declaration are a peer
session's measurements from `phpstan-src`, reported as theirs; the port-side rows and every recorded row are
this repository's own runs.

### The sweep's first run found a third `is_callable()` narrowing gap, and this one is mago's

`symfony/console` had never been run as a corpus. Its first sweep reports **six only-port findings, all
`symplify.noDynamicName`**, four of them in `Helper/QuestionHelper.php` and one in `Helper/TreeNode.php:76`.

Traced rather than attributed. `TreeNode` declares:

    /** @var array<TreeNode|callable(): \Generator> */
    private array $children = [];

and iterates `if (\is_callable($child)) { yield from $child(); }`. Both engines were measured on that exact
shape:

    PHPStan   after is_callable()   callable(): Generator        the object arm is gone
    mago      after is_callable()   callable|Node                the object arm is retained
                                    CallableType | NamedObjectType
                                    Types::typeIsCallable -> false

**`Node` is `final`, declares no `__invoke`, and is in the analysed file**, so it cannot be callable and mago
can see that. PHPStan eliminates it from the union; mago does not, and the port then reports because
`Types::typeIsCallable()` requires every atomic to be callable.

#### The predicate is right, which is the part worth stating

Relaxing "every atomic must be callable" to "any atomic" would exempt a genuinely non-callable union — an
unnarrowed `string|Closure` is precisely the case the rule exists to report. So the port is faithfully
reporting what mago tells it, and the imprecision is upstream.

That conclusion was reached the second way round on purpose. This morning the same shape was written up as a
mago narrowing bug and turned out to be this repository's own predicate reading `literalValue` instead of
`callable`. So PHPStan's side was measured before anything was claimed, rather than inferred from the port's
answer.

#### Third instance of one family

`#2311` (compound-assignment operands), the `callable-string` refinement, and now a union that keeps a
non-invokable object through `is_callable()`. All three are the same shape: a narrowing that does not fully
eliminate, visible only through the external index.

**The first one an instrument found rather than a person.** The Laravel and carbon differentials showed no
movement when the callable-string fix landed, and both were re-run against 1.47.6 with identical results —
neither tree contains this shape. "No regression on what was looked at" and "fixed" are different claims, and
only a corpus nobody had run separated them.

#### Verification

Two probes on one minimal subject reproducing `TreeNode`'s declaration: the port's atomics read through
`Type::$atomicTypes` from inside a plugin, and PHPStan's through its own `dumpType()` at the identical
position. The six findings are `run-corpus-sweep.php`'s recorded output, attributed to the rule by a second
differential run. No cause is claimed for the four `QuestionHelper` sites beyond their sharing the rule —
they were not traced.

### All six symfony-console findings traced, and the #2310 workaround does not work

> **Retracted in part** by *The #2310 workaround does work, and I misread which parentheses* at the end of
> this file. The four `QuestionHelper` findings, the native-hint severity and the `TreeNode` gap all stand.
> The claim that the workaround fails does not: symfony never applied it.

The five remaining findings from the sweep's first run resolve into one more mago narrowing gap and **four
instances of a filed issue, plus a fifth showing its published workaround does not hold.**

#### Four are `#2310` in the wild

`QuestionHelper` declares `@param callable(string):string[] $autocomplete` on a parameter whose **native type
hint is `callable`**. Measured on that shape against the unambiguous spelling:

    callable(string):string[]        mago reads array      KeyedArrayType     typeIsCallable false
    callable(string):array<string>   mago reads callable   CallableType       typeIsCallable TRUE

The trailing `[]` binds to the whole callable rather than to its return type, which is `#2310`. **The
docblock overrides a correct native `callable` hint**, which the issue as filed does not say — it is not
imprecision on an unannotated value, it is a malformed docblock defeating a declaration mago already had.

#### The fifth shows the workaround failing

`Question.php:169` reads a value from `getAutocompleterCallback()`, declared:

    /** @return (callable(string):string[])|null */
    public function getAutocompleterCallback(): ?callable

**Parenthesised — which is the maintainer's stated workaround for `#2310`** — and with a native `?callable`
return type. Measured:

    the returned value              array|null   KeyedArrayType | SimpleAtomicType   typeIsCallable false
    after `if ($callback)`          array        KeyedArrayType                      typeIsCallable false

So the parentheses do not rescue it. symfony/console has already applied the remedy the issue thread
recommends, and mago still reads `array`. That is checkable by anyone and belongs on the issue, because a
workaround that does not work is worse than none — a project applying it believes it is fixed.

Standing: the maintainer's reply is reported by a peer session and not read here; what is measured is that the
parenthesised spelling still reads as `array`.

#### What the corpus was worth

Six findings, one previously unknown mago gap, four live instances of a filed issue, and one refutation of its
workaround — from a corpus that had never been run, on the instrument's first execution. The other three
corpora reproduced their recorded figures exactly and found nothing new, which is the control: the sweep is
not simply reporting noise on every tree.

#### Verification

Four probes, each a minimal subject with the two spellings side by side, read through `Type::$atomicTypes`
from inside a plugin. The `TreeNode` case additionally has PHPStan's own `dumpType()` at the same position;
the `#2310` cases do not, because the port-side reading alone establishes what the docblock does to mago and
PHPStan's behaviour on that spelling is what `#2310` already records.

### The #2310 workaround does work, and I misread which parentheses

The entry above claims symfony/console applies `#2310`'s published workaround and that it fails. **The second
half is wrong**, and a peer session caught it by checking the maintainer's wording rather than the punctuation.

The workaround parenthesises the **return type**. Symfony parenthesises the **whole callable**, which the
`|null` union requires. Same punctuation, different edit, and the inner `string[]` is untouched either way.
Three spellings on one subject, measured here after the peer measured them independently:

    (callable(string):string[])|null      array|null      typeIsCallable false   symfony's spelling
    (callable(string):(string[]))|null    callable|null   typeIsCallable TRUE    the workaround
    callable(string):(string[])           callable        typeIsCallable TRUE    the workaround, unwrapped

So the remedy holds and symfony has simply never applied it. The draft comment has been deleted rather than
edited: its whole argument was that a widely-installed package had applied the fix and been let down, and
nothing of that survives.

#### The error, which is the second of this exact shape today

I saw parentheses in symfony's docblock, matched them against "the maintainer said parenthesise", and
concluded the workaround was applied — without checking *where* the parentheses went.

That is the same mistake as the `ForbiddenFuncCallRule` retraction earlier: finding the wiring in
`configurable-rules.neon`, concluding the refusal was wrong, and never asking whether that neon is one the
tool reads. Both times a fact was established at one level and carried to a conclusion about another, and both
times the marker `likely` rather than `established` is the only reason it cost a retraction rather than a
wrong fix.

The rule that would have caught both: when a claim rests on matching something you found against something
someone said, check that the two are the same thing before they are the same sentence.

#### What survives, which is most of it

- Four `QuestionHelper` sites are `#2310` in the wild, on `symfony/console` — a second real-world instance
  independent of Laravel.
- **The native-hint severity stands and is the strongest part.** Those parameters are declared `callable` in
  PHP and the docblock overrides that to `array`. There is no reading of that source where the author meant
  an array of callables while the signature says `callable`, which makes it unambiguously wrong in a way that
  loose parsing of an unannotated value is not.
- The `TreeNode` narrowing gap is untouched by any of this — both engines were measured at that shape before
  anything was concluded.

#### Verification

The three rows above are this repository's own run, reading `Type::$atomicTypes` from inside a plugin, made
after a peer session reported the same three from a different instrument. The maintainer's wording remains
their report and is still not read here — but the retraction does not depend on it, because the measured
difference between the two parenthesisations is enough on its own.

### The two callable findings are one upstream behaviour, and there is a third case

`TreeNode` and the monolog fix presented identically — `NamedObjectType | CallableType`, `typeIsCallable`
false — and were written up as opposite defects: mago retaining a non-callable arm, and this repository's
predicate failing to recognise a callable one. A peer session proposed they are one thing seen from two sides,
and predicted a third case. Tested, three arms on one subject under `is_callable()`:

    the object arm              PHPStan                              mago        port (after 1855257)
    final, no __invoke          removed, provably not callable       untouched   reports
    non-final, no __invoke      refined to callable&Class            untouched   reports
    final, with __invoke        retained as the class, it is callable untouched   exempt

**PHPStan does three different things to the object atomic; mago does nothing in any of them.** That is one
behaviour with two symptoms, which is why they read as unrelated: the same untouched atom makes the predicate
answer false whether the class is callable or not.

#### The predicted third case exists, and runs the other way

The peer predicted a non-final `__invoke`-less class would diverge as *only-original* — port silent, PHPStan
reporting. Measured, it is **only-port**: PHPStan exempts all three arms and the port reports the first two.
Same direction as `TreeNode`, not the opposite.

PHPStan's silence was checked with a control before being read as agreement — an unnarrowed dynamic call in
the same file, which it does report. Without that, "PHPStan reports nothing on all three" is equally
consistent with the rule never having run.

#### What it changes

The issue's claim moves from "mago retains a provably non-callable arm" to "mago's `is_callable()` narrowing
does not act on object atomics", which is harder to answer with a preference about narrowing aggressiveness —
the non-final row shows the assertion being dropped rather than applied conservatively. The **final,
no-`__invoke`** row stays the spine, because it is the only one where the retained arm is provably impossible;
whether to refine an unprovable atom is a defensible choice and the draft does not ask for it.

It also means `1855257` is compensating on this side for an upstream gap. That is worth knowing before
someone reads `isCallableAtomic()` later and wonders why it performs a codebase lookup mago could have done —
the answer is that mago does not, and until it does, the lookup is what makes an invokable object callable
here.

#### Verification

The three port rows are this repository's run reading `Type::$atomicTypes` from a plugin; the three PHPStan
rows are a peer session's measurement, reproduced here to the extent of which arms PHPStan's rule exempts,
with a control proving the rule runs. The unification was their inference from both sets and is now measured
rather than inferred.

### The last untraced finding is a third mechanism: `!instanceof` against an unresolvable class

`MandrillHandler.php:41` was the one sweep finding with no written cause. It is neither of the other two.

    protected function __construct(string $apiKey, callable|Swift_Message $message, ...)
    if (!$message instanceof Swift_Message) { $message = $message(); }

**`Swift_Message` is not installed** — swiftmailer is absent from the tree, so the class cannot be resolved.
Probed with a resolvable control in the same file:

    !instanceof Known                 callable                        typeIsCallable TRUE   arm eliminated
    !instanceof Totally\Missing\Thing callable|Thing (ReferenceType)  typeIsCallable false  arm survives

PHPStan exempts both, with a control proving its rule ran — an unnarrowed dynamic call it does report.

**`!$x instanceof T` removes `T` by pure logic.** It requires nothing about `T`: if the value is not an
instance of `T`, the `T` arm is gone whether or not the class can be resolved. Mago eliminates the arm when
the class resolves and keeps it when it does not, so the assertion is being conditioned on knowledge it does
not need.

#### Three mechanisms, and whether they are one

    is_callable() vs an object atomic      untouched, whatever the class          TreeNode, and two more arms
    the port's own predicate               did not ask about __invoke             fixed in 1855257
    !instanceof vs an unresolvable class   arm survives; resolvable control works MandrillHandler

A tempting unification is "mago's assertions do not act on atomics it cannot reason about". It fits, and it is
**not claimed here**: the `is_callable()` case leaves object atomics untouched even for classes it resolves
perfectly well, which the resolvable `!instanceof` control shows is not a general inability. Two mechanisms
that share a symptom have already been written up here as one thing and as two, in both directions, and the
honest position is that these are two measured behaviours whose relationship is unmeasured.

#### Verification

Both engines on one subject carrying a resolvable and an unresolvable arm, so the difference is the resolution
and not the shape. PHPStan's silence carries a positive control in the same file. The absence of
`Swift_Message` is `ls vendor/swiftmailer` plus a grep for its declaration across the whole tree.

### The narrowing gaps are two mechanisms, and the matrix settles it

Three findings shared a symptom — an atomic surviving an assertion that should have removed or refined it —
and the entry above declined to unify them, saying the relationship was unmeasured. It is measurable. Five
rows, one subject, each an assertion over a union carrying one interesting atomic:

    is_callable   string atomic              string|callable   ScalarType | CallableType     ACTS, refines
    is_callable   resolvable object atomic   callable|Plain    CallableType | NamedObject     does not act
    !instanceof   resolvable object atomic   callable          CallableType                  ACTS, eliminates
    !instanceof   unresolvable atomic        callable|Gone     CallableType | ReferenceType   does not act
    instanceof    resolvable object atomic   Plain             NamedObjectType               ACTS, eliminates

**Two blind spots, and they do not coincide:**

- **`is_callable` does not act on object atomics**, resolvable or not. It refines a string atomic in the same
  breath, so this is not an inability to touch the union.
  **Superseded — see "The fourth row" below.** The five rows here never put `is_callable` against an
  unresolvable atomic; "resolvable or not" generalised from four measured cells to a fifth that was not. It
  was later measured and is the opposite: `is_callable` *eliminates* an unresolvable object arm. The half of
  this bullet that survives is the resolvable one.
- **`instanceof` does not act on unresolvable atomics**, in either polarity. It eliminates a resolvable object
  arm correctly, so this is not an inability to touch objects.

**The tempting unification is refuted rather than declined.** "Mago's assertions do not act on atomics it
cannot reason about" fails on row two: `Plain` is `final`, declared in the analysed file, and perfectly
reasonable about — and `is_callable` still leaves it. "Mago's assertions do not act on object atomics" fails on
rows three and five, where `instanceof` eliminates exactly such an atomic.

#### What that does to the report

It strengthens it, in the way that matters most for a maintainer reading it. The `is_callable` claim is no
longer "your narrowing is not aggressive enough about objects" — `instanceof` shows the machinery already
removes object arms from unions when an assertion warrants it. So the question becomes why one assertion
participates and another does not, which is a question about a gap rather than about a preference.

The unresolvable-`instanceof` finding stays a separate report. Its mechanism is different, and joining them
would ask a maintainer to accept two unrelated things at once.

#### Why this was worth measuring rather than judging

Two findings in this file have already been written up as one thing and as two, in both directions, on the
strength of a shared symptom. A symptom shared by two mechanisms and a symptom caused by one look identical
from the outside, and the only thing that separates them is a row where the two predict different answers.
Rows three and five are those rows.

#### Verification

One subject, five methods differing in exactly the assertion and the atomic under it, read through
`Type::$atomicTypes` from inside a plugin on mago 1.47.6. The `is_callable`-over-a-string row is included
deliberately as the positive control for that assertion: without it, "`is_callable` does not act" would be
indistinguishable from "`is_callable` was not reached".

### The two engines agree on `instanceof` and diverge only on `is_callable`

The matrix above measured mago alone. A peer session measured PHPStan on the same union and the pairing is
sharper than either half. Reproduced here, `Plain|(callable(): \Generator)` with `Plain` final and no
`__invoke`, PHPStan level 9 via `dumpType`, mago via `Type::$atomicTypes`:

    assertion                  PHPStan                  mago              
    $i instanceof Plain        Plain                    Plain             agree
    ! ($i instanceof Plain)    callable(): Generator    callable          agree
    is_callable($i)            callable(): Generator    callable|Plain    DIVERGE

**Mago's `instanceof` reaches the reference implementation's answer on this union, in both polarities.** Its
`is_callable` does not. One engine, one union, two assertions, one of them agreeing and the other not.

That is the hardest form of the claim to answer with a design preference, because the preference would have to
explain why it applies to one assertion and not the other on identical input. Every weaker framing this report
passed through — "narrows objects conservatively", "assertions skip object atomics" — is refuted by the two
rows where mago and PHPStan agree.

#### Two findings, labelled

The unresolvable-`instanceof` gap is **not** part of this. A maintainer could fix `is_callable` and leave it
standing, so the draft says so explicitly rather than letting a reader who trips over it read it as one claim.

#### Verification

PHPStan's three rows are this repository's own run, made after a peer session reported the same three from
their own subject; the mago rows are the matrix recorded above. The two sets are on the same union shape, which
is what makes them comparable — a different union on either side would compare two engines on two questions.

### A count I published without counting

The README carried "the nine defects they found", then "the eleven". The second number was written while
editing the sentence around it and **never counted** — I incremented a figure rather than re-deriving it.

It is also not mechanically derivable. "Defect" in that sentence has spanned defects in this port that a
differential caught, gaps in mago that a corpus exposed, and at least once a divergence later shown to be
neither. Three populations, one number, and no definition committed anywhere that a reader could apply.

So the number is gone rather than corrected. The sentence now points at the file and says what is in it,
which is checkable by opening it. This file's own rule — *a count belongs to its configuration; print the
configuration next to the number, or a reader will conclude the tool is inconsistent* — applies to prose
counts as much as to tool output, and a curated tally with no stated criterion has no configuration to print.

Worth naming the mechanism, because it is quieter than the wrong-cause failures recorded above: a figure in a
sentence being edited for another reason gets carried along, and carrying it feels like preserving it rather
than asserting it. Nothing in the edit looked like a claim, which is why nothing triggered a check.

#### Verification

`git log -S'nine defects'` dates the original figure to `f62f331` and the increment to `83e3aab`, whose diff
shows the sentence rewritten for the corpus-sweep figure with the count changed in passing. No commit between
them adds a tally either could be read off.

### Auditing the README's other figures, and finding a caveat I trimmed away

Having removed one uncounted figure, the rest were checked against their sources rather than assumed to be
the only bad one. Recomputed mechanically:

    sweep totals      11327 agreeing, 31 divergences   recomputed from corpus-sweep.md, exact
    phpunit 1003 files, 0 divergences                  same file, exact
    coverage table    7 rows                           every row matched against census.md, 0 mismatches
    --status 99 of 209                                 re-run today
    versions          floor ^1.47.6, README 1.47.6+, installed 1.47.6   consistent

**One real gap, and I put it there.** The performance table states it was measured on mago 1.47.5, and this
package now requires `^1.47.6` — so a reader on the supported version could not reproduce it. A clause saying
the mago rows hold within 2% on 1.47.6 was added when the floor was raised in `2f40774` and **cut in
`83e3aab`**, the commit where the README was trimmed back under its word budget.

That is precisely the failure the README guidance names: *never cut a caveat to hit a number*. The cut did not
feel like removing a caveat — it felt like removing a version clause from a sentence that already named a
version. Restored, with the 1.47.6 figures behind it (`3.97s/3.79s` against the published `4.00s/3.87s`).

#### The pattern across both audit findings

Both defects entered while editing prose for a different purpose — a count carried along during a rewrite, a
caveat dropped during a trim. Neither edit was about the claim it damaged, which is why neither triggered a
check. The wrong-cause failures recorded elsewhere in this file all came from claims made deliberately; these
came from claims *touched* incidentally, and they are harder to catch because nothing about the edit looks
like an assertion.

The cheap countermeasure is the one used here: after editing a document for any reason, re-derive its figures
from their sources. It took one command per figure and found two defects in a file that had been reviewed
repeatedly.

#### Verification

The sweep and coverage figures are recomputed by parsing the committed records, not by reading them.
`git log -S'reproduce within'` dates the clause's addition and removal to the two commits named.

## The hooks spaze needs were never the blocker

`spaze/phpstan-disallowed-calls` is installed here but sits outside the census's seven packages, and the
census header explained its `0 of 38` emit run by naming a missing hook family: its rules register
`Stmt\Echo_`, `Stmt\Break_`, `Stmt\Goto_` and the like, and none of those kinds was mapped in
`Vocabulary::HOOK_KINDS`. Sixteen of the 38 refuse on exactly that, counted from an emit run rather than
from the header's own figure. That reading of the *cause* was then measured, and it is wrong.

#### What was built

Twelve rows, each the same shape as the `For_` row already there — a `StatementHook`/`ExpressionHook` trait,
`after_statement`/`after_expression`, and the mago kind, all of which the SDK's `NodeKind` enum already
declares:

| php-parser node | mago kind | php-parser node | mago kind |
|:--|:--|:--|:--|
| `Stmt\Echo_` | `Echo` | `Stmt\Unset_` | `Unset` |
| `Stmt\Break_` | `Break` | `Expr\Eval_` | `EvalConstruct` |
| `Stmt\Continue_` | `Continue` | `Expr\Isset_` | `IssetConstruct` |
| `Stmt\Declare_` | `Declare` | `Expr\Print_` | `PrintConstruct` |
| `Stmt\Global_` | `Global` | `Expr\Match_` | `Match` |
| `Stmt\Goto_` | `Goto` | | |
| `Stmt\Return_` | `Return` | | |

#### What it moved

Nothing.

- **spaze: 0 of 38 before, 0 of 38 after.** Sixteen rules refused on `no hook mapping for node type ...`
  before; four do after — `ExitDieCalls`, `FunctionFirstClassCallables`, `ElseControlStructure` and
  `RequireIncludeControlStructure`, whose kinds these rows do not cover. The twelve that moved now refuse on
  `could not find the reported message` instead. The hook was the first obstacle and never the operative one.
- **The seven census packages plus `tests/Fixtures/Rules`: byte-for-byte identical.** Emit-all over those
  eight paths and spaze, across all three targets — 151 php, 32 analyzer, 23 linter, spaze contributing none
  of them — diffed to zero against the baseline, apart from the `--out` path the `mago.toml.snippet` embeds.

The rows were reverted. What is committed is the corrected header and this record.

#### Why the second refusal is terminal, not the next step

`BreakControlStructure::processNode()` is one line: it hands the node to an injected
`DisallowedKeywordRuleErrors::get()`, which builds the message and filters on `$this->disallowedKeywords` —
a constructor parameter the package's own neon wires nowhere, because it is consumer configuration. So the
message cannot be found *and* the list it gates on has no value to carry. Unconfigured, `get()` loops over an
empty list and returns `[]`, so the rule is silent — and that is what makes both ways past the refusal wrong.
Step over the filter, as the pass does with any statement it cannot translate, and the plugin reports every
`break` in the file. Carry the filter as an empty list and it reports nothing on any file. Neither is the
rule. The refusal is correct here.

#### The instrument that said otherwise

`--survey` reports these rules as `EMIT`, because it assumes a hook and translates the body under that
assumption. It says 14 of 38; the emit run says 0. The header already carried *"read an emit figure before
sizing a package from a survey one"* and still sized the blocker from the survey's first line rather than
from what a real run reports after the hook exists. Naming the first obstacle is not naming the cause —
the same shape as the cross-class-resolution probe the guidelines record, which was also necessary, also
built, and also changed the count by zero.

#### Verification

`bin/phpstan-to-mago --out=DIR vendor/spaze/phpstan-disallowed-calls/src` before and after, read in full
rather than by its total. Emit-all over the seven packages plus `tests/Fixtures/Rules` plus spaze, three
targets, `diff -r` against a baseline built from `HEAD`'s `src/Vocabulary.php`.

### The fourth row: mago's `is_callable` over-acts where PHPStan refines

A peer session measured PHPStan on the one cell the matrix above left open — `is_callable` against a union
carrying an **unresolvable** class atomic — and asked this repository to confirm mago's half. It does not
match either candidate they named. Mago neither retains the arm nor refines it. It **eliminates** it, and
says so out loud.

No plugin, mago 1.47.6. The whole subject, and a `mago.toml` of `[source]` / `paths = ["src"]` beside it:

```php
<?php declare(strict_types=1);

namespace DraftCheck;

final class A
{
    public function f(\Totally\Gone\Klass $i): void
    {
        if (is_callable($i)) {
            $i();
        }
    }
}
```

`mago analyze` reports three errors — the expected `non-existent-class-like` on the type, and these two:

    error[impossible-type-comparison]: Impossible type assertion: `$i` of type
        `unknown-ref(Totally\Gone\Klass)` can never be `(callable(...mixed): mixed)`.
    error[invalid-callable]: Expression of type `never` cannot be called as a function or method.

The narrowed types behind those errors come from a second subject, in full:

```php
<?php declare(strict_types=1);

namespace UnresProbe;

final class Plain { public function value(): int { return 1; } }

final class Subject
{
    public function unresolvableUnderIsCallable(\Totally\Gone\Klass|callable $i): void
    {
        if (is_callable($i)) { probeUnresolvableIsCallable($i); }
    }

    public function resolvableUnderIsCallable(Plain|callable $i): void
    {
        if (is_callable($i)) { probeResolvableIsCallable($i); }
    }

    public function unresolvableUnderNotInstanceof(\Totally\Gone\Klass|callable $i): void
    {
        if (! $i instanceof \Totally\Gone\Klass) { probeUnresolvableNotInstanceof($i); }
    }

    public function stringUnderIsCallable(string|callable $i): void
    {
        if (is_callable($i)) { probeStringIsCallable($i); }
    }

    public function unresolvableAloneUnderIsCallable(\Totally\Gone\Klass $i): void
    {
        if (is_callable($i)) { probeUnresolvableAlone($i); }
    }

    public function unresolvableUnderNotIsCallable(\Totally\Gone\Klass|callable $i): void
    {
        if (! is_callable($i)) { probeUnresolvableNotCallable($i); }
    }
}

function probeUnresolvableIsCallable(mixed $x): void {}
function probeResolvableIsCallable(mixed $x): void {}
function probeUnresolvableNotInstanceof(mixed $x): void {}
function probeStringIsCallable(mixed $x): void {}
function probeUnresolvableAlone(mixed $x): void {}
function probeUnresolvableNotCallable(mixed $x): void {}
```

The reader is the whole plugin, run as an extension host (`command = ["php", "probe.php"]`). Above what
follows go `require 'vendor/autoload.php';` and imports of `Mago\Sdk\Analyzer\{FileAnalysisRequirement,
NodeAnalysisContext, NodeAnalysisHook, Plugin, PluginDefinition, PluginRegistry}`, `Mago\Sdk\Syntax\NodeKind`
and `Mago\Sdk\{Extension, Worker}`. Pasted with those, it reproduces the six rows below verbatim, which is
how they were checked before being written down here:

```php
final class P implements Plugin, NodeAnalysisHook
{
    public function getDefinition(): PluginDefinition { return new PluginDefinition('probe/type', 'P', 'type'); }
    public function register(PluginRegistry $r): void { $r->registerNodeAnalysisHook($this); }
    public function getTargets(): array { return [NodeKind::FunctionCall]; }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ArgumentTypes, FileAnalysisRequirement::ExpressionTypes,
            FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $c): void
    {
        $text = (string) $c->source->getText($c->node);
        if (! str_contains($text, 'probe')) { return; }

        $out = fopen('result.txt', 'a');
        fwrite($out, "\n=== {$text}\n");
        foreach ($c->argumentTypes as $t) {
            fwrite($out, '    ' . ($t === null ? 'NULL' : (string) $t) . "\n");
            foreach (($t?->atomicTypes ?? []) as $atomic) {
                fwrite($out, '      ' . (new ReflectionClass($atomic))->getShortName() . " \"{$atomic}\"\n");
                if (property_exists($atomic, 'refinement') && $atomic->refinement !== null) {
                    fwrite($out, '        refinement callable=' . var_export($atomic->refinement->callable, true) . "\n");
                }
            }
        }
        fclose($out);
    }
}

(new Worker(new Extension('probe/type', 'p', '0.0.0', analyzerPlugins: [new P()])))->run();
```

Read on mago 1.47.6:

    assertion       declared union                     inside the true branch
    is_callable     \Totally\Gone\Klass|callable       callable                        arm ELIMINATED
    is_callable     Plain|callable                     callable|UnresProbe\Plain       arm retained
    !instanceof     \Totally\Gone\Klass|callable       callable|Totally\Gone\Klass     arm retained
    is_callable     string|callable                    string|callable                 acts: callable=true
    is_callable     \Totally\Gone\Klass                never                           whole type ELIMINATED
    !is_callable    \Totally\Gone\Klass|callable       Totally\Gone\Klass              arm retained

Row five is the one that isolates the axis. With the unresolvable class as the *only* arm, the true branch of
`is_callable()` is `never`, and mago's own message spells the assertion out: a value of type
`unknown-ref(Totally\Gone\Klass)` *can never be* callable.

That much is measured. **Why** mago answers that way is not — nothing here reads its implementation.
"Resolution failure is treated as proof of non-callability" is an *interpretation*, and every later sentence
in this entry that phrases it as something mago "reads" or "can prove" is that interpretation restated, not a
second observation.

Rows one and two are the single-axis evidence for it: same `object|callable` shape, resolvability the only
difference, opposite outcomes. Row five is not part of that pair — it drops the callable arm as well — and it
establishes something narrower and worth having on its own: with the unresolvable class alone, the whole type
becomes `never`.

What the rows establish without any interpretation is that the answer is wrong. An unresolvable class may
declare `__invoke`, so `never` is not a sound conclusion whatever produces it.

#### The two engines are inverted, not merely divergent

The peer session re-ran their half with the true and false branches pinned on separate lines, so attribution
comes from the line rather than from output order. Joined with the six rows above, the pattern is sharper
than "diverge in opposite directions" — each engine applies elimination to precisely the case the other does
not:

                                                      mago                          PHPStan
    is_callable, resolvable, provably not callable    retains callable|Plain        `never`
    is_callable, unresolvable                         `never`                       refines to
                                                                                    callable(): mixed & Klass

PHPStan emits `never` where it **can** prove non-callability and refines where it cannot. Mago does the
reverse in both cells. Read as a disposition — and this is the interpretation flagged above, not a further
measurement — mago treats *cannot resolve* as *provably not callable* where PHPStan treats it as *cannot
prove not callable*, and the second is the sound reading.

That is what makes it a defect rather than a policy. "We narrow conservatively" cannot explain the
unresolvable row, where mago is more aggressive than PHPStan on the case with **less** information; "we
narrow aggressively" cannot explain the resolvable row. No single disposition produces both.

The intersection is worth quoting rather than paraphrasing. `callable(): mixed & Totally\Gone\Klass` asserts
that the value is callable *and* is that class — a stronger and more specific claim than "possibly callable",
and a reviewer testing the paraphrase would find it does not match the output.

#### The inversion is visible at the diagnostic level, not only in the inferred types

The peer session re-ran their half on the **released** 2.2.13 rather than the `2.2.x-dev` tree the earlier
rows came from — their own catch, made because the version label was about to go into a public document — and
reported every cell identical. They then sent their subjects and raw output, so **their column has since been
re-run here** and is no longer peer-reported; both subjects, the config and the run's output are below. On the
resolvable row PHPStan does not only narrow to `never`, it emits:

    Call to function is_callable() with Only2\Plain will always evaluate to false.
    [identifier: function.impossibleType]

Both engines therefore ship an impossible-type diagnostic for `is_callable`, **and they fire on opposite
cases**: PHPStan on the resolvable class, mago on the unresolvable one. That pairing is observation; which
engine could have proved what is the interpretation above. A maintainer can put the two messages side by side
without reading a type dump.

That narrows the ask. Mago is not missing an impossible-type diagnostic for `is_callable` — it has one, and
it points at the wrong case.

Measured here on the resolvable case, so the "mago reports nothing" half of that pairing is this repository's
row rather than an inference from the peer's. On mago 1.47.6 the file below is **`No issues found`**:

```php
final class Plain { public function value(): int { return 1; } }

final class B
{
    public function f(Plain $i): void
    {
        if (is_callable($i)) {   // PHPStan: function.impossibleType, always false
            $i();                // never reached
        }
    }
}
```

**The `$i()` is dead code, not a fatal.** `is_callable()` on a `Plain` with no `__invoke` is false at run
time, so the body never executes — an earlier draft of this entry claimed a runtime fatal here and was wrong.
PHPStan reports the *guard*, at the `is_callable()` line, and nothing at the call, because the true branch is
`never` and the body is not analysed. Mago reports neither.

That is the sharper statement anyway: the two engines disagree about whether this file has anything wrong
with it at all, not about where.

That is a sharper report than the one the draft carried, not a weaker one. The finding is no longer "mago's
`is_callable` is conservative about objects" — it is not conservative at all where it cannot resolve the
class, and the over-acting half reaches the user as a **wrong diagnostic** rather than as a missed narrowing.
`impossible-type-comparison` on a class the analyser simply could not find is a false positive on the shape
measured here — a parameter declared as an unresolvable class, guarded by `is_callable()`. Guarding an
optional dependency that way is ordinary; whether every spelling of that guard reaches the same diagnostic is
not measured.

#### The PHPStan half, reproduced here

The peer session sent its subjects rather than only its numbers, which is what made the join checkable. Run
on this tree's own `phpstan/phpstan` 2.2.13 (`vendor/bin/phpstan --version` says so), level 9, against a
throwaway config naming only the subject's path:

```php
<?php declare(strict_types=1);

namespace Only2;

final class Plain {}

/** @param \Totally\Gone\Klass $x */
function unresolvableOnly($x): void
{
    if (is_callable($x)) {
        \PHPStan\dumpType($x);   // TRUE branch, unresolvable
        return;
    }
    \PHPStan\dumpType($x);       // FALSE branch, unresolvable
}

function resolvableOnly(Plain $z): void
{
    if (is_callable($z)) {
        \PHPStan\dumpType($z);   // TRUE branch, resolvable final no __invoke
        return;
    }
    \PHPStan\dumpType($z);       // FALSE branch, resolvable
}
```

Exact command, from the directory holding `src/` and this config:

```neon
parameters:
    level: 9
    paths:
        - src
```

    vendor/bin/phpstan analyse -c phpstan.neon --no-progress --error-format=raw

The run's own output, verbatim. This environment wraps PHPStan's reporter in JSON whatever `--error-format`
asks for, so this is what the command above prints — eight diagnostics, seven from subject A and one from
subject B, with only the harness's trailing `instructions` blob removed:

    {
      "tool": "phpstan",
      "result": "failed",
      "errors": 8,
      "error_details": {
        "/tmp/ps-peer/src/A.php": [
          {
            "line": 8,
            "message": "Out of 3 possible param types, only 2 - 66.6 % actually have it. Add more param types to get over 99 %",
            "identifier": "typeCoverage.paramTypeCoverage"
          },
          {
            "line": 8,
            "message": "Parameter $x of function Only2\\unresolvableOnly() has invalid type Totally\\Gone\\Klass.",
            "identifier": "class.notFound"
          },
          {
            "line": 11,
            "message": "Dumped type: callable(): mixed&Totally\\Gone\\Klass",
            "identifier": "phpstan.dumpType",
            "ignorable": false
          },
          {
            "line": 14,
            "message": "Dumped type: Totally\\Gone\\Klass",
            "identifier": "phpstan.dumpType",
            "ignorable": false
          },
          {
            "line": 19,
            "message": "Call to function is_callable() with Only2\\Plain will always evaluate to false.",
            "identifier": "function.impossibleType"
          },
          {
            "line": 20,
            "message": "Dumped type: *NEVER*",
            "identifier": "phpstan.dumpType",
            "ignorable": false
          },
          {
            "line": 23,
            "message": "Dumped type: Only2\\Plain",
            "identifier": "phpstan.dumpType",
            "ignorable": false
          }
        ],
        "/tmp/ps-peer/src/B.php": [
          {
            "line": 9,
            "message": "Call to function is_callable() with Repro\\Plain will always evaluate to false.",
            "identifier": "function.impossibleType"
          }
        ]
      }
    }

The branches are on their own lines so attribution comes from the line rather than from output order.

Two rows need a word. `typeCoverage.paramTypeCoverage` is this repository's own noise — `tomasvotruba/type-coverage`
is installed here and registers itself through the extension installer, which is the traced source of that
row in this run. Whether any other run produces it is not measured, so a differing row count elsewhere needs
its own check rather than this explanation. And `class.notFound` is expected output for a subject whose type deliberately
does not exist. Adding `ignoreErrors: [{identifier: class.notFound}]` to the config removes that row and
leaves the other seven untouched.

**Two `class.notFound` occurrences behave differently under the same `ignoreErrors` entry**, which took a
file carrying both, run twice on this tree's PHPStan 2.2.13:

```php
<?php declare(strict_types=1);

namespace Positions;

final class ViaExtends extends \Totally\Gone\Base {}

/** @param \Totally\Gone\Klass $x */
function viaParam($x): void {}
```

`vendor/bin/phpstan analyse -c plain.neon --no-progress --error-format=raw`, with `plain.neon` the same
`level: 9` plus `paths` config as above:

    {
      "tool": "phpstan",
      "result": "failed",
      "errors": 3,
      "error_details": {
        "/tmp/ps-ign/src/C.php": [
          {
            "line": 5,
            "message": "Class Positions\\ViaExtends extends unknown class Totally\\Gone\\Base.",
            "identifier": "class.notFound",
            "ignorable": false,
            "tip": "Learn more at https://phpstan.org/user-guide/discovering-symbols"
          },
          {
            "line": 8,
            "message": "Out of 1 possible param types, only 0 - 0.0 % actually have it. Add more param types to get over 99 %",
            "identifier": "typeCoverage.paramTypeCoverage"
          },
          {
            "line": 8,
            "message": "Parameter $x of function Positions\\viaParam() has invalid type Totally\\Gone\\Klass.",
            "identifier": "class.notFound"
          }
        ]
      }
    }

The same file with `ignoreErrors: [{identifier: class.notFound}]` added:

    {
      "tool": "phpstan",
      "result": "failed",
      "errors": 3,
      "error_details": {
        "/tmp/ps-ign/src/C.php": [
          {
            "line": 5,
            "message": "Class Positions\\ViaExtends extends unknown class Totally\\Gone\\Base.",
            "identifier": "class.notFound",
            "ignorable": false,
            "tip": "Learn more at https://phpstan.org/user-guide/discovering-symbols"
          },
          {
            "line": 8,
            "message": "Out of 1 possible param types, only 0 - 0.0 % actually have it. Add more param types to get over 99 %",
            "identifier": "typeCoverage.paramTypeCoverage"
          }
        ]
      },
      "general_errors": [
        "Error message \"Class Positions\\ViaExtends extends unknown class Totally\\Gone\\Base.\" cannot be ignored, use excludePaths instead."
      ]
    }

The `@param` row is gone; the `extends` row is not, and PHPStan says why in `general_errors`. So
`ignorable: false` is not decoration — it resists identifier-based suppression.

#### The mechanism, read rather than inferred

An earlier version of this entry stopped at "these two occurrences differ", because naming a cause needed
PHPStan's implementation and nobody had read it. It is readable: the installed 2.2.13 ships its source inside
the phar, at `phar://vendor/phpstan/phpstan/phpstan.phar/src`. Every `class.notFound` construction site in it,
counted mechanically:

    'class.notFound' construction sites   28
    of which call ->nonIgnorable()         1
        Rules/Classes/ExistingClassInClassExtendsRule.php:56   (phar; :65-66 in the git source)

That one site is the `extends` row above:

    RuleErrorBuilder::message(sprintf('%s extends unknown class %s.', ...))
        ->identifier('class.notFound')->nonIgnorable();

The `@param` row comes from `Rules/Functions/ExistingClassesInTypehintsRule.php:34`, which hands the
`Parameter $%s of function %s() has invalid type %s.` template to `FunctionDefinitionCheck::checkFunction()`,
where it is built at `FunctionDefinitionCheck.php:126` with a plain `->build()`.

#### Two correct citations that contradict each other

The line numbers above are the phar's. The peer session's were the repository's, and an earlier version of
this entry recorded theirs as wrong. They are not. **The phar's copy holds each fluent chain on one line
where the repository's spreads it over several** — see below for how far the cause is traced — so the same
file is 150 lines in one and 87 in the other, and everything after the first chain shifts up.

    artefact      file length   the class.notFound site            what sits at :65
    git 2.2.13    150 lines     :65 identifier, :66 nonIgnorable   ->identifier('class.notFound')
    phar 2.2.13    87 lines     :56, whole chain on one line       ->identifier('class.extendsInterface')

The phar side is read directly out of `phar://vendor/phpstan/phpstan/phpstan.phar/src`. The git side was
fetched here, not taken from the peer:

    gh api "repos/phpstan/phpstan-src/contents/src/Rules/Classes/ExistingClassInClassExtendsRule.php\
        ?ref=2.2.13" --jq .content | tr -d '\n' | base64 -d

    150 lines, and:
      60  $errorBuilder = RuleErrorBuilder::message(sprintf(
      61      '%s extends unknown class %s.',
      ...
      64  ))
      65      ->identifier('class.notFound')
      66      ->nonIgnorable();
      82      ->identifier('class.extendsInterface')

The same fetch on `FunctionDefinitionCheck.php` at that tag gives 901 lines and `class.notFound` at `:173`,
`:260`, `:330`, `:453` and `:543` — so the peer's `:173` and `:453` are both real sites there.

**The trap is that `:65` exists in both and holds a different identifier in each**, so checking the phar
returns a confident, self-consistent answer that makes the other citation look wrong. Re-deriving is what
surfaced the discrepancy at all; it is not what resolved it. What resolves it is naming the artefact a line
number indexes — "src/Rules/..." meant the repository on one side and the phar on the other, and the paths
are spelled the same.

For an issue, quote the git line. That is the file a maintainer opens.

#### How far the reprint is traced

Two build configurations were read at the `2.2.13` tag rather than assumed, both fetched with
`gh api "repos/phpstan/phpstan-src/contents/<path>?ref=2.2.13" --jq .content | tr -d '\n' | base64 -d`:

`build/downgrade.php` — **`vendor` is not among the paths**:

    return [
        'composerJson' => __DIR__ . '/../composer.json',
        'paths' => [
            __DIR__ . '/../build/PHPStan',
            __DIR__ . '/../src',
            __DIR__ . '/../tests/PHPStan',
            __DIR__ . '/../tests/e2e',
        ],
        'excludePaths' => [ 'tests/*/data/*', ... ],
    ];

`compiler/build/box.json` — one compactor:

    "compactors": [
      "KevinGH\\Box\\Compactor\\PhpScoper"
    ],
    "directories": ["conf", "src", "resources", "stubs"],
    "php-scoper": "compiler/build/scoper.inc.php"

That suggests a bundled *vendor* file as the row separating them, and one is available at the same version on
both sides — the phar's `vendor/composer/installed.php` reports `phpstan/phpdoc-parser 2.3.5`, which is what this
repository installs. `src/Ast/Node.php` from that package, 22 lines here and 18 in the phar:

    <?php declare(strict_types = 1);      ->   <?php
                                               (blank)
                                               declare (strict_types=1);
    tabs                                  ->   four spaces
    blank lines between members           ->   removed

**A reprint reaches bundled vendor code, and which stage applies it is not traced here.** The measured part
is the reprint itself: same package, same version, different formatting in the shipped artefact.

Everything past that is inference, and it is labelled rather than asserted. `vendor` not appearing in
`downgrade.php`'s `paths` is not proof the downgrade never reached this file. `build/PHPStan` holds one entry,
`Build`, at that tag (`gh api "repos/phpstan/phpstan-src/contents/build/PHPStan?ref=2.2.13" --jq '.[].name'`),
but the compiler's `PrepareCommand.php:199-202` walks `$vendorDir . '/phpstan/phpdoc-parser/src'` in a
`$finder->files()` loop for its turbo-stub pass — a read there, not a rewrite, but the full build order was
not enumerated.

Nor does the row say which stage joins the fluent chains. phpdoc-parser 2.3.5 holds no multi-line fluent
chain to test a stage on by itself: searched by scanning every `.php` under `vendor/phpstan/phpdoc-parser/src`
for a line whose first non-space characters are `->`, which returned nothing.

What would settle it is instrumenting the build, which is more than this question is worth here. **The finding
that needed a cause was the citation split, and that one is fully measured**: git `:65` and phar `:56` both
read, the reprint confirmed on a same-version file, and the practical rule — name the artefact a line number
indexes — standing whatever produces the difference.

A peer session offered `src/TrinaryLogic.php` for this, on the grounds that it carries no 8.x syntax for the
downgrade to lower. It carries one, at git `:59` against phar `:54`:

    git    private function __construct(private int $value)
           {
           }

    phar   private function __construct(int $value)
           {
               $this->value = $value;
           }

which is the promotion lowered. The 323-to-265-line shrink with all 73 comment lines intact
reproduces exactly — `count(file($path))` on each copy, and a comment count of lines whose first non-space
characters are `*`, `/*` or `//` — so the comment-stripping explanation is out — but the downgrade demonstrably processed
that file, so it cannot separate the two transforms. Nor, on the same reasoning, is any file under
`src` likely to — `downgrade.php` lists `src` and `box.json` lists `src` — though that is configured scope
rather than traced traversal, and this entry does not claim more.

**The unit is the individual error construction.** Not the identifier — 27 of the 28 sites are ignorable. Not
the position either, and that fails on its own terms rather than by argument: `ExistingClassInInstanceOfRule.php:69`
raises `class.notFound` about a class name in a class-name position and is ignorable. Position only correlates
because different positions are checked by different code. It is finer than "per rule", too:
`FunctionDefinitionCheck.php` calls `nonIgnorable()` eight times and none of them is one of its five
`class.notFound` sites.

**Both sentences this paragraph has carried were a cell stated as a property**, and the correction written
here was the second of them. "`class.notFound` is ignorable" was measured on the `@param` occurrence and is
true only there. The peer session's "non-ignorable" reached this file attached to a `@param` subject, where
the run above shows it does not hold; they later reported having measured it on an `extends` subject, which
matches the `extends` row above — that run is theirs and is not reproduced here. They also sent the mechanism
with file and line, and the mechanism is right. Neither sentence was a fact
about `class.notFound`, and the pair of rows above is what shows why.

Subject B, in full, is the guard reproducer — its single row is the last one above:

```php
<?php declare(strict_types=1);

namespace Repro;

final class Plain {}

function guard(Plain $i): void
{
    if (is_callable($i)) {
        $i();
    }
}
```

**Nothing at line 10**, where the call is. That is the precision the peer session insisted on, and it is
worth having: "PHPStan catches the fatal call" would be wrong in a way a maintainer disproves in ten seconds.
The true branch is `never`, so the body is not analysed and the diagnostic lands on the guard.

#### Controls

Four of them, and each must answer the way it does for the axis row to mean what it says. The single-axis
*pair* is rows one and two; these four are what stop that pair reading as something it is not:

- **Row three** holds the atomic constant and changes the assertion. `!instanceof` retains the same
  unresolvable arm, so the elimination in row one is `is_callable`'s doing and not a general disposal of
  unresolvable atomics.
- **Row six** holds the assertion and flips the polarity. The arm survives in the false branch, so it exists
  in the declared type and was removed rather than never present — which is the reading a single row cannot
  separate.
- **Row four** is the positive control for the assertion itself: without it, "`is_callable` eliminated the
  arm" is indistinguishable from "`is_callable` was not reached". Read off the model rather than the
  rendering, because `__toString()` prints `string|callable` either way — the `ScalarType`'s refinement is a
  `StringType` whose `callable` field is `true` after the guard, which is the only place the action shows.
- **Row two** is the previously recorded resolvable case, re-run here so both halves come from one file on
  one version rather than from two runs compared across time.

#### Standing

Three mago subjects, because they answer different questions, and all three are written out above rather than
pointed at — the six-method file behind the type table, the unresolvable reproducer, and the resolvable one —
along with the plugin the first one needs. The other two need none. Each ran under `mago analyze` on 1.47.6 with a `mago.toml` naming `src` as its
only path and nothing else configured.

**Both columns are measured here.** The mago cells are this repository's throughout, on 1.47.6. The PHPStan
cells were the peer session's first — branch-attributed, and re-run by them on the released 2.2.13 after they
caught their own version label naming a `2.2.x-dev` tree — and they then sent the subjects rather than only
the numbers, so subject A's seven rows and subject B's one have since been run on this tree's own PHPStan 2.2.13
and matched. That is the run this entry quotes. Their earlier `2.2.x-dev` and released-2.2.13
runs are reported, not repeated here; the cells they cover are the same cells. Neither column depends on the
transpiler.

**The asymmetry that remains is one of order, not of evidence.** Each engine was measured first by the
session that owned it, so the join was two reports before it was one run; what makes it checkable now is that
both subjects are written out above, and re-running either takes a config file and a copy-paste. An earlier
version of this entry marked the PHPStan half peer-reported and told a reader to re-run it before quoting it.
That instruction was right, and following it is what removed the need for it.

### A `phpOnly` audit I published from the wrong table

A peer session, closing an exchange about configured scope versus observed behaviour, noted that this
repository makes the same move in its own tables: *a row saying a hook is PHP-only is a declaration about our
table, not a measurement of Mago.* The observation is worth acting on. What was written here first was not.

The audit reported 34 rows, 28 of them `phpOnly`, and 22 whose stated reason this repository's own
`ModuleEmitter` contradicted. **Every one of those figures came from the wrong constant**, so they are
recorded here as what a broken run printed and not as findings. They have not been re-derived for this entry
and should not be: the row counts follow from the faulty slice below, and the `22` came from a further pass
matching each row's `'trait'` against `ModuleEmitter::module()`'s match arms, whose output is not preserved.
This was the locating step, which is the part worth keeping:

    s = open('src/Vocabulary.php').read()
    i = s.index('HOOK_KINDS'); j = s.index('];', i); block = s[i:j]

`s.index('HOOK_KINDS')` matched a *comment mentioning* `HOOK_KINDS` at `src/Vocabulary.php:127`, inside the
body of `Vocabulary::HOOKS`, which starts at line 78. So `block` was a truncated slice of `HOOKS` — a
different table with different rows — while every sentence built on it named `HOOK_KINDS`. The constant it
named starts at line 1003.

A second reader caught it, along with three consequences the misparse had made invisible: one of the "22
contradicted" rows uses `ClassLikeMemberHook`, which an audit already in this file records as *invented by
this repository*; the claim that `Trait_` is the only row ever checked against Mago's registry contradicts an
earlier peer registry inspection recorded above; and the `git log -S` command offered as history cannot show a
`phpOnly` removal, because removing a flag from a retained row does not change the occurrence count of
`Trait_::class`.

The section is withdrawn rather than patched. Its foundation was a string match, not a parse, and correcting
the arithmetic on top of that would have produced a more careful wrong answer.

#### Why this one is worth keeping in the record

It was written in the working tree on top of `cbe91bd`, **one commit after** `42ae170` extended the guideline
about claims that outrun their rows, and it is a
cleaner instance than any in that section: no clause of it was licensed by any row, because the rows were from
another table. It also passed every check this file normally relies on. The figures were internally
consistent, mechanically derived, reproducible by re-running the same script, and cross-checked against a
second source — `ModuleEmitter`'s match arms — which is the shape of a well-evidenced finding. **An
instrument pointed at the wrong object produces all the same signals as one pointed at the right object.**

The check that would have caught it is one line: after locating a structure by name, assert that what you
found is that structure. `s.index('HOOK_KINDS')` finding a comment and `s.index('HOOK_KINDS')` finding the
constant are indistinguishable to everything downstream.

#### The audit, rerun

A peer session cloned Mago at `1.47.6` and ran both halves, with a standing note to rerun rather than quote.
Rerun here, and **the interpretation is not repeated: an entry above already covers this ground with better
evidence** — it names the invented trait spellings, records `NullSafeMethodCallHook` and `ProgramHook` as
traits that exist and are flagged PHP-only anyway, and refutes "one Rust hook registers one kind" for
`ExpressionHook` specifically, using a shipped snapshot. Read that entry, not this one, for what the rows
mean. What follows is arithmetic against the table as it stands today.

Registry, at `1.47.6` — the eight `.rs` files under `crates/analyzer/src/plugin/hook/` fetched with
`gh api "repos/carthage-software/mago/contents/crates/analyzer/src/plugin/hook/<f>.rs?ref=1.47.6" --jq
.content | tr -d '\n' | base64 -d`, then `grep -oE 'pub trait [A-Za-z]+' | sort -u`. **13**, cross-checked
against thirteen `register_*_hook` methods in `registry.rs`, and reproducing the peer's list exactly:

    ClassDeclarationHook  EnumDeclarationHook  ExpressionHook  FunctionCallHook
    FunctionDeclarationHook  InterfaceDeclarationHook  IssueFilterHook  MethodCallHook
    NullSafeMethodCallHook  ProgramHook  StatementHook  StaticMethodCallHook  TraitDeclarationHook

`Vocabulary::HOOKS`, parsed under the assertions below:

    rows with a trait                              50
    of which phpOnly                               34
      naming a trait in that list                  23   ExpressionHook 14, StatementHook 6,
                                                        ClassDeclarationHook 1, NullSafeMethodCallHook 1,
                                                        ProgramHook 1
      naming a trait not in it                     11   ArrayHook, AttributeHook, AttributeListHook,
                                                        BinaryHook, ClassLikeMemberHook, ClosureHook,
                                                        ForeachHook, MethodPartialApplicationHook,
                                                        PropertyAccessHook, StaticMethodPartialApplicationHook,
                                                        StaticPropertyAccessHook

**The 23 agree exactly across two sessions and two instruments.** The totals do not. The peer reported 47, 31
and 8 — their figures, not rerun here — and the gap is three rows in each. The three their absent-trait list
omits are `ArrayHook`, `AttributeListHook` and `BinaryHook`, whose rows (`Array_`, `AttributeGroup`, `Concat`)
are the three in this table written across several lines rather than one. That was recorded here as a
correspondence rather than a diagnosis, and they then diagnosed it: their row regex ran without `re.DOTALL`,
so `(.*?)` could not cross a newline and every multi-line row was invisible. With the flag they get 50, 34
and 11, and the three that appear are those three by name.

**The agreement on 23 was luck, and that is the part worth keeping.** All three rows their extractor dropped
named *absent* traits, so the whole error landed in the other bucket. Comparing only that subtotal would have
shown an exact match across two sessions and two instruments and certified both as sound. **An exact
agreement on a subtotal is not evidence about the totals.** It is a sharper form of the *agreement on zero*
rule recorded above: a zero from two tools fails to reveal a difference, and this matching subtotal actively
concealed one.

The earlier entry lists eight of these names rather than eleven, and the same three are the difference. A
chronological explanation was drafted here and was wrong — `git log -S` dates all three rows to 18 and 20 and
29 August against that entry's `2445865` on 2 September, so every one of them existed when it was written.
Why it lists eight is not traced.

One of the eleven is worth separating. `ModuleEmitter::module()` throws `no registration for ...` on ten of
them, so `phpOnly` keeps rows that could not be emitted off a target that could not take them.
`ClassLikeMemberHook` is not one: `ModuleEmitter.php:38` maps it to `register_class_like_member_hook`, which
the 1.47.6 registry does not declare — so that row would emit Rust naming a registration that does not exist,
and `phpOnly` is the only thing preventing it.
**Superseded — see "The analyzer target emits two hook names Mago does not declare" below.** `phpOnly`
prevents nothing about this trait: four *other* rows name it without the flag, and the analyzer emit writes
four files using it, one of them a committed snapshot. The `FunctionLike` row this sentence is about is
guarded; the trait is not.

This is a name-set intersection. Whether a named trait is the right registration for its node shape is not
shown by a name matching a name, and nothing here is changed on the strength of it.

#### The assertions, now in the instrument

The rerun carries the two the peer proposed, and both are load-bearing rather than decorative:

    m = re.search(r'^\s*public const array HOOKS = \[$', src, re.M)
    assert m                                              # an anchored declaration, not a mention
    block = src[m.end():src.index('\n    ];', m.end())]
    assert block.count('public const array') == 0         # did not swallow a later constant

The first is the one that catches the withdrawn run: `s.index('HOOK_KINDS')` landing on a comment fails an
anchored declaration match. The second catches the mirror image, where the closing delimiter found belongs to
something after the table — the same class of error producing a superset rather than a truncation, and failing
silently in the opposite direction.

#### The boundary was accurate for this position and not for the question

"No route found is not the same as no route" was the right thing to write, and the route existed:
`git clone --depth 1 --branch 1.47.6 https://github.com/carthage-software/mago`, then
`crates/analyzer/src/plugin/`. What had been searched was the installed source and one CLI subcommand, which
is what "no route found" meant and all it meant. **A limit found by exhausting the artefacts you happen to
have is a fact about your position**, and the artefact that answered this one was a `git clone` away.

#### What still stands

Acting on `HOOKS` moves emitted bytes on two targets, and the earlier entry is the one that has done the work
to justify such a change. This one contributes counts to it.

### The analyzer target emits two hook names Mago does not declare

A peer session, diagnosing its own extractor, noticed a second `ModuleEmitter` mapping with no counterpart in
Mago's registry and flagged that its row carries no `phpOnly`. Checked here, and it is wider than one row and
reaches committed output.

#### Measured

`ModuleEmitter::module()` knows ten trait-to-registration mappings. Two name a function `registry.rs` at
`1.47.6` does not declare:

    ClassLikeMemberHook  ->  register_class_like_member_hook   MISSING
    AnalysisHook         ->  register_analysis_hook            MISSING

The traits are absent too, and `crates/analyzer/src/plugin/hook/mod.rs` settles it by enumerating the surface
in its own docblock: thirteen hooks, neither of these among them. Nothing under `plugin/` mentions either
name; the only `ClassLikeMember` hits in that module are `mago_syntax::cst::ClassLikeMemberSelector`, which is
unrelated.

Five rows in `Vocabulary::HOOKS` name one of those two traits **and carry no `phpOnly`**:

    CollectedDataNode    AnalysisHook
    ClassMethod          ClassLikeMemberHook
    InClassMethodNode    ClassLikeMemberHook
    Property             ClassLikeMemberHook
    ClassConst           ClassLikeMemberHook

#### It is reached, and it is committed

Not hypothetical, and the size of it was measured rather than projected. `bin/phpstan-to-mago
--target=analyzer --out=DIR` over the seven corpus packages plus `tests/Fixtures/Rules` emits **34** files.
Six of them carry `impl ClassLikeMemberHook for ...`, and `generated/mod.rs` carries six matching
`registry.register_class_like_member_hook(...)` lines:

    AnyConstantHelperRule.rs   NoMockObjectAndRealObjectPropertyRule.rs
    PropertyNameRule.rs        TestCaseOnlyRule.rs
    ThrowingAssertionGuardRule.rs   BoundNameComparisonRule.rs

Flagging the five rows `phpOnly` in a scratch copy and re-emitting removed exactly the affected files, by
name, `diff` of the two file listings. Two figures here have already gone stale once each and both are
recorded rather than quietly corrected: an earlier draft said *eight* files, from `grep -rhoE 'impl
...Hook for'` counting rule files *and* `mod.rs` lines; and the count was **32 files, four affected** until
fixtures added for unrelated folds moved it twice: `ThrowingAssertionGuardRule` made it 33 and five,
`BoundNameComparisonRule` 34 and six. Both are `ClassMethod` rules, so both take `ClassLikeMemberHook`; the
figure moves whenever one is added, and it is re-derived rather than adjusted each time. The second is this file's own *"a claim can be damaged by an
edit that was not about it"*, caught by re-deriving the figure when the emit counts moved.

A fifth rule carries it and is not in either count. `tests/Fixtures/expected-rust/UppercaseConstantRule.rs:13`
is `impl ClassLikeMemberHook for UppercaseConstantRule {` — a **reviewed snapshot** — while the batch run
above refuses that rule for an unrelated reason: `two rules would be written to UppercaseConstantRule.rs`,
because `symplify/phpstan-rules` ships a class of the same short name. Emitted on its own it emits, with the
trait. So the corpus figure understates the reach by one, and the snapshot is the more damning artefact of the
two: it is committed, reviewed, and names a trait that does not exist.

`register_analysis_hook` appears nowhere in an emit run, which matches what the baseline notes already record
— no rule in the corpus reaches the whole-run hook. That row is unguarded but unreached.

#### How much committed output is wrong: one snapshot of three

The four-files figure answers "how much would change if the rows were flagged". A different question is how
much committed output is wrong today, and a sweep of every reviewed Rust snapshot bounds it. Each `impl X for`
checked against the traits Mago declares at `1.47.6`:

    expected-rust/ForbiddenStaticConstFetchRule.rs   Provider, ExpressionHook          both exist
    expected-rust/QuotedClassNameMessageRule.rs      Provider, ExpressionHook          both exist
    expected-rust/UppercaseConstantRule.rs           Provider, ClassLikeMemberHook     ABSENT
    expected-lint/*.rs                               Config, Default, LintRule         all exist

This round is a weaker one and says so: `codex-review` could not run — the account hit its usage limit. The
external reader is the check that caught the wrong-table audit two entries above, which is the largest defect
recorded in this exchange, so a round without it is a round with a **known weaker check** rather than merely
one fewer, and a later reader comparing rounds should not treat them as equivalent. The in-house pass did find a defect here: a `Config`
line cite true of one build configuration and silently false of the other.

`Provider` is `crates/analyzer/src/plugin/provider/mod.rs:29`; `LintRule` is
`crates/linter/src/rule/mod.rs:65`, and `Config` is declared twice in that file behind
`#[cfg(feature = "serde")]` and `#[cfg(not(...))]` at `:42` and `:54`, so it exists either way; `Default` is
std's. **One snapshot of three carries the defect,
and the other absent trait — `AnalysisHook` — reaches no snapshot at all.** Not systemic across the fixtures.

#### There is no third state

Whichever way the rows go, that snapshot is wrong today. Flagging them `phpOnly` fixes it by deleting the
file; leaving them fixes nothing and keeps a reviewed fixture asserting a trait that does not exist. What
there is not is an option where the snapshot stays as it is and is correct.

#### What it costs, and what it does not

Nothing here installs this. Only the `php` target is installable — that part is this repository's own
behaviour and is measured. The two Rust targets emit source for a fork to compile in, and *that stock Mago has
no path for loading such a plugin from outside its own tree* is an **inference** from the architecture read at
`1.47.6`, not from a survey of Mago's loading surfaces. Whether anyone has compiled this output into a fork —
the target's documented purpose — is not something any of this measures.

What it does cost is the meaning of the analyzer figures. "34 analyzer files emitted" counts six that name a
trait a fork would have to write before the file compiled — which is a different claim from the one the number
looks like it is making, and the same shape as `PhpBackend::checked()`: a file appeared, and what was counted
was that it appeared.

#### Not fixed here

The fix is presumably `phpOnly` on those rows, which removes every affected file from the analyzer emit —
five as this is written, measured in a scratch copy restored afterwards — and changes a committed snapshot. That is a deliberate reduction in what a target claims to cover, and it is the
user's call rather than a correction to make on the way past. **Recorded, not acted on.**

The peer declined to assert reachability without running it, which was right, and running it is what turned a
row-level observation into a committed-snapshot one.

#### Verification

`ModuleEmitter::module()`'s match arms against `fn register_*` in `crates/analyzer/src/plugin/registry.rs` at
`1.47.6`; the trait list against `pub trait` in `plugin/hook/*.rs` and the docblock in `hook/mod.rs`; the
`HOOKS` rows by the anchored-declaration parse recorded above; the emitted output by
`bin/phpstan-to-mago --target=analyzer --out=DIR <seven packages> tests/Fixtures/Rules` before and after
flagging the rows in a copy of `src/Vocabulary.php` restored from `/tmp` afterwards, compared by `diff` of the
two `generated/*.rs` listings (32 against 28); and the snapshot by `grep` over `tests/Fixtures/expected-rust`.

### A prediction recorded before the movement it predicts

Four of the corpus sweep's only-port findings will close on a future dependency bump, for a reason that has
nothing to do with this tool. Written down **now**, while the cause is known and the effect has not happened,
because a sweep cannot tell an improving tool from an improving corpus once the number has moved.

    corpus-sweep.md   port  Helper/QuestionHelper.php:272  302  350  385

All four are the `callable(string):string[]` annotation in `symfony/console`, which binds the `[]` to the
callable rather than to the return type. A peer session raised it upstream and it is **merged**:
`symfony/symfony#65860`, into `7.4` at `cbf9781f6425a7f6ec36b09bdee1dd9d2c7a5c5a`, 2026-09-06 07:53Z. The
merged form is not the parentheses that were proposed — the maintainer wrote `callable(string):array<string>`,
removing the trailing `[]` so nothing is left for a parser to bind.

Verified here rather than taken: the PR is merged into `7.4` at that sha, `contents/...QuestionHelper.php`
at `ref=7.4` reads `@param callable(string):array<string>` at line 238, and this repository's pin —
`symfony/console v8.1.6` — still reads `@param callable(string):string[]` at line 259. So all four findings
still reproduce today, and will keep reproducing until the merge reaches `8.x`, a console release carries it,
and this repository bumps.

**When that happens the only-port trend reads 21 → 17, and none of it is this tool.** The trend already
recorded here is 448 → 25 → 23 → 21, and every step of it has been read as the port improving. This step will
not be, and the sweep has no way to say so — the divergence simply stops being printed.

This is the only case in the corpus where the cause is known before the effect, which is what makes recording
it worth a section. Corpus drift and tool change are indistinguishable in an aggregate; a prediction written
in advance is the one instrument that separates them, and it works exactly once per known cause.

#### Verification

`gh api repos/symfony/symfony/pulls/65860` for the merge state and sha; `contents/...?ref=7.4` for the merged
annotation; `vendor/symfony/console/Helper/QuestionHelper.php:259` and `installed.json` for the pin; the four
line numbers from `tests/Fixtures/expected/corpus-sweep.md`. The upstream discussion and the maintainer's own
independent confirmation of the precedence claim are the peer session's, reported and not reproduced here.

### `possiblyUndefined` is not the definedness question, measured on three states

A peer session traced local definedness from mago's analyzer through the protocol into the SDK and found
`Type::$flags->possiblyUndefined` — `@api`, public, on every type a plugin already receives. Their chain is
right and reproduces here: `TypeCodec.php:113-114` writes bits 3 and 4, `:765-766` reads them back, and
`Type/TypeFlags.php:17-18` exposes both. They then withdrew the upstream ask on the strength of it, and asked
for the two things they could not run.

Run, on one file with three variables in three definedness states, read from a node hook's `argumentTypes`:

    $definite = 1;  probeDefinite($definite);        int    possiblyUndefined = false
    if ($c) { $maybe = 2; }  probeMaybe($maybe);     int    possiblyUndefined = TRUE
    probeUndefined($neverAssigned);                  mixed  possiblyUndefined = false

Mago's own diagnostics agree about the file — `possibly-undefined-variable` on the second and
`undefined-variable` on the third — so the three states are the three states.

**The flag does not give the trinary.** A variable that was never assigned arrives with
`possiblyUndefined = false`, the same answer as one that is definitely defined. Every one of the eleven
`TypeFlags` fields is identical between those two rows; the only difference is the type itself, `mixed`
against `int`, and a variable declared `mixed` and definitely assigned is then indistinguishable from one
that does not exist.

So the flag answers *maybe*, and collapses *yes* and *no* into its negation. What
`OverwriteVariablesWithForeachRule` and `OverwriteVariablesWithForLoopInitRule` ask is
`hasVariableType($name)->yes()` — definitely defined — which is exactly the half this cannot separate.

#### Why the flag is clear on an undefined variable

The rows say the flag cannot separate *yes* from *no*. They do not say why, and the reason matters for what
gets asked for. A peer session traced it, corrected its own first attempt at the field, and the citations
verify at 1.47.6: `crates/analyzer/src/expression/variable.rs` discriminates at the read site with `locals`
presence (`:142-143`, defined) and `variables_possibly_in_scope` membership (`:145`, possibly defined),
and the undefined case reports `UndefinedVariable` (`:232`) and returns `Rc::new(get_mixed())` (`:234`).

**A freshly constructed union carries default flags.** So `possiblyUndefined` is clear on the undefined row
because nothing set it, not because definedness was considered and denied. The probe row and the source line
are the same fact from two ends, and together they say the flag was never the carrier — a reader of the rows
alone might reasonably conclude the flag is the thing to fix.

`possibly_undefined_variable_ids` at `:239` is a *separate* later check, on a variable that **is** in `locals`
and whose type already carries the flag; the `match` closes at `:237`. It was named as half of the
discrimination in a first version of this trace and it is not.

#### What that changes

The upstream ask is **not** dissolved; it is narrowed, and the narrowing makes it easier to argue. The SDK
already carries the harder half across the protocol, so what is missing is not a new capability but the other
bit beside one that is already there.

It also confirms, by measurement rather than by failing to find a method, the note in this repository's
guidelines that those two rules are blocked on `hasVariableType()`. That was written from an absence — no SDK
method with `variable`, `locals` or `scope` in its name — and an absence is what the peer session correctly
identified as the weakest kind of evidence, having reached the opposite conclusion from the same kind of
search. The rows above are the positive form of it.

#### The three rules require it; none is blocked on it

A peer session read the draft against this repository's own census and caught an overstatement in it. The
draft said `OverwriteVariablesWithForeachRule` and `OverwriteVariablesWithForLoopInitRule` were *blocked* on
definedness. The census says otherwise, and so does the census header, which names this exact rule as its
example of a needs list under-reporting:

    OverwriteVariablesWithForLoopInitRule   no iteration mapped for ->init
    OverwriteVariablesWithForeachRule       guard body is neither `return []` nor `continue`, but Stmt_Foreach
    NoJustPropertyAssignRule                no node predicate for instanceof Expr on a bytes

Each refuses earlier, for an unrelated reason. Symptom B's rule is the same story measured here rather than
read: probing past that predicate put it on `$this->phpDocResolver->resolve()`, and the tags are behind
*that*. So the ask is a requirement three rules eventually have, not an unblock count, and the draft now
gives the chain per rule instead of the claim.

The rule that catches this is in this repository's own census header — *"Where a count decides work, read the
rules it is made of"* — and it was not applied to the document that would carry the count outward.

#### Verification

One subject, three variables, one node hook on `NodeKind::FunctionCall` requiring `ArgumentTypes` and
`ExpressionTypes`, dumping `get_object_vars($type->flags)` for each argument; mago 1.47.6. The SDK lines are
read from `vendor/carthage-software/mago/composer/src/Sdk`. The analyzer and protocol lines in the peer's
chain are theirs and are not reproduced here — the SDK end is, and it is the end this conclusion rests on.

### `isSuperTypeOf` was never an SDK gap, and this session said it was

Twice in one session this repository recorded a capability as missing from the SDK on the strength of a grep,
and twice a peer session's chain-trace found it. The second is the one that changed a decision.

**What was claimed here.** That the SDK has no type-comparison API, therefore
`MatchingTypeInSwitchCaseConditionRule` and `AssertSameWithCountRule` were blocked upstream rather than by the
transpiler. That claim was put to the user inside the question that chose what to build next.

**What is there.** `Sdk/Analyzer/TypeComparator.php`, `@api`, with `equals()`, `isContainedBy()`,
`canBeIdentical()` and `compareMultiple()`. `isContainedBy($input, $container)` is `isSuperTypeOf` with the
arguments the other way round. It is reachable from a node hook because `NodeAnalysisContext extends
LifecycleContext`, which declares `public readonly TypeComparator $types`.

Probed rather than read, from inside a node hook on mago 1.47.6:

    isContainedBy(int, int|string)        true
    isContainedBy(int|string, int)        false
    isContainedBy(TcProbe\Plain, object)  true

#### Why the grep missed it

The search was `grep -iE 'super|subtype|accept|compat|contains|assignable|comparable'` over
`Sdk/Analyzer/*.php`. The method is `isContainedBy` — **`Contained`, not `contains`** — and the class is
`TypeComparator`, which the pattern `comparable` does not match either. Two near-misses on one line, and the
file was never opened.

That is the same shape as the definedness search recorded above, and as the peer session's own
`NodeAnalysisContext` miss: **a grep for the name a concept has in the other system is a search for a shape,
and its failure is evidence about the shape rather than about the capability.** Three instances in one
investigation, across two sessions, and none of them was caught by re-running the search more carefully — two
were caught by tracing the value, and one by a class declaration that happened to be on screen.

#### What it changed

The capability is now translated: `$container->isSuperTypeOf($input)->yes()` becomes
`Support::typeIsSuperTypeOf()`, over `$context->types->isContainedBy()`. **Only the `yes` tail.** The SDK
answers a bool where PHPStan answers a trinary, so `! isContainedBy()` is *maybe or no*, and reading it as
`no` would claim a proof the comparator never gave; the other tails refuse.

**No corpus rule emits from it.** All four rules that name it refuse earlier on something else, so not even a
refusal moved — two `needs:` lines disappeared from the census and nothing else. The reason to have built it
anyway is that it was recorded as impossible, and it is not.

One operational note from the peer session, not measured here: each comparison is an RPC to the host,
memoised per distinct pair, and the SDK caps a run at `MAXIMUM_COMPARISONS = 65_536`. A rule asking this
inside a loop does not cost what PHPStan's in-process comparison costs.

#### Verification

`TypeComparator.php` and `LifecycleContext.php:29` read from the installed SDK; `NodeAnalysisContext extends
LifecycleContext` from its class line. The three comparison rows are a node hook on `NodeKind::FunctionCall`
requiring `ArgumentTypes`, calling `$context->types` on the argument types of three probe calls. The
grep that missed it is quoted from this session's own transcript.

### Mago does apply an inline `@var`, and not where the rule stands

A peer session traced `NoJustPropertyAssignRule`'s remaining wall and reported that no var-tag reader is
needed: mago applies an inline `/** @var Foo $x */` to the assignment itself
(`crates/analyzer/src/expression/assignment/mod.rs:662` calling `get_type_from_var_docblock`), so the rule's
question becomes the assignment target's type against the assigned expression's type — and `equals()` is on
the comparator this repository just started using. They marked it a source trace, unprobed.

Probed. **The first half is right and the second half does not hold at the rule's hook position.**

One file, one tagged assignment and one untagged, `Holder::$pet` declared `Animal` and the tag saying `Dog`:

    read at the variable's USE          probeTagged($tagged)     Dog
                                        probeUntagged($untagged) Animal

So the tag is applied, exactly as traced, and the two rows differ by the tag alone.

    read at the ASSIGNMENT statement    $tagged = $h->pet;    target NULL, value Animal
                                        $untagged = $h->pet;  target NULL, value Animal

**The assignment target has no type**, and neither does the assignment carry the override:

    read at the ASSIGNMENT statement    assignment span   target   value
      $tagged = $h->pet;                Animal            NULL     Animal
      $untagged = $h->pet;              Animal            NULL     Animal
      $this->pet = $pet;                Animal            Animal   Animal

`NoJustPropertyAssignRule` hooks `Stmt\Expression`, so that is exactly where it stands. The tagged row's
assignment span says `Animal` while the variable itself says `Dog` two lines later — the override is nowhere
on this node.

The property row is the control: `$this->pet` is a real expression with a span of its own, so its target
*does* type. A local variable's defining occurrence is the thing that does not.

#### Why it is missing, which the source says and the probe cannot

The peer session then traced it, and the citations verify at `1.47.6`. `analyze_assignment_to_variable`
(`assignment/mod.rs:500-724`) computes the docblock type at `:662`, binds it at `:674`, and puts it in
`block_context.locals` at `:721`. The span-keyed write is in a *different* function —
`analyze_assignment` (`:98-314`) — which records `source_type` at `:309`. Different functions, and the rows
above show what that costs: the span gets the right-hand side's type and the override goes to `locals`.

So it is not a quirk of span keying. **The value went to a store the protocol does not carry**, which is a
different fact and a more useful one: it says asking for it is coherent rather than merely desirable.

#### The cause, stated so it survives its own counterexample

A first version of that reading said `BlockContext::locals` is not marshalled. That is refutable in one
command: `possiblyUndefined` demonstrably crosses the protocol, and it is a `locals`-derived fact. The
version that survives is narrower — **the protocol carries span-keyed expression types only; `locals` is
consulted to produce those at use sites and is never itself carried, so a value that exists only in `locals`
at a given node is invisible at that node.** `possiblyUndefined` rides on a union that *was* written to a
span at a use site, so it is evidence that span-keyed types are carried rather than that `locals` is.

Checked here: nothing under `crates/analyzer/src/external/` reads `block_context.locals` — all six files
(`error.rs`, `lifecycle.rs`, `metadata.rs`, `mod.rs`, `protocol.rs`, `scan.rs`) return zero matches.

It is still a **reading of two symptoms rather than a measurement of one cause**, and surviving one
refutation is not the same as being measured. The draft in `internal/` is written to that standing: one
issue, two symptoms, each with its own evidence line, the cause offered as a reading with the source beside
it, so a maintainer who rejects the reading can still act on either symptom.

#### What that means for the rule

The exemption is still not reachable. Comparing the target's type to the value's type is the right question
and the answer is unavailable at the node the rule fires on; the tagged type appears only at a later *use*,
which is a different node and would move the finding's line.

This is the `PHPVersion::$id` shape again, and it is worth naming as such: **the value exists, is correct, and
is not at the position the rule occupies.** A trace establishes that a capability is present. Only a probe at
the rule's own node establishes that the rule can reach it.

#### Verification

One subject, two assignments differing only in the tag, on mago 1.47.6. Read twice: a hook on
`NodeKind::FunctionCall` over the arguments of two probe calls, and a hook on `NodeKind::ExpressionStatement`
over `nthExpression` of the assignment's two sides. `FileAnalysis.php:96-142` is where the span keying is
read. The `assignment/mod.rs` and `docblock.rs` line numbers are the peer session's and are not reproduced
here — what is reproduced is the behaviour they predict.

### Stubbing every unknown access path moves the emit count by one

Three folds in a row were chosen by the same reasoning — a capability that works written one way and refuses
written another — and one of the three made a rule emit. That is a poor hit rate to keep guessing at, so the
question was measured instead: **how much of the refusal set is vocabulary, and how much is shape?**

The instrument is a one-line stub. Where `resolveDescriptor()` would refuse with `access path outside the
vocabulary`, return a descriptor instead, behind an environment variable. Every rule blocked *only* by access
paths then emits, and every rule with a structural blocker still refuses. Run over the seven corpus packages:

    plain                   emitted 108, refused 95
    access paths stubbed    emitted 109, refused 94

**One rule.** `access path outside the vocabulary` is the largest refusal category in the census by count —
fourteen distinct paths across the corpus — and removing all of it at once is worth a single emission.

#### And that one is not a fold either

`NoGetRepositoryOnServiceRepositoryEntityRule` is the rule that appears. Its single path is
`$this->repositoryClassResolver->resolveFromEntityClass()`, and the helper behind it reads the entity's
**source file off disk** and runs a train of three regexes over it looking for `repositoryClass="..."`. That
is not a vocabulary entry; it is file I/O plus annotation parsing by regex.

So the honest reading of the two rows is stronger than "one rule": **no rule in the corpus is blocked only by
access paths that are cheap to add.**

#### What is actually left

With paths stubbed, the categories that still refuse are shapes rather than names:

    6  an if/elseif/else chain                    (the withdrawn OperandsInArithmetic* family)
    4  assignment value outside the vocabulary
    3  statement outside the vocabulary
    3  condition outside the vocabulary
    2  this rule reports nothing                  (NEVER — writes a file, or feeds PHPStan back)
    2  no node predicate for instanceof ErrorType
    2  no mapping for ->returnType
    2+ if statements that are not single-statement guards

Adding vocabulary entries one at a time is close to worthless for the emit count. The remaining field is
guard and statement shapes, and the census's own warning applies to the aggregate as well as to a single
rule: *grep a capability to count what it is worth before building it.*

#### Verification

`bin/phpstan-to-mago --out=DIR <seven packages>` twice, once with the stub active. The stub is not committed:
it is two lines at the refusal site and an `getenv()` guard, reverted after the run. `tests/Fixtures/Rules`
is excluded from both runs so a fixture written for a fold cannot move the figure.


### Both engine columns of the `is_callable` report are now measured here

The draft's opening line claims every claim in it is measured in this repository. Two tables in it were not:
the three-arm PHPStan column arrived from a peer session, and the practical-impact sentence generalised from
one shape to "any project". With the drafts about to be filed by that session, both were closed.

#### The three arms, both engines, one file

    arm                        PHPStan 2.2.13 level 9, dumpType     mago 1.47.6, Type::$atomicTypes
    final, no __invoke         callable(): Generator                CallableType | NamedObjectType
    non-final, no __invoke     (Open&callable(): mixed)|(callable   CallableType | NamedObjectType
                                 (): Generator)
    final, with __invoke       FinalWithInvoke|(callable():         CallableType | NamedObjectType
                                 Generator), plus
                                 function.alreadyNarrowedType

PHPStan removes, refines and retains — three answers to what it can prove. Mago returns the object atomic
unchanged in all three. The draft's table matches row for row, and both columns are now this repository's.

The `function.alreadyNarrowedType` on the third arm was not in the draft and is worth having: PHPStan says
the guard is *always true* for an invokable final class, which is the strongest form of "it considered the
question".

#### Which shapes produce the false error, and which fail silently

"A false `impossible-type-comparison` on any project where that dependency is not installed" generalised over
shapes. Measured, one file, an unresolvable `\Gone\Klass` guarded four ways:

    \Gone\Klass $i               impossible-type-comparison, then invalid-callable on the call
    ?\Gone\Klass $i              the same two
    ?\Gone\Klass via a property  the same two
    \Gone\Klass|callable $i      NO error — the arm is dropped silently, narrowing to `callable`

So the wrong diagnostic appears wherever the guarded type has **no callable member**, and the union that has
one fails the other way instead: silently, by dropping an arm that may have been the right one. Both failure
modes in one file, and the draft now carries the table rather than the generalisation.

#### The definedness draft's one inference is gone rather than marked

It carried a shared-cause reading — that both symptoms are values `BlockContext::locals` holds and the
protocol does not carry — marked as a reading. Every fact under that heading is a verified source read; only
the connection between them was inferred. With the issue about to be filed the connection is removed rather
than labelled: the four source facts are stated alone, including `variable.rs:142-145,232-234`, and what a
maintainer concludes from them is theirs. The standing table now says nothing in the issue is inferred,
which is a claim that had to be made true rather than written.

#### Verification

The three-arm subject is one file with three parameters, run under PHPStan at level 9 and under a node hook
on `NodeKind::FunctionCall` reading `Type::$atomicTypes`. The four-shape subject is one file run under
`mago analyze` with no plugin. Both are reproducible from the tables above.


### The correction reached the claim and not its summary

A peer session, told both drafts were about to be filed, predicted where "nothing rests on an assumption"
would still be false: *the sentences that only join measured facts together — provenance headers, symmetry
rows, mechanism glue* — and named the `is_callable` draft's lead-in as where a four-row table most easily
grows a fifth claim it did not measure.

It had. The four-shape table replaced *"a false error on any project where that dependency is not installed"*
in the body. The summary paragraph two screens above still said it, and the body now contradicts it: a union
that already includes `callable` produces **no** error at all.

The instance is worth its own line because the failure is not the original overstatement — that was found and
fixed — but that **the fix was applied where the claim was made and not where it was summarised**. A summary
is exactly where a reader takes a number from, and it is the part least likely to be re-read when the
evidence under it changes.

The countermeasure is the one already in this file for figures, widened: after correcting a claim, grep the
document for the claim's *other* spellings before calling it fixed. The body said "any project"; so did the
lead-in, in different words, which is why a search for the corrected sentence would not have found it.

#### Verification

`grep -c 'false positive on any project'` returns 0 after the edit and returned 1 before it. The prediction
that made it worth looking is the peer session's, quoted above.
### A second party verified both drafts, and found three defects in one of them

Both drafts were handed to the peer session for verification before filing, on this repository's exact mago
build — the official 1.47.6 release binary, `sha256 da54b7fd…`, which `composer require
carthage-software/mago:1.47.6` reproduces. They had 1.45.0 installed and said so before running anything;
1.47.6 is the release that fixed #2311, so a 1.45.0 run would have been a different experiment rather than a
check.

**The `is_callable` draft reproduced end to end**, including tables not on the priority list — the minimal
subject, the `instanceof` ladder in both polarities on both engines, PHPStan's intersection refinement, both
mago retention controls, and the diagnostics inversion. All six 1.47.6 line numbers verified against source
fetched from the tag independently.

**The definedness draft had three defects. All three reproduce here.**

#### 1. Symptom B's control did not reproduce, because the channel was never named

They hooked `NodeKind::Assignment` and read `$context->targetType`; this repository hooked
`NodeKind::ExpressionStatement` and read the span-keyed type of each side. Re-run here, both are right:

    $context->targetType, no TargetExpressionTypes    all three assignments   NULL
    $context->targetType, with it                     all three assignments   Animal
    span-keyed, per side                              NULL, NULL, Animal

Different channels, different answers, and the draft named neither. **A maintainer would have refuted the
table by running a documented channel it did not mention.**

Their row is also the better evidence, which is the part worth keeping: `TargetExpressionTypes` is the SDK's
channel *built for* the target's type, and on the `@var Dog` assignment it answers `Animal`. The override is
hidden from the dedicated channel rather than merely absent from a general one. The draft now leads with that
and keeps the span-keyed rows as the control.

#### 2. The `mixed` claim was a generalisation over shapes, again

*"A variable declared `mixed` and definitely assigned is indistinguishable from one that does not exist"* was
never measured — the only `mixed` row in the probe was the undefined one. Five definitely-assigned shapes,
reproduced here row for row:

    $v = json_decode($s)   possiblyUndefined=false  populated=false   indistinguishable
    $v = unserialize($s)   possiblyUndefined=false  populated=false   indistinguishable
    $v = $mixedParam       possiblyUndefined=false  populated=true    distinguishable
    $v = anyMixed()        possiblyUndefined=false  populated=true    distinguishable
    $v = $arr['k']         possiblyUndefined=true                     distinguishable

Three of five separate on `populated`. The draft now names `json_decode()` as the witness in the sentence, so
the claim is true of a shape rather than of a category. **This is the same failure the other draft's lead-in
had, in the draft where it had already been fixed once.**

#### 3. `possibly_undefined_variable_ids` is at `:240`, not `:239`

`:239` is `if variable_type.possibly_undefined()`; the ids check is the line after. They checked whether the
identifier had been invented — the failure class flagged about this session's own work — and it had not: it
is absent from 1.47.1 entirely, which is independent evidence the source read was of genuine 1.47.6.

#### Smaller, all verified here

`symfony/console` is **v8.1.6** and the guard is at **`TreeNode.php:75`**, not 76; the line moves between
patch releases, so the draft pins the version. Their PHPStan is `2.2.x-dev@bba3c00` rather than 2.2.13 —
every row matched anyway, and both drafts now state which build each column came from.

#### The duplicate search neither draft had done

**#2037**, *False invalid-callable after narrow down to callable type (with is_callable)*, closed 2026-07-04.
Its shape is `(callable(): void)|null` — a *null* arm, not an object arm — so it is not a duplicate, and its
reproducer still passes at 1.47.6 so this is not a regression report. It is now cited anyway: the maintainers
have accepted this class once, which is the cheapest credibility the report can buy. The old `Related` line
also claimed a `callable-string` gap was *"reported separately"*; no such open report was found, so the claim
is gone rather than left standing.

#### The cut paragraph stays cut

They declined to restore it: the draft never named which rule it was about, and reconstructing the rule and
then finding a subject where it fires is inventing evidence to fit a sentence that already exists. The corpus
finding that replaced it is confirmed — the symfony shape is real and identical.

### The `is_callable` report is filed: carthage-software/mago#2333

Filed 2026-09-06 by the peer session, after their verification pass and their own user's go-ahead. Read back
from the tracker rather than from the draft: every figure in the posted body traces to a run both sessions
made, and the two corrections their pass produced are in it.

**It went out under `SanderMuller` — this repository's own account.** The two sessions had treated filing
authority as a boundary between two users, and it turns out to be the same person on two machines. The
caution cost nothing and the reasoning was right at the time: a peer's decision cannot stand in for a user's
approval on an outward action, and neither session could see whose account the other held.

What the peer changed, all of it correction or ordering:

- **The unresolvable half leads.** It is the half a user sees as an error; the kept-arm half needs a type
  dump to see at all. A maintainer's first screen is now a wrong diagnostic on ordinary code.
- **`symfony/console` v7.4.16, `TreeNode.php:75`**, with the `@var` quoted so the shape is checkable without
  the version. Confirmed here on v8.1.6, where the guard is also at `:75`.
- **Versions are what they ran**: the 1.47.6 release asset, and PHPStan `2.2.x-dev@bba3c00` rather than
  2.2.13. Every row matched on that build, so nothing is softened — the build is simply named.
- **`#2037` cited with its disposition**, and the old `callable-string` "reported separately" claim removed
  rather than left standing, because no such open report exists.
- **The suppression point** went in as what is true — `non-existent-class-like` is correct, and suppressing it
  does not remove the `impossible-type-comparison` — with nothing resting on the pragma syntax they could not
  get working.

#### What separated the check that worked from the one that nearly cost a finding

Their closing observation, and it is the sharpest thing in the exchange. Three of this session's corrections
came from re-reading its own text; two of theirs came from running its tables on shapes it had not run.
**Neither of us caught anything by reading more carefully in the same direction.** The check that worked was
changing the instrument.

And changing the instrument is also what nearly destroyed symptom B: they read `$context->targetType` where
this repository had walked to each side, got a different answer, and the difference read as a refutation
rather than as a second measurement. Same move, opposite outcomes. What separates them is asking **whose
instrument produced the number** before deciding what the number means — which is the positional rule already
in this file, turned around: a value can be right, and be an answer to a question you did not ask.

The definedness report is held on their recommendation until its control is settled. The three items are
answered in this repository already — the channel is named, `json_decode` is the witness, `:240` is corrected
— and their verification of those answers is outstanding.

### A third instrument settles symptom B, and it is better than either of the first two

The peer session confirmed the control without the probe that was sent, on an instrument neither of us had
used: a hook registered for `PropertyAccess` and `DirectVariable`, calling `getExpressionType($node->span)`
per node. No walk, no `targetType`. Reproduced here:

    $this->pet    assignment target, a PropertyAccess    Asg\Animal
    $tagged       defining occurrence                    NULL
    $untagged     defining occurrence                    NULL
    $tagged       at a later use                         Asg\Dog
    $untagged     at a later use                         Asg\Animal

**All three parts of the symptom are in those five rows** — the override applied and visible at a use, absent
at the assignment, and the by-design answer refuted by the property target typing in the same reading.

It is better than either earlier table for a reason worth naming: **the divergence that cost a day is not in
it**. Every row is the same call on a different node, so there is no channel for a reader to have picked
differently. The span-keyed walk and the `targetType` row are now corroboration rather than the spine, and
`targetType` keeps its sharpest form as a closing fact — the channel built for the target's type answers
`Animal` on the `Dog` row.

#### Why the countermeasure did not fire

The rule against generalising over shapes was already written down when the same failure happened again, in
the next draft. Their diagnosis is the useful one and it is now in the guidelines: **that failure was in
scope**. Drafting is the activity the rule is about. It did not fire because remembering a rule at the moment
of writing uses the same faculty that produced the error.

What caught it both times was someone else running the table on a shape the author had not chosen. That is
not a rule and cannot be written as one, so the practical form is: where a claim matters, budget for a second
party rather than for a more careful self-review.

#### One citation that is stable and one that is not

`symfony/console` is v8.1.6 here and v7.4.16 there, and `TreeNode.php:75` in both. #2333 cites theirs. The
line agreeing across two versions is luck rather than stability — **the `@var` annotation is the durable
citation and the line number is not**, which is why the filed issue quotes the annotation.

### Symptom B has a workaround, and the sentence justifying it was false

The peer session, checking for workarounds before filing, found `SourceFile::getTrivia()`. Reproduced here on
this repository's own subject, under the `SourceText` requirement the probes already request:

    TRIVIA DocBlockComment  start=344  /** @var Dog $tagged */
    ASSIGN                  start=376  $tagged = $h->pet

So the annotation is readable and associable by position. `NoJustPropertyAssignRule`'s exemption — *the
docblock says something more specific, so the assignment is deliberate* — **can be honoured today**: read the
trivia, match the span, parse the `@var`.

The draft said *"an exemption that cannot be read is an exemption that cannot be honoured"*. That was the one
false sentence in either document, and it justified half of one of them.

The residual is real and much smaller: raw text is not the analyzer's resolved type, so honouring it means
redoing alias and imported-type resolution. A different and weaker ask.

#### What was filed instead

**carthage-software/mago#2334**, symptom A alone. Symptom B appears in it under *Checked before filing*, named
with its workaround and explicitly not requested — volunteering the thing that weakens the ask, rather than
being handed it by a maintainer who finds `getTrivia()` in a minute and closes the whole report.

Symptom A got stronger on the way, from the same workaround hunt. Reading span-keyed types at the variable
nodes rather than the flags:

    $definite       int      $neverAssigned   mixed
    $maybe          int      $mixedDef        mixed   ($mixedDef = json_decode('1'), definitely assigned)

An undefined variable gets a span entry typed `mixed`; a definitely-assigned genuinely-`mixed` variable gets
a span entry typed `mixed`. Neither the entry's presence nor its type separates them, and no flag does
either. A second independent demonstration, and it closes the obvious *"just check whether it has a type"*
answer before it is asked.

#### The failure this is, which is not the one already recorded

Every figure in both drafts was verified. Twice, on two machines, with three instruments, on inputs the
author had not chosen. **No pass asked whether the gap had a workaround**, because verifying a claim and
testing whether the claim matters are different questions and only the first looks like verification.

The countermeasure recorded this morning — *have a second party run the table on a shape you did not choose*
— cannot reach it. Re-running the author's inputs never asks whether a different route exists. It is now a
guideline section of its own, because the practical form is a different question rather than a better check:
**is there another route to the same answer**, searched for by outcome rather than by concept. The trivia
store was not found by looking for docblocks; it was found by asking how else a rule could see one.

It also aims this file's positional rule one notch higher. *A value can be right and still be the answer to a
question nobody asked* — and, now measured, **the whole document can be built on such a value with every
figure in it correct**.

### Auditing for the leaked-Rust shape found a second one, and it emitted

The definedness fix came from a refusal that named a leaked Rust operand. The shape is mechanical to search
for — a handler returning a `support::` string with no PHP branch — so it was searched:

    7 sites in Translator.php return raw Rust
    5 guarded by an explicit target check
    1 reachable only through definednessTest(), which now refuses on php before it
    1 unguarded and reachable

The unguarded one is `$node->getLine()` / `$node->getStartLine()` interpolated into a message. It did not
refuse. It **emitted**:

    Issue::new(Support::viaTraitUsers($context, $node, sprintf('Method at line %s',
        support::line_text(context, node.span()))), $node->span, 'here'),

**That is a `.php` file containing Rust, and it parses.** `support::line_text(..)` reads as a static call on
an undefined class; `node.span()` reads as a concatenation of an undefined constant and an undefined
function. So nothing before execution catches it — which is the outcome this repository's own invariant rates
worse than a file that does not parse, because it loads and misbehaves.

Latent: no corpus rule interpolates a line number into a message, and `grep 'support::'` over the 159 emitted
php plugins and every reviewed snapshot returns nothing. Found by writing a rule that does.

#### Refused rather than implemented

The PHP target has nothing to render. A `Span` carries byte offsets, `SourceFile` exposes no line lookup, and
`Support::anchor()` positions a finding rather than producing a number. Implementing one would mean counting
newlines to an offset — a new capability with no consumer in the corpus — so the honest fix is the refusal,
which turns a silently broken emission into a named one. Both Rust targets still emit; they have the helper.

#### What the audit says about the two fixes together

Both defects were invisible to every check this repository runs. The snapshots compare bytes that were
already correct, the census records a refusal that already fires, and the fires gate runs plugins that
already emit. **A defect that only appears for input no rule in the corpus supplies is outside all three**,
and the way it was found was writing the input — twice, once for each shape.

That generalises the sizing instrument from earlier today. Stubbing measured what a capability is *worth*;
this measured what a handler *does when reached*, which needs a rule that reaches it. The corpus cannot
supply one by definition, since a rule that reached it would already be broken.

#### Verification

`grep -n 'return "support::' src/Translator.php`, seven hits, each read for a target guard in the twelve
lines above it. The emission is from a two-guard fixture written for the purpose, transpiled at each target.
Emit-all across all three targets is byte-identical before and after both fixes.

---

## A day spent ranking, and the ranking was the artefact

Four findings from one session that ended with a clean tree on purpose. Together they say the same thing from
four sides: **the instrument that says which rule is closest to emitting does not say that**.

### The census `needs:` count is not a proxy for what a rule costs

`tests/Fixtures/expected/census.md` lists, per refused rule, the constructs it would need. Counting those and
sorting gives an apparently free ranking — 26 rules with exactly one recorded need, 12 with two — and the
natural reading is that a one-need rule is one capability from emitting.

It is not. **Ten of ten rules read this session had a true bill larger than the recorded one**, and usually
several times larger:

| rule | recorded needs | what the source actually needs |
|:--|--:|:--|
| `TaggedIteratorOverRepeatedServiceCallRule` | 1 | a closure detector, `isFirstClassCallable()`, a static finder that walks, per-statement reporting |
| `ClassAttributeRequiresPhpVersionRule` | 1 | the whole body delegates to a helper that builds findings |
| `IllegalConstructorStaticCallRule` | 1 | `getTraitAliases`, a nested non-guard `if`, `array_map`/`in_array` over parent class names, `resolveName` |
| `FileNameMatchesExtensionRule` | 2 | a `NodeFinder` with a closure writing a captured variable by reference |
| `AssertSameWithCountRule` | 3 | `TrinaryLogic` algebra in a helper (`->or()`, `->negate()`), `ObjectType` construction |
| `NoInstanceOfStaticReflectionRule` | 2 | a static type analyser, `ConstantStringType` construction, two hook kinds |

The cause is structural rather than a bug: the needs pass steps over a refusal to keep looking, and every
construct *inside* what it stepped over is never recorded. So the recorded set is a subset of the real one,
and the size of the gap varies with how early the first refusal sits. A rule that refuses on its first line
records one need and may want twenty.

**A subset with a variable-size gap cannot order anything.** The count is safe to read as "at least this
many" and unsafe to read as "fewer than that rule".

### Which also means a first-refusal line can name a symptom

The same stepping-over inverts causes. `DisallowedLooseComparisonRule` refuses on
`message expression outside the vocabulary: Expr_Ternary` — a message shape, which reads as a small
vocabulary gap. Resolving the ternary's *condition* before refusing says something else:

    $includeOperandTypesInErrorMessage is wired to the container parameter %featureToggles.bleedingEdge%,
    which the package's own neon does not declare

Measured, by a throwaway edit that resolves the condition and then refuses as before, run on the one rule and
reverted. The ternary was never the wall: the branch cannot be taken either way, because the value that
picks it is not carryable. Supporting ternary messages would have moved the rule zero.

So a first-refusal line answers "what stopped it here", never "what would let it through".

### The `BinaryOp` fold: built, measured at zero, reverted

Built to completion, because reading could not settle it. A `HOOKS` row for the abstract
`PhpParser\Node\Expr\BinaryOp` — the same multi-kind shape `Expr` and `CallLike` already take, and needing no
`HOOK_KINDS` entry because Mago has exactly one `Binary` kind for every operator — plus `is_loose_equal` and
`is_loose_not_equal` node predicates over the existing `Operators::binaryOperatorIs()`, which turn
php-parser's class-per-operator into Mago's operator-child test.

It works: the rule advanced two refusals, from `no hook mapping for node type PhpParser\Node\Expr\BinaryOp`
to the parameter above. Emit-all across all three targets was **byte-identical** to the baseline and the
counts did not move — 158 php, 34 analyzer, 25 linter, before and after, the only difference being the
`--out` path `mago.toml.snippet` embeds. That configuration is the six rule packages installed with a `src`
directory (`symplify/phpstan-rules`, `hihaho/phpstan-rules`, `tomasvotruba/type-coverage`,
`tomasvotruba/cognitive-complexity`, `phpstan/phpstan-strict-rules`, `phpstan/phpstan-phpunit`) plus
`tests/Fixtures/Rules`, which is a smaller corpus than the census's own — `phpstan/phpstan-doctrine` is not
installed here, so its rules are in neither count.

Reverted, and the reason is not the zero. Over that same corpus, `grep 'return BinaryOp::class'` returns
**exactly one** rule — fourteen other files name `BinaryOp` in a body, none hooks on it — and that rule is
blocked correct-forever on a parameter no member of this capability set can supply. A capability whose only consumer cannot complete is unexercised vocabulary, which is what the
withdrawn arithmetic port was reverted for: a table that describes what the tool *could* do stops describing
what it does.

### And the arithmetic family stays withdrawn, on the reason that survives

`#2311` — mago recording a compound assignment's coerced right operand — is fixed in 1.47.6, which is
installed, so the first of the two withdrawal reasons is stale. It does not reopen the family.

Two things stop it. The real-code measurement stands, with its scope stated: across Shopware's 9199 files and
hihaho's 2926 the division rule made **zero agreements and four findings PHPStan declines** — and because
that run predates the fix, it covered the plain divisions mago could then read, not the compound half. Fixing
the compound half adds cases to a rule whose readable half already agreed with PHPStan nowhere.

And the census puts the family nowhere near emitting regardless. Each of the six refuses first on
`a chain of 1 elseif and an else`, with four more recorded needs behind it — and by the finding above, at
least four.

### What the four have in common

Every one is the same error caught at a different distance: a cheap reading substituted for a measurement.
The needs count for a bill, the first refusal for a cause, a plausible unlock for a measured one, a stale
reason for a live one. Three were caught by running something — a stub, a probe, an emit-all diff. The fourth
was caught by reading a date.

---

## The first rule whose recorded needs were its real bill

`NoEntityOutsideEntityNamespaceRule` emits. It is the tenth rule read this session and the first whose census
entry matched what the source actually wants — which is why it was worth building, and the ranking finding
above is why the other nine were not.

**It moves the emitted count and not the package's headline ratio**, and the two are different numbers.
`symplify/phpstan-rules` reads `60 of 89 portable rules the package registers` before and after: this rule is
one of the eight the package registers nowhere, so it was never in that denominator. The figure that moves is
the number of files emitted over the walked corpus, php 158 to 159 for it alone. Quoting the ratio here would
have been the carried-figure failure this document names elsewhere, and it was caught by diffing the census
header across the commit rather than by remembering.

### What it needed, and what the census knew

Three members. The census recorded two.

| member | recorded | what it is |
|:--|:--|:--|
| several names in one attribute walk | yes | the fold existed for one guard; the rule writes two |
| `->getParts()` on a qualified name | yes | the segments a namespace test asks membership of |
| `in_array()` over a computed list | **no** | the reverse of the direction already carried |

The third was invisible for the reason the ranking finding gives: the needs pass stopped at `->getParts()`,
so nothing downstream of it was recorded. A bill read off the census would have been two thirds of the work.

### Each member, and the control that measures it

**The walk.** `hasEntityAttribute()` writes the two-level `attrGroups` → `attrs` walk, and the transpiler
already folds that to `Support::hasAttributeNamed()` rather than mapping the levels. It capped the inner body
at one statement; this rule tests `Entity` and `Embeddable`, so the fold read one guard per statement and
joins them with `||`.

Measured rather than argued: reading the first guard alone **still emits**, and the emitted plugin is
silently missing `Embeddable`. `BadEntityNamespaces.php` holds an `#[Embeddable]` class for exactly that,
so the shape a stricter reading would drop is one the gate runs both engines over.

**`getParts()`.** php-parser includes the declaration's own short name among the parts, and the port does
too. `GoodShortNameControl.php` is the control on that single axis: a class *named* `Entity` sitting in
`Examples\Model`, where no namespace segment matches. Both engines stay silent, so a port that dropped the
short name would report there and nowhere else.

**`in_array()` over the list.** The existing computed-list branch folds case, because metadata lowercases the
names it holds. These segments came off the CST with the spelling their author wrote, so they compare
exactly — a separate branch rather than a widened one, since folding case there would answer wider than the
`true` the rule was given.

### A first attempt that was wrong, and the mutation that said so

The multi-guard reading first required every guard to `return true`, on the reasoning that a guard answering
`false` inverts the question and cannot join a disjunction. That reasoning is about a fold that does not
exist: the caller wraps the folded condition in the literal the rule's own tree returned. A single guard
answering `false` had always emitted correctly, as `hasAttributeNamed(..) ? false : true`, and the new
requirement refused it.

Found by mutating the guard away and reading what came out, not by reading the code — the emission was
correct, which is the opposite of what the added check predicted. `InvertedAttributeWalkRule` is that shape,
kept as a snapshot: no corpus rule writes it, so nothing else would notice a fold that assumed `true`.

A second added check went the same way. Guards that answer *differently* genuinely cannot fold — but
removing the check that caught them changed nothing, because the inliner already refuses with
`a foreach in an inlined helper returning both booleans`, which names the shape better. So there is no check,
and `DisagreeingAttributeWalkRule` records which guard is the load-bearing one.

**Both were defences against a failure that could not occur, and reading could not tell.** The one that
mattered — the multi-guard reading itself — is the one whose mutation *did* change the output, and silently.

### Verification

Emit-all across all three targets before and after: two new files, `NoEntityOutsideEntityNamespaceRule` and
the `InvertedAttributeWalkRule` fixture, and **no other emitted byte moved** — php 158 to 160, analyzer 34
and linter 25 unchanged, with only the `--out` path in `mago.toml.snippet` differing. The corpus rule alone
is php 158 to 159. Suite 307 of 307, PHPStan 0, pint clean; the two complexity baselines moved with the new
branches and no new entry appeared. The fires gate ran last, on this tree: 686 of 686, which is real `mago`
against real PHPStan over the example pair above — so the rule is measured to *run*, not only to emit.

---

## A rule two capabilities away, and a defect underneath the second one

`NoGetRepositoryOutsideServiceRule` emits — symplify 61 of 89. It is the second rule in a
row whose census entry was its whole bill, which is worth saying next to the ranking finding above: the
recorded needs are a *lower* bound, not a wrong one, and a rule that refuses late records most of what it
wants. Both of these refused late.

### The two capabilities

**A guard in an inlined helper may bind before it answers.** `isDynamicArg()` writes

    if ($firstArg->value instanceof ClassConstFetch) {
        $classConstFetch = $firstArg->value;
        return ! $classConstFetch->class instanceof Name;
    }

and the binding is there for PHPStan's own narrowing rather than for the reader: it names a value already in
scope. So it is bound, used, and dropped, scoped to the guard. The other half is the answer — computed, not
a literal — and the guard list was already carrying rendered expressions in that position, so nothing had to
change to hold it. What did have to change is the check: the old refusal demanded a boolean *literal*, which
was standing in for "a boolean". The replacement asks the question directly, from the expression's shape.

**An early report written with a temporary is normalised.** `$e = RuleErrorBuilder::…; return [$e];` inside a
guard is the one-statement form every reading below already handles, so it is rewritten to that rather than
each of them learning a second spelling. Narrow on purpose: the name has to match, the array has to hold the
one item, and the value has to be a built error — a temporary the body uses for anything else is a step, and
dropping it would emit a rule that skipped work.

### And then the defect, which was already there

With both built the rule emitted, and the emitted plugin was **missing its trailing report**. The rule reports
early for a call outside any class, and reports again at the end for a call inside one that is not a
repository — which is the case it mostly exists for. The plugin had the first and not the second.

The cause is one flag doing two jobs. `reportedInline` records that a report was written where it was found,
and the emitter reads it as *there is nothing left to say at the end*. Those coincide exactly while a rule has
one report. A rule with an early one and a trailing one has both, and the emitter believed the first.

**Present at HEAD, and nothing to do with the new capabilities.** Measured by writing the shape as a fixture
and transpiling it with the working tree stashed: one report before, one report after, on code that asks for
two. No rule in the corpus writes it, which is why the snapshots, the census and the fires gate were all
silent — the fourth defect of this family found by supplying input the corpus cannot.

The fix gives the emitter a second question to ask: whether the rule's own body *ends* in a report. Two
spellings reach it, the builder in the return and the builder in a temporary above it, and both now say so.

### The fix's first version was wrong, and the diff said so

Marking the tail wherever a builder was taken outside a loop gave `NoDynamicNameRule` an **unconditional
report on every expression it saw**. That rule ends `return [];` and wraps two branch checks that each report
inside their own extracted method, so its builders are not its tail. `EveryExpressionRule` gained the same.

Neither was caught by reading — the change looked local and correct. The emit-all diff named both, which is
the whole reason the byte-for-byte comparison is the pass condition rather than the test suite. The condition
that fixes it is one line, and it is a condition about *where* translation is, not about what it found.

### Verification

Emit-all across all three targets, on the committed tree against the last pushed one: **two** new files and
no other emitted byte moved. php 160 to 162 — one of those is `NoGetRepositoryOutsideServiceRule` and the
other is the `EarlyThenTailReportRule` fixture, and the rule alone was measured at 161 with the fixture
absent. Analyzer 34 and linter 25 *emitting* are unchanged; each refuses one more, which is the fixture being
counted. Only the `--out` path in `mago.toml.snippet` differs.

The counts in this paragraph were re-derived from a run on the committed tree rather than carried from the
previous entry's "+1 each". They are not the same numbers: the fixture emits on the php target too, so the
file count moves by two where the corpus moves by one, and a sentence saying "159 to 160" would have been
right about neither. The census moves three ways and all three are the change: symplify 60 to 61
emitting and 28 to 27 refusing, this rule from REFUSE to EMIT, and `AlreadyRegisteredAutodiscoveryServiceRule`
losing one recorded need, because the two-statement early report now translates for it too.

Suite 311 of 311, PHPStan 0, pint clean. `translateStatement` falls from 76 to 63 with the extraction, which
is the first of these entries to move down. The fix is mutation-checked against
`EarlyThenTailReportRule`: with the second question removed the fixture emits one report, with it two.

The fires gate ran last, on this tree: 690 of 690, real `mago` against real PHPStan over the example pair.
Its good file carries the two `isDynamicArg()` shapes — a variable argument and `$subject::class`, whose
class is an expression rather than a written name — so the branch whose binding this change drops is
measured on both engines rather than argued from the emitted text.

---

## Two walls in a row, and the second one emitted

No rule was added this round. The output is the measurement, and it says something about where the corpus
now is rather than about the two rules that were tried.

### Nine rules are walled by a value that does not exist, and none of them declares a default

The largest remaining first-refusal cluster after the arithmetic family is a constructor parameter the
package's own neon wires nowhere. Nine rules refuse there, and the obvious repair is to carry the parameter's
declared default instead — PHP uses it, so the plugin would too.

Derived rather than assumed: of the nine, **none** declares a default on any constructor parameter. Every one
takes between one and four, all of them required. So the repair serves zero of the rules that motivated it.

    ForbiddenFuncCallRule 0/3   NoUnsafeRequestDataRule 0/3    PositionalFlagArgumentMethodCallRule 0/1
    ForbiddenNewArgumentRule 0/1  NoUnsafeRequestFacadeRule 0/3  PositionalFlagArgumentStaticCallRule 0/2
    UnvalidatedFormRequestFieldRule 0/4  NoUnsafeRequestHelperRule 0/3  VariablePropertyFetchRule 0/2

`NoTestMocksRule` is the one rule with a defaulted parameter, and it is not in that cluster — it refuses
earlier, on `new ObjectType(...)`.

### So the second attempt was that rule, and the chain closed onto a silent plugin

Four folds, each small and each apparently sound: carry `new ObjectType(<name>)` as the name it was given,
read `->getClassName()` straight back out of it, read `instanceof ObjectType` on one as "there is a name",
and answer `isInstanceOf($runtimeName)` through `Support::classDescendsFrom()`, whose runtime signature
already takes a plain string for the parent. With a scratch route from an unwired-but-defaulted parameter
into the configured machinery, the rule **emitted**.

What it emitted does not work:

    foreach (Support::constantStringsOf(Support::expressionType($context, $arg_value)) as $constant_string_type) {
    }

    return;
    if (!($constant_string_type !== null)) {

The rule writes `foreach (...) { return new ObjectType($c->getValue()); }` — "the first one, then stop". The
`New_` was consumed as a descriptor, which left the loop body empty and the loop's exit as an unconditional
`return`. Every guard after it, and the report, are dead code. The plugin parses, loads, runs, and is
**silent on every file**.

**This is the failure the "refuse rather than approximate" invariant exists for, reached by relaxing a
refusal.** Nothing downstream would have caught it: it is valid PHP, it declares its targets, its helpers all
exist, and a fires gate comparing it against PHPStan would record agreement on every file where PHPStan also
says nothing — which, for a rule about mocking, is almost all of them.

Reverted whole. The four folds are not merely worth zero; the first of them is unsafe in the position the
rule uses it, and keeping it against a fixture written to suit would have hidden that.

### What the two rounds together say

The `BinaryOp` fold above was reverted because its only consumer could never complete. This one is the other
shape: the consumer *can* complete, the chain does close, and the thing that comes out is wrong. Both were
settled by running rather than reading, and in both the reading looked fine — the `New_` fold in particular
is correct everywhere except inside a loop whose body is nothing but the return, which is the one place this
rule uses it.

**A refusal that has stood for a while is evidence about the shapes behind it**, and the two capabilities
tried here were each blocked by something the refusal did not name. Where the next rule comes from is
therefore not the census's shortest entry; it is a rule whose whole body can be read and whose every step is
already in the vocabulary — which is what both rules that emitted today had in common, and what neither of
these did.

---

## A node hook cannot look outward, measured

`ClosureUsesThisRule` reached an emission that ran, and one of its four cells was wrong. Tracking that down
produced a fact about the SDK that no earlier probe had asked for, and it is the reason the rule is reverted
rather than shipped.

### The rule, and why it looked like the right one to pick

Fifty-two lines, whole body readable, and the two steps that looked like soundness questions were both
already answered somewhere in this repository:

- `! $varType instanceof ThisType` — `$self = $this` and `$other = new Holder()` both render as `Holder`, so
  reading `(string) $type` cannot separate them. Mago marks it on the atomic, and the marker survives both the
  assignment *and* the capture: probed on a `use` clause holding each, `isThis=true, static=true` against
  `isThis=false, static=false`.
- `$scope->isInClosureBind()` — traced in the installed `phpstan.phar` rather than named from the method.
  `src/Analyser/ExprHandler/StaticCallHandler.php` enters that scope for a static call whose declaring class
  is `Closure` and whose lowercased name is `bind`. So `->bindTo()` is a method call and does **not** set it,
  which is narrower than the name suggests and would have been got wrong by reasoning from the name.

Everything else was navigation: the `use` clause is a `ClosureUseClause` holding one
`ClosureUseClauseVariable` per capture, and the capture node is what carries the type.

### Two defects the running caught, and neither was visible in the emitted text

**A runtime fatal.** `new Part($node, $text)` — the constructor takes four arguments in a different order.
The emitted plugin was well-formed PHP and every static check passed; mago rejected the worker on the first
file.

**An empty name in the message.** `$closureUse->var->name` resolved through the generic `expr->name` reading,
which is php-parser's `ConstFetch->name` — so the finding read *"assigned to variable $"*. Correct where that
reading was written, wrong here, and only visible in the text of a real finding.

### And then the cell that could not be fixed

With both repaired the port agreed with PHPStan on three of four shapes and reported a fourth PHPStan is
silent on: the closure inside `Closure::bind(...)`. Ground truth taken from real PHPStan with the one rule
registered, not assumed.

`isInClosureBind()` needs to know what encloses the closure. Instrumented, `SourceFile::getAncestors()`
returns **empty** inside a node hook. `getNodes()` returns 60 nodes for that file and every one of them is
inside a targeted closure — four `Closure`, four `ClosureUseClause`, eight `DirectVariable` and so on, with
no `StaticMethodCall`, no `Method`, no `Class`.

**So `TargetSubtree` is exactly what it says: a node hook receives its targets' subtrees and nothing else.**
No ancestors, no siblings, no enclosing call, and no requirement in `FileAnalysisRequirement` supplies them —
`ExpressionTypes` is for after-file hooks, and the other four are types and text. The question is not hard to
answer, it is unanswerable from where the plugin stands.

That is a guard that *exits*, so dropping it makes the port report where the rule is silent. Reverted.

### What this measurement is worth beyond one rule

It is the first time this repository has asked what a node hook can see *outward*, and the answer bounds a
whole class of rules rather than this one. Any rule whose guard is about context — what call encloses this
expression, what statement precedes it, whether this node is an argument of something — is outside the PHP
target's reach for the same reason, however simple the guard reads.

It also sharpens the earlier probe rule. `getTrivia()` and `getResolvedName()` were both found by asking what
a plugin receives; neither says anything about *scope*. "The SDK exposes the tree" is true of the subtree and
false of everything above it, and the two look identical until something walks up.

---

## The first package that transpiles whole, and the number that would have broken it

`CallWithDeprecatedIniOptionRule` emits, and with it `phpstan/phpstan-deprecation-rules` reads **2 of 2**:
the first package in the corpus that needs no PHPStan at all. The rule was picked by the criterion the three
reverts before it produced — a whole readable body, every step already answerable, and no guard that needs to
look outward from the node.

### The version comparison, which this document had already warned about

The rule compares the analysed PHP version against a table of thresholds. Both engines have a version id and
they are not the same number:

| | 8.3.0 | encoding |
|:--|--:|:--|
| Mago's `PHPVersion::$id` | 525056 | `(major << 16) \| (minor << 8) \| patch` |
| PHPStan's `PhpVersion` | 80300 | `major * 10000 + minor * 100 + patch` |

A port reading mago's `id` raw finds **every** threshold in the table larger than it, and reports every
deprecated option on every project. `Runtime\Versions` builds PHPStan's encoding from `major()`, `minor()`
and `patch()` instead, so the mapping is exact rather than arithmetic a reader has to check.

This is the case an earlier entry here predicted from `fromParts()` without a rule to test it on. It held.

**And it is measured in both directions.** On one file with real PHPStan and the emitted plugin: at PHP 8.3
both report three findings, same lines and same messages; at PHP 8.4 both report four, the fourth being
`session.sid_length` whose threshold is 80400. Only the analysed version moves between the two runs. A port
comparing raw ids would have reported four at both, and one that never read the version would have reported
three at both — the pair separates all three behaviours.

The example pair cannot carry that control, and says so in its own docblock: every threshold in the table is
at or below the PHP this suite runs, so no option in it can be silent for being too new. The version axis is
measured here instead of pretended to in a fixture.

### Three folds behind it, and one defect in the fourth

- **A `try` that binds through its catch.** `try { $f = <lookup>; } catch (NotFound) { return []; }` becomes
  the binding plus a null guard. The catch is *not* dropped — the plugin's equivalent lookup returns null
  where PHPStan throws, and dropping it would widen the rule onto every name the codebase does not know.
- **`$this->reflectionProvider->getFunction()` answers as the name.** The service has no injectable
  equivalent, but the only thing the rules reading it use is `->getName()`, and `Support::functionName()`
  already existed with a docblock naming that exact call. Two other rules moved past it as a side effect.
- **A constant map is carried onto the plugin.** `array_key_exists()` and the value read are the original's,
  so a threshold table stays the rule's data instead of becoming this transpiler's.

The fourth thing was a defect. Carrying a constant was gated on the plugin having *configuration*, and this
rule has none — so it emitted a plugin naming `self::DEPRECATED_OPTIONS` without declaring it. Valid PHP that
loads and fatals on the first file it matches. **Found by looking for the declaration rather than by the
emission failing**, which is the only way to find it: every check this repository runs was green.

The repair had to stay under `Emitter`'s complexity limit rather than open a new baseline entry, which two
ternaries rewritten as string accumulation paid for — same output, verified by emit-all.

### Verification

Emit-all across all three targets, on the committed tree against the previous one: **one** new file and no
other emitted byte. php 163 to 164 over the walked corpus, analyzer 34 and linter 25 unchanged. That corpus
now includes `phpstan/phpstan-deprecation-rules`, which an earlier run of this instrument had omitted — the
counts here are not comparable with the ones in the entries above, and the configuration is the reason.

The census moves three ways and all three are the change: the package to 2 of 2, this rule to EMIT, and
`ArrayFilterStrictRule` and `StrictFunctionCallsRule` past `getFunction()` onto their next obstacle.

Suite 311 of 311, PHPStan 0, pint clean. The fires gate ran last, on this tree: 694 of 694, real `mago`
against real PHPStan over the example pair — so the rule is measured to run, and the four options its bad
file names are agreed on line and message by both engines.

### The closeout review found the fold accepting more than it can carry

Three things came out of reviewing the round, and the third is the one worth writing down.

Two were mine, from reading my own diff. The extracted `carriedConstants()` went in between
`emitConstructor()` and its docblock — the third time that displacement has happened in this repository, and
invisible in a diff read hunk by hunk. And `constantMapOperand()` recorded the constant *before* its caller
had committed to the read, while `numericOperands()` calls it speculatively inside a `catch (Refusal)`, so a
comparison refusing on its other operand would have left a `private const` declared on the plugin with
nothing reading it.

The third was Codex's, and it is this document's own invariant pointed back at me. **`bindsThroughACatch()`
accepted any single local assignment inside the `try` and any catch type**, then replaced the catch with a
null guard. That rewrite is sound only where the plugin's reading answers null for exactly the failure the
catch was there to take — a fact about the *reading*, which the shape of the `try` says nothing about. For
any other assignment the guard is wrong in one of two silent directions: it fires where the original
continued, because the value is legitimately null, or it never fires where the original caught.

The accepted kinds are now listed rather than inferred, with one entry, because
`Support::functionName()` answering null for an unknown name is measured and nothing else is.

**The fold worked on its one consumer, and that is exactly why the over-acceptance was invisible.** Emit-all
was byte-identical before and after the restriction — the same measurement that proves a refactor safe proves
nothing about a rule that does not exist yet, and the corpus contains no second `try` of this shape to catch
it. What found it was a reader who did not know which rule the fold was written for.

A second round found two more of the same species, and both were right:

- **The catch type was never checked.** Any catch returning `[]` was consumed, so a rule catching a
  `LogicException` around this lookup would have had that catch replaced by a null guard while
  `FunctionNotFoundException` still escaped in the original. The accepted exceptions are now listed per kind,
  and a catch of anything else refuses by name.
- **A qualified call got PHP's global fallback, which PHP does not give it.** `Support::functionName()` tries
  the written name and then its last segment, which is right for an *unqualified* call — `request()` inside
  `namespace Acme` really does fall back to the global one. It is wrong for `Other\ini_get()`, which PHPStan
  resolves to nothing and the port would have answered `ini_get` for, reporting a deprecation the original
  does not. `calledFunctionName()` declines a name carrying a separator and leaves the fallback to the case
  that earns it. A *leading* backslash is not qualification — `\ini_get()` is the global function written
  explicitly, and the bad example now holds one so that cell is measured: both engines report five findings
  on that file, four without it.

A third round found the sharpest one, and it was about a helper that predates this work.
`Support::functionName()` tries the written name and then its last segment, which reads like PHP's rule and
is not. For `ini_get()` inside `namespace App` it asks for the *global* function first, so a file declaring
`App\ini_get()` gets the global answer — and this rule, which compares that answer against five global
names, reports a deprecation PHPStan does not.

Probed rather than reasoned about, and the probe is the reason the fix is not what it first looked like:

    namespace App;                    resolved name
    ini_get('..')   with App\ini_get declared    App\ini_get
    ini_get('..')   with it *not* declared       App\ini_get
    \ini_get('..')                               ini_get

**Mago resolves the unqualified call to the namespaced candidate whether or not it exists**, so the resolved
name is not PHP's answer either — the global fallback is still the caller's to apply, and only for a call
written unqualified. `calledFunctionName()` now takes the node, tries the resolved candidate, and falls back
to the bare name only when the written spelling has no separator. Measured on the discriminating pair: with
`App\ini_get()` declared both engines are silent, and on `\ini_get()` in the same file both report.

A fourth round found the constant carry accepting more than it can copy: any array with string keys became
a readable map, but only the *keys* were checked. `['x' => self::LIMIT]` would have been copied onto the
plugin naming a constant the plugin does not declare, and an imported class constant would resolve in the
wrong namespace there. Only literal values are recorded as a map now; membership needs the keys alone and is
unaffected.

Five rounds, seven findings, and **not one of them was reachable by any check this repository runs**. Emit-all
stayed byte-identical through the first two and moved only inside this one rule after that; the suite, the
census and the fires gate were green throughout. Every one was a rule that does not exist yet — or a file
nobody had written — meeting a fold built for the case in front of it.

---

## A pattern the rule owns, and a fixer that quietly unwrote the fixture

`NoMissnamedDocTagRule` emits — symplify **62 of 89**, php 164 to 165. It walks a class-like's methods,
properties and constants and reports a different message for each, naming the tag it found.

### Keeping a match rather than answering with one

The boolean half of `Strings::match()` was already here, with the semantics read out of Nette rather than
assumed. What was missing is a rule that *keeps* the result and asks a second question of it — `$matches[1]`.

The binding carries the pattern and the subject instead of a value, and each read re-asks. That is the design
decision worth recording: **there is no match array in the emitted plugin**, so no later read can depend on
one, and any navigation of a `regex-match` other than the null test and the group read meets the ordinary "no
mapping" refusal rather than a guess. After a round that produced seven over-acceptance findings, defaulting
an unknown read to a refusal is worth more than a shorter emission.

The restrictions are the existing half's, for reasons traced in `Strings::match()` itself: exactly two
arguments, because `$utf8` appends the `u` modifier and `$captureOffset` changes the array's shape; and a
literal pattern, because it is copied into the plugin verbatim. One divergence stated rather than hidden —
Nette routes `preg_match` through a wrapper that turns a PCRE runtime error into a thrown exception, where
`preg_match` returns false and this reads as "no match". The port is silent where the original raises.

`getDocComment()` answers for a constant declaration now, and that claim is measured rather than argued: on
one file both engines report the constant, the property and the method, on lines 8, 14 and 20.

### The fixture stopped testing what it was written to test, and stayed green

`pint` rewrote both example files. In the good one, `no_superfluous_phpdoc_tags` **deleted** the property's
`@var` and the method's `@param` as redundant against their native types. Both cases stay silent under both
engines afterwards — for the wrong reason: no docblock at all, rather than the right tag. The suite passes
either way, because a good example is only ever asserted to produce nothing.

This is the second time a formatter has done this here. The first was `array_syntax` rewriting
`array($this, 'handle')` into `[..]` in an example pair, which is recorded above. Both files are in pint's
`notPath` now, each with the reason in its own docblock so the next reader does not remove it.

**A good example is the fixture kind with no failure mode.** A bad example that stops firing fails the gate;
a good example that stops being the shape it was written as passes, and every tool in the chain is content.
The formatter is only the mechanism — anything that edits a fixture for reasons of its own can do this, and
nothing in this repository compares a fixture against what it was for.

### Verification

Emit-all across all three targets, against the previous tree: one new file and no other emitted byte, php
164 to 165, analyzer 34 and linter 25 unchanged. The census moves three ways and all three are the change:
symplify to 62 emitting and 26 refusing, this rule to EMIT, and `PhpUpgradeDowngradeRegisteredInSetRule` past
`Strings::match()` onto its next obstacle.

Suite 311 of 311, PHPStan 0, pint clean. The fires gate ran last, on this tree: 698 of 698, real `mago`
against real PHPStan over the example pair — so the three member kinds are agreed on line and message by
both engines rather than only in the one-file run above.

---

## Two argument reads that shared one name, and a rule that compared a value with itself

`NoSetClassServiceDuplicationRule` emits — symplify **63 of 89**, php 165 to 166. It is a linear guard chain
with no services, closures or recursion, which is what made it the right pick. The defect it surfaced is
worth more than the rule.

### The defect

An argument binding was named from the argument's **index** — `arg_value` for position 0 — which is a fact
about the argument and not about where it was read. This rule calls one helper on two receivers:

    $parentSoleArgContents = $this->resolveSoleArgContents($parentMethodCall);
    $currentSoleArgContents = $this->resolveSoleArgContents($node);

Both inlinings bound `$arg_value`, the second shadowing the first, so both outer locals referenced the same
name. The rule then reports when the two **match**, and the emitted plugin compared `$arg_value` with itself:

    if (!(Support::textOf($arg_value) === Support::textOf($arg_value))) {

Constantly true. The guard that exists to require a match never fired, so the plugin would have reported
**every** `$services->set(A)->class(B)` pair, matching or not.

Named from the index rather than from the read is the whole of it, and it took a rule calling one helper
twice to make it visible. `unusedBindName()` now takes the next free variant, which is safe for a reason the
shape gives rather than by inspection: the binding's name is recorded on the local's descriptor, so later
reads render from the descriptor, and the refinement key is the binding too so two cannot alias. Measured —
no existing emitted plugin renames a bind, so the fix is byte-neutral everywhere but here.

Every `declare` now goes through one method that refuses a name this rule's emission already holds. It fires
on nothing today, because the argument path is a different statement kind. It is kept anyway: it is the guard
that would have caught this one, and the next collision will not be an argument.

**Two false starts on the way, both from reading a property that does not exist.** The first guard checked
`$statement->fields[...]`; `Stm` calls it `$args`. So the loop looked at nothing, found nothing, and the
emit-all diff said "nothing broke" — which was true and useless. A guard that cannot fire looks exactly like
a guard with nothing to catch, and the only thing that separated them was that the *rule* kept emitting.

### Three folds behind the rule, each with its divergence stated

- **php-parser's printer answers as the written source text.** They are not the same string: the printer
  normalises, so `set( Foo::class )` and `class(Foo::class)` print alike where their source differs. A pair
  written differently but printing the same is therefore missed — under-reporting — and a pair written the
  same, which is the duplication the rule exists for, compares equal in both engines.
- **`Strings::after($x, $n, -1)` gets its own helper** rather than the name-segment one it resembles. That
  one hands back the *whole* string where there is no separator and Nette hands back null; read out of
  `Strings::after()`, whose `pos()` returns null and short-circuits before the `substr`. Under this rule's
  own `str_contains` guard the two agree, which is exactly why reusing it would have looked correct.
- **A helper parameter the call site bound to a literal reads as one.** `isMethodName($node->name, 'class')`
  compares against `$name`, whose value is known at transpile time — the same table the raw reader already
  consults. Only that table: a variable holding anything the plugin computes still refuses.

### Verification

Emit-all across all three targets: one new file and no other emitted byte, php 165 to 166, analyzer 34 and
linter 25 unchanged. The census moves four ways and all four are the change: symplify to 63 emitting and 25
refusing, this rule to EMIT, one rule past `prettyPrintExpr()` onto `Expr_New`, and another losing its
`Strings::after()` need.

Both engines on a pair that discriminates: `set(X)->class(X)` reported by both, `set(A)->class(B)` silent in
both. The second is precisely what the shared local would have mis-reported, and it is in the good example so
the gate carries it. Suite 311 of 311, PHPStan 0, pint clean, and the fires gate ran last on this tree:
702 of 702, real `mago` against real PHPStan.

---

## "The first package that transpiles whole" was a sentence, not a measurement

`phpstan/phpstan-deprecation-rules` reads **2 of 2 portable rules the package registers emit**, and that is
exactly true: the package ships two `Rule` classes and both emit. What I wrote next does not follow from it —
the README bullet said such a package "needs no PHPStan", naming this one.

It does need it. The same `rules.neon` registers five more services:

    RestrictedDeprecatedClassConstantUsageExtension    phpstan.restrictedClassConstantUsageExtension
    RestrictedDeprecatedFunctionUsageExtension         phpstan.restrictedFunctionUsageExtension
    RestrictedDeprecatedMethodUsageExtension           phpstan.restrictedMethodUsageExtension
    RestrictedDeprecatedPropertyUsageExtension         phpstan.restrictedPropertyUsageExtension
    RestrictedDeprecatedClassNameUsageExtension        phpstan.restrictedClassNameUsageExtension

Those are where "Call to deprecated method X" comes from — they answer PHPStan core's restricted-usage
mechanism rather than reporting themselves. **They are not `Rule`s, so the census neither counts them nor
should**, and nothing here ports them. Dropping the package loses all five.

This is the failure pattern this document already names, in its purest form yet: *every number right, and the
sentence still wrong.* The count is correct, the denominator is correct, the census's own wording —
"portable rules the package registers" — is correct and carries its scope. The overreach is entirely in the
word "package", which I substituted for "rules" while writing a bullet about something else.

**And it was caught by a reader who did nothing but ask whether the happy number was true.** Not by
re-deriving the figure, which is right; not by a control pair, since there is nothing to vary; not by any
check in this repository, none of which looks at prose. The correction is to say what the unit is: rules are
the unit, and a package is not. The README says that now.

Worth noting what the near-miss was. The bullet had stood for two commits, and the next thing that would have
touched it is a release note or an upstream issue quoting it — which is exactly the boundary this document
records as the one where a claim leaves the repository and stops being checked at all.

---

## The node hook's horizon, confirmed from a second direction

Two rules were checked this round and both are walled by the same thing, which is worth stating once as a
property of the target rather than three times as a discovery about three rules.

`NoGetRepositoryOnServiceRepositoryEntityRule` resolves an entity class, then reads **that class's own file**
from disk and regex-scans it for `repositoryClass=`. `internal/probe-declaring-file-body.php` already
measured the answer: another file's CST is reachable from an *after-analysis* hook, through
`AfterAnalysisContext->analysis->files`, and not from a node hook — `FileAnalysis::getSourceFile()` takes no
argument and answers about the one file the hook was given.

That is the same boundary the closure-bind guard hit from the other side, where `getAncestors()` came back
empty and `getNodes()` returned only the targeted subtrees. **A node hook sees its targets' subtrees in one
file: nothing above them, nothing beside them, and nothing in another file.** Both measurements are in this
document; neither was taken with the other in mind, which is why the agreement is worth recording.

So the earlier sizing instrument's "access paths are worth +1 rule" was counting this rule, and it cannot
complete on the PHP target at all. That figure should be read as +0.

### What is actually left, and what it costs

Every remaining refusal in the census was read this round. Four groups, and only the last is buildable:

- **Walled by the hook's horizon** — anything whose guard is about context: what encloses this expression,
  what file declares that class.
- **Walled by a value that does not exist** — nine rules on constructor parameters the package wires
  nowhere, none of which declares a default.
- **Walled by design, and documented** — `findTypeToCheck` with an inline closure over PHPStan `Type`
  objects; a collaborator that builds findings rather than answering; a collector, whose measurement the
  emitting rules already reimplement.
- **Buildable, and priced.** `AssertEqualsIsDiscouragedRule` is the live one. Its condition reduces cleanly:
  `ScalarType` carries a `kind` and a `refinement`, so PHPStan's `generalize(lessSpecific)` is "drop the
  refinement", and two mutual `isSuperTypeOf` checks over generalized scalars are "the same set of scalar
  kinds". That is one runtime question rather than five folds — the shape `RuleLevel::passesAsBoolean`
  already uses.

  Two things are open and both are cheap to settle before writing anything. `UnionType::isConstantScalarValue()`
  is every member being one, read from the phar; where `generalize()` lives for a union is not yet read, and
  the reduction above depends on it. And `->fixNode($node, closure)` in the builder chain is *silently
  skipped* by the chain walk, which is right for findings and means the port offers no autofix — a
  divergence to state rather than discover.

**The reduction is the interesting part and the reason to price it rather than start it.** Mapping the
question needs the two engines' answers to agree for unions as well as single scalars, and that is a claim
about PHPStan's `generalize` that no probe in this repository has made yet.

---

## The reduction I priced is unsound, and the case that breaks it is one line

The open question from the entry above is settled by reading rather than probing. `UnionType` declares no
`generalize()`; it uses `NonGeneralizableTypeTrait`, whose `generalize()` is
`$this->traverse(fn ($t) => $t->generalize($precision))`, and `UnionType::traverse()` maps over members.
`ConstantIntegerType::generalize()` returns `new IntegerType()`. So generalizing a union of constant scalars
does give a union of bare scalars, which is what the reduction assumed.

**It is still unsound, and the reason is the conditional.** `AssertEqualsIsDiscouragedRule` generalizes each
side only `if ($type->isConstantScalarValue()->yes())`, and on a union that is *every* member being one —
read from the phar, `notBenevolentUnionResults`. So three shapes, not two:

| both sides | generalized? | mutual `isSuperTypeOf` |
|:--|:--|:--|
| `int(1)` and `int(2)` | yes, to `int` and `int` | equal — reports |
| `int` and `int` | no, already bare | equal — reports |
| `int(1)\|string` and `int\|string` | **no** — the first is not fully constant | `int(1)\|string ⊆ int\|string` but not back — silent |

The reduction "both scalar-only and the same set of scalar kinds" answers *reports* for that third row, where
the rule is silent. A mixed union of one literal and one bare scalar is a line of ordinary code —
`assertEquals(1, $stringOrInt)` — not a contrived shape.

**And the same case defeats the alternative.** Carrying "the generalized form of this type" as a recipe and
generalizing at each read is equivalent only if unconditional generalization is equivalent to the rule's
conditional one. It is not, for exactly that row: the rule leaves `int(1)|string` alone and unconditional
generalization turns it into `int|string`, which then compares equal and reports.

So both routes need the mixed case handled, and a plugin cannot refuse at runtime — it has to answer. The
honest answer there is `false`, which under-reports on a pair of *identical* mixed unions, where PHPStan
reports. That is a divergence to state, not to discover, and it makes the fold a pattern match on one
compound condition with a caveat attached rather than the clean question the reduction promised.

**Priced again, and not built.** The rule is reachable at that cost; it is not reachable at the cost the
entry above quoted, and the difference is one table row. Recording the row is worth more than the rule:
*a reduction that holds for every shape you thought of is not a reduction*, and the shape that broke this one
took working the case rather than liking the algebra.

---

## A sentinel that would have leaked, with no witness found

Closeout review of the last two code commits, by hand because Codex was interrupted twice.

`Translator::PHP_ONLY` is the *string* `/* PHP target only */`. It is the right `rust` value for a
php-only descriptor, and it is what several descriptors also give as their `php` value — including the
`regex-match` one added this round. `operand()` on the php target returns `$descriptor['php']` verbatim, so a
descriptor carrying the sentinel there does not refuse: it splices a PHP comment into whatever expression
asked for an operand. `sprintf('..', /* PHP target only */)` is the shape.

That is the same class as the two leaked-Rust defects recorded above, one of which emitted a `.php` file
containing Rust that parsed. The fix is to omit the key: `operand()` throws
`no PHP navigation for … on a … node` when `php` is absent, which is the refusal every other unhandled read
of this descriptor already gets.

**And no witness was found.** A fixture reading a match array into a message refuses identically with the key
present and absent, because the message path checks the descriptor's *kind* before it ever asks for an
operand. So this is a latent hazard removed, **not a defect fixed**, and the distinction is the point: the
emission is byte-identical, no corpus rule reaches it, and the argument for the change is the shape rather
than a measurement. Two other descriptors — `node-finder` among them — carry the sentinel as their `php`
value and were left alone, because changing them without a witness would be churn on the same reasoning.

Worth stating for whoever meets this next: the hazard is not that the sentinel is wrong, it is that it is a
*string that parses*. A sentinel which could not survive being emitted — an empty string, or a value
`operand()` recognises and refuses — would make the whole class impossible instead of latent.

### And the same review found one with a consumer

The printer fold matched on the *method name* alone, and accepted both `prettyPrintExpr` and `prettyPrint`.
php-parser's `prettyPrint()` takes an **array of statements**; `prettyPrintExpr()` takes one expression. So
the fold read a statement list as a node under the same name.

That one has a consumer. `ForbiddenNodeRule:60` writes `$this->standard->prettyPrint([$node])`, and the wide
branch matched it. What stopped it emitting was the kind check further down refusing an `Expr_Array` — and
the refusal it produced named the *argument*, not the unsupported call, so the census recorded
`access path outside the vocabulary: Expr_Array` where the obstacle was the printer method. A reader sizing
work from that line would have gone looking for array support.

Narrowed to `prettyPrintExpr` on an injected printer, matching the receiver the way the reflection fold
already does. Byte-neutral, and the census line now names the printer call. **The lesson is the pair**: the
sentinel above is a hazard with no witness and this is the same shape with one, found in the same read, and
only the census diff told them apart.

---

## CI had been red for twelve pushes, and every green number I reported was local

The release gate found `run-tests` failing on `main`, and `gh run list --workflow run-tests.yml` shows it
failing on **twelve consecutive pushes** — every commit of this session and several before it. The oldest
checked fails on the same two tests with the same messages, so it is one cause throughout, not a moving
target.

**Both failures are on the `prefer-lowest` matrix leg only**, and neither is a defect in the transpiler:

- `RecordsDivergencesTest` — the record names the engines it was produced against, `PHPStan 2.2.13`. At
  lowest resolution PHPStan installs 2.2.6, so the regenerated header differs.
- `ReportsInstalledCoverageTest::test_a_refusal_that_ends_the_pass_is_listed_as_a_need` — asserts
  `ClassAttributeRequiresPhpVersionRule` is in `phpstan/phpstan-phpunit`, which is `^2.0`. That rule does not
  exist at 2.0.0.

**The uncomfortable part is the reporting.** "Suite 311 of 311" appears in this document and in most of this
session's commit messages, and every instance is true and local. Nothing in this session looked at CI until
the release gate demanded it, so a red matrix leg sat behind every one of those sentences. The figures were
right; the impression that the tree was green was not — which is this document's own recurring failure, in my
own words this time.

### Why tightening the constraints was the wrong fix

The obvious repair is to raise the floors so lowest resolves what the records name. It is wrong twice.

`phpstan/phpstan` is **not a direct dependency** — it arrives through the rule packages — so pinning it would
mean adding it to `require-dev` purely so a recorded snapshot matches. And that inverts what the leg is for:
`prefer-lowest` exists to prove the declared floors *work*, so raising a floor to make a snapshot match turns
the leg into a second copy of `prefer-stable`. Dropping the leg fails the same way from the other side — it
removes the only check that the floors are real.

### The repository had already chosen the right pattern

`LockedCorpus::mismatch()` compares the installed rule-package versions against the ones the census records
and returns a message; `TracksUpstreamDriftTest` skips on it, and the README documents that as deliberate —
*"skipping when the installed corpus is not the one recorded — so an ordinary `composer update` neither fails
nor rewrites it."* These two tests asserted where that one skips.

Sharper still: the coverage test's **own docblock** says it asserts by name "because a rule legitimately
reaching no needs at all is possible and this test should fail when the terminal refusal goes missing, **not
when the corpus shifts**." It failed on exactly the shift its comment excludes. The comment was right and the
code did not implement it.

So both tests now take the guard. The corpus one reuses `LockedCorpus::mismatch()`. The divergence one needed
its own, because `LockedCorpus` tracks the seven rule packages and this record pins the two *engines* — the
same distinction one level up, honouring the same `WATCH_CORPUS_DRIFT` escape so the parity watch still
asserts.

### Mutation-checked, because a guard that cannot fire looks like a guard with nothing to catch

That failure happened twice earlier today, so all four cells were run rather than reasoned about:

| | corpus guard | engine guard |
|:--|:--|:--|
| versions match | asserts (3 assertions) | asserts (2 assertions) |
| versions differ | skips | skips |
| `WATCH_CORPUS_DRIFT=1` over a mismatch | — | **asserts and fails**, as the watch needs |

And a third docblock displacement: the new helper went in above `render()` and took its `@param` lines with
it, which PHPStan caught as two untyped parameters and two mixed offsets. Same shape as the two recorded
above, same fix. Three times in one session is not carelessness about one edit — inserting a method above an
existing one silently adopts its docblock, and nothing in the toolchain treats that as a change.

## A rule that emitted a plugin which could never report, and the guard it shares with one already shipped

`NoReturnSetterMethodRule` now emits. Getting there took one capability and one defect, and the defect was
already in the tree — the interesting half is that nothing in the toolchain had noticed.

### The capability: a traverser is a question, not four statements

The rule's `hasReturnReturnFunctionLike()` builds a php-parser `NodeTraverser`, adds a visitor, traverses,
and reads a flag off the visitor. The census recorded one need, `Expr_New` at line 82 — `new
HasScopedReturnNodeVisitor()`. That is the first of four statements none of which means anything alone, so
the port maps the *question* through `COLLABORATOR_CALLS`, the way `AttributeFinder::hasAttribute()` and
`FunctionLikeCognitiveComplexityRule::resolveFunctionName()` already are. `Runtime\Returns` holds the two
halves.

They disagree about closures **on purpose**, and both halves are ported as written. The return search stops
at `Closure` and at nothing else, so a `return` inside an arrow function or a nested named function still
counts; the yield search is php-parser's `NodeFinder`, which recurses into everything. Reading that as an
oversight and unifying them would have been the plausible repair.

`internal/probe-scoped-return-and-yield.php` measured three CST shapes before either walk was written, and
**two of the three do not translate the way mago's kind names read**:

| written | mago | php-parser's `Yield_` / visitor |
|:--|:--|:--|
| `yield $v` | `Yield` → `YieldValue` | matches |
| `yield $k => $v` | `Yield` → `YieldPair` | matches |
| `yield from $xs` | `Yield` → `YieldFrom` | **does not match** — separate class |
| `return;` | `Return`, no `Expression` child | does not count |
| `return 1;` | `Return` with an `Expression` child | counts |
| `fn () => 1` | `ArrowFunction` with a bare `Expression` | no `Return` to find |

`Yield` is a wrapper, so matching it fires on `yield from` where the original is silent. The probe carries
`fromYield()` beside `valueYield()` because that is the row that separates them, and the example pair carries
`setDelegating()` for the same reason.

### The defect: an enclosing-class guard asked of the wrong node

With the traverser mapped, `--survey` said EMIT. The plugin was silent.

The rule guards on `$scope->getClassReflection()->isClass()` — a question about the class-like *around* the
method. The translator answered it with `Support::declarationKindIs($context, $node, 'Class')`, which tests
the node the hook was handed. On a `Method` hook that comparison is false for every method ever written, so
the guard never passed. A probe printing each guard's value settled it in one run:

```
name='setNoAttribute' attrs=[] isClass=false pattern=true returns=true
```

Every other guard was right. `isClass` was the one that could not be true.

**The gate for it was in the wrong place.** `HOOKS` marks `ClassMethod` with `classFrom: 'metadata'`, which
the translator read as "this is a declaration hook, the node is the declaration". It is true of the class-like
hooks and of every *member* hook as well. Only `isAbstract` had noticed — it already routed to
`enclosingClassIsAbstract()` — and the other five did not.

So `Declares::enclosingClassKindIs()` answers the enclosing question by the same walk
`enclosingClassName()` uses, and the four kind predicates route to it whenever the hook is not a class-like
one. Measured across all five class-likes, one file, one run:

| method declared in | `Class` | `Interface` | `Enum` | `AnonymousClass` |
|:--|:--|:--|:--|:--|
| a class | true | false | false | false |
| an interface | false | **true** | false | false |
| an enum | false | false | **true** | false |
| an anonymous class | true | false | false | **true** |
| a trait | see below | | | |

### It was already shipped, in a fixture rule, silent since it was first emitted

`NativeReflectionHopRule` is this repository's own fixture for the native-reflection hatch. It registers
`ClassMethod` and guards on `isInterface()`, so its emitted plugin asked whether a `Method` node was an
`Interface` and returned on every call. It has a reviewed snapshot, and the snapshot recorded the silent
guard.

Nothing catches this. The plugin parses, loads, calls only helpers that exist, and runs — the four things a
count is worth stating alongside. It has no example pair, so the fires gate never looked at it; and the
snapshot compares the port against itself. **A guard that cannot pass is indistinguishable from a guard with
nothing to catch**, which is the same failure recorded twice earlier in this file, this time surviving a
release rather than a session.

The emit-all diff is what named it: one line in a file this change was not about.

### The trait row, measured rather than reasoned about

A trait method answered all four kinds false, so the port was silent on every trait-declared setter. Whether
that diverges was a question for PHPStan, not for reading: PHPStan analyses a trait member once per *using*
class and hands `getClassReflection()` that class. With a using class added, PHPStan reported and the port did
not — a real divergence, and one the example pair caught only because the trait had a user. Without one
**both engines report nothing and the row passes whether the port looked or not.**

`enclosingClassKindIs()` now asks the trait's users, which is the same answer `enclosingClassIs()` already
gives for the same reason and with the same bound: exact for a trait used by one class, under-reporting for
one used by several. The satisfying users are deliberately *not* recorded — `satisfyingUsers()` feeds
`viaTraitUsers()`, which appends them to the message, and PHPStan's message here names no user. Recording
them would have turned an agreeing finding into a differing string.

### Seven mutations, each killed, two in opposite directions

A passing gate over examples this session wrote is the weakest evidence available, so every fold was broken
on purpose and the file restored from a copy:

| mutation | result |
|:--|:--|
| drop the `Closure` stop | good example reports at line 41 |
| count a valueless `return` | good example reports |
| match the `Yield` wrapper instead of its two leaves | good example reports on `yield from` |
| disable the enclosing-kind routing | **bad example reports nothing** — "the plugin ran and found nothing" |
| drop the trait-users branch | trait row goes silent, PHPStan still reports |
| fold a trait to "is a class" | enum-only trait row reports where PHPStan does not |
| make `attributeNames()` always answer `[]` | both attributed rows report where PHPStan skips |

The trait mutations are the control pair: one row varies the axis, the other must not move. And the gate
is not agreement on zero — PHPStan reports at `BadReturningSetters.php:21` and `:29` and mago matches both.

**The last row was missing until it was looked for.** The rule's *first* guard is `$node->attrGroups !== []`,
and no file in the pair carried an attribute — so deleting that guard entirely would have passed every
check above. The gap was noticed mid-task, then displaced by the silent-plugin finding and never returned to;
an outside reader asked which row licensed it, which is the countermeasure this file already records and the
one that cannot be replaced by a more careful self-review.

Measuring it also settled a claim the vocabulary had been carrying unbacked. The `->attrGroups` mapping's
comment says a declaration has an empty group list exactly when it has no attributes, but the port answers
from *metadata* — `enclosingClassName()` then `getMethod()` — while the rule reads the syntax tree, and this
file elsewhere records that mago skips the bodies of classes whose parent it cannot resolve. If `getMethod()`
answered null there, the guard would let through a method that carries an attribute and the port would report
where PHPStan skips: a **false positive**, in the rule shipped here, in the direction this repository designs
against. One file, two rows, one axis varied:

| method with `#[Entity]`, returning a value | port | PHPStan |
|:--|:--|:--|
| in a class with a resolvable hierarchy | silent | silent |
| in a class extending an unresolvable parent | silent | silent |

So the claim holds, and it now has a row under it rather than a sentence. `GoodAttributedSetters.php` carries
both rows, and the comment can cite them.

### Pint deleted the control row, for the third time in this repository

`no_useless_return` removed the `return;` from `setBare()`, which is the only row that kills the
valueless-return mutation. The suite stayed green and the docblock above it still described a row that was no
longer there. `GoodPlainSetters.php` is now in `pint.json`'s `notPath`, and the mutation was re-run after
restoring it to confirm the control still bites. Two example files were lost the same way earlier; that makes
three, all silent.

### What did not move

php emits 166 → 167, analyzer 34 and linter 25 unchanged. The new branch refuses for both Rust targets, as
the sibling `isAbstract` branch beside it already did; what the unchanged counts measure is that no rule
which emitted on a Rust target reaches it, not that the refusal is the only thing holding them level. The
emit-all diff across all three
targets is five files: the new rule, its manifest and worker entries, the one `NativeReflectionHopRule` line,
and the `--out` path the snippet embeds. Suite 1017/1017, PHPStan 0 errors, Rector clean, Pint clean. Census
symplify 63 → 64 emit and 25 → 24 refuse; README's table row and `--status` figure re-derived from the census
and from a fresh `--status` run rather than edited to match.

### The two candidates I rejected first, and why the census could not tell me

Both had exactly one recorded need and both were traps, in the same direction the census header warns about.

`OverwriteVariablesWithForLoopInitRule` needs `->init` iteration. One expression deeper it calls
`$scope->hasVariableType($expr->name)->yes()` — the **definedness test** this file already records as
unanswerable for the PHP target, and the one its `Foreach_` sibling refuses on by name. The For/Foreach pair
is the evidence: the same blocker, recorded for one and hidden behind an iteration obstacle for the other.
Building the iteration would have bought nothing.

`MatchingTypeInSwitchCaseConditionRule` needs `->cases` iteration, and behind it a supertype comparison, two
`describe()` renderings, a per-case report line and accumulation. `IllegalConstructorStaticCallRule` needs
`getTraitAliases()`, which mago has no equivalent for — `getTraitNames()` is the closest and answers a
different question — and the branch it guards cannot be stepped over without reporting a trait-aliased
constructor the rule exempts.

**A single-need row is a claim about where the pass stopped, not about how much work is left.** It said one
thing for the rule that shipped today too: the traverser was the recorded need, and the guard that would have
made the plugin silent was not in the list at all.

## The drift alarm's three nights, and a refusal whose recorded reason is not its blocker

The nightly `upstream-parity` watch had two issues open, #10 (released) and #11 (dev-main), each
re-commented three nights running. Both are now accepted into the census. What they turned out to say is
worth more than the bump.

### The alarm was right, stable, and reproduced exactly

`hihaho/phpstan-rules` v3.15.2 — v3.18.1 adds one rule, `SlowMigrationDdlRule`. The three nightly comments
carry **byte-identical** census diffs, so nothing was accumulating, and running the alarm locally at v3.18.1
produced the same four lines. One test failed per leg, `TracksUpstreamDriftTest`, out of 1013.

Two signals in that are results rather than noise:

- **#11 adds nothing substantive over #10.** Its only extra lines are the version block showing symplify,
  type-coverage and cognitive-complexity on `dev-main`. Three packages' unreleased branches introduce no new
  rule shape at all.
- **Three minor versions of upstream drift moved no emitted byte.** The emit-all diff across all three
  targets, v3.15.2 against v3.18.1, is the `--out` path and nothing else; php stays at 167, analyzer 34,
  linter 25, with refusals +1 each for the new rule. The census recorded a change in exactly one package's
  header and one rule, and the six other hihaho rules' emissions are identical.

### `Expr_Array` is where the pass stopped, not what stops the rule

The census records the refusal as `assignment value outside the vocabulary: access path outside the
vocabulary: Expr_Array` at line 146. That line is:

```php
$found = [
    ...$this->inspectSchemaCalls($class, $resolver),
    ...$this->rawAlterFindings($class, $resolver),
];
```

Both spread elements return `list<array{int, IdentifierRuleError}>`. So the array literal is a symptom: the
rule's body is two helpers that **build findings** rather than answer questions, merged, `usort`ed by a
comparator closure and `array_map`ped to the errors — the same category the census already names for
`ClassNameRespectsParentSuffixRule`, "there is nothing here to translate into guards". Around it sit a
synthesised `new MigrationTableNameResolver($class)`, three injected collaborators and a `NodeFinder` walk.

**One clause of my own sizing needed splitting.** Reading the neon first, I corrected an assumption that
`outlierTables` was unwired like the package's other refused rules — it is wired, `%outlierTables%` is
declared with a `parametersSchema` entry and a default of `[]`, so it is the configurable shape this port
already supports through `flags`, and inertness at the default is faithful because the original is inert
there too. That is true of the **parameter** and says nothing about the **body**, which is the blocker. Two
claims of different standing about one rule, and the first reads like a verdict on the second.

So this is a `rule-shapes.md` candidate at best, not a next step, and the honest sizing is the body rather
than the line the refusal names.

### What did not need changing

The `^3.15.2` constraint stays. `composer.lock` is gitignored here, so the floor is what a consumer resolves
against, and `LockedCorpus::mismatch()` already makes the corpus tests skip when the installed corpus is not
the recorded one — which is what a `prefer-lowest` leg installing v3.15.2 now hits, by design rather than
by accident. README's hihaho row and `--status` denominator re-derived from the census and cross-checked:
the emit column sums to 105 and the denominator to 210.

Suite 1017/1017, PHPStan 0 errors, Rector and Pint clean, at the new corpus.

## Mago has no `instanceof` node, and two mutually redundant guards each passed their own check

`NoInstanceOfStaticReflectionRule` now emits. php 167 → 168. The build reopened machinery this session had
deliberately reverted, and the mutation pass found a shape the mutation discipline does not catch on its own.

### The revert named the condition, and this rule meets it

The `BinaryOp` fold was withdrawn earlier today for a stated reason: *"a capability whose only consumer cannot
complete is unexercised vocabulary."* `DisallowedLooseComparisonRule` was that only consumer and it is blocked
correct-forever on a parameter.

This rule is a second consumer that completes. It hooks `Expr`, not `BinaryOp`, and its heaviest dependency —
`RectorAllowedAutoloadedTypeAnalyzer::isAllowedType` — was already ported to `Runtime\RectorAutoloadedTypes`.
So the machinery is now exercised by a rule that reports, which is what the revert record said was missing.

### The measurement that shaped it

Mago files `instanceof` under `Binary`, with every other operator. `$a instanceof Foo` and `1 + 2` are the
same node kind, separated only by the `BinaryOperator` child's text. The class side keeps the author's
spelling, and its inner kind is the discriminator:

| written | inner kind | text | resolved |
|:--|:--|:--|:--|
| `instanceof Foo` | `Identifier` | `Foo` | `Examples\…\Foo` |
| `instanceof \App\Foo` | `Identifier` | `\App\Foo` | resolved |
| `instanceof self` | `Keyword` | `self` | null |
| `instanceof static` | `Keyword` | `static` | null |
| `instanceof $cls` | `Variable` | `$cls` | null |

Two rows would have been guessed wrong. **`self` and `static` are `Keyword`, not `Identifier`** — and
`Keyword` is one of `Names::isName()`'s kinds, which is what makes the name branch cover them the way
php-parser's `instanceof Name` does. And **only `self` is skipped**: the rule compares against `'self'` alone,
so `instanceof static` resolves to a name no allowed prefix covers and *is* reported. Folding the two keywords
together goes quiet on a case the original reports, and the gate says so.

### The defect the example pair caught, which reading would not have

The first run diverged twice, and both were the same species: a value that is correct and answers a different
question than the rule asks.

- **`instanceof Node` was reported and PHPStan is silent.** PHPStan runs its `NameResolver` before a rule sees
  the tree, so `$instanceof->class->toString()` on an imported `Node` answers `PhpParser\Node`. Mago keeps the
  written spelling and answers the resolution *separately*, through `getResolvedName()`. I read the text. `Node`
  matches no allowed prefix and `PhpParser\Node` matches the first one, so the port reported every allowed
  class that had been imported under a short name.
- **`is_a($value, Foo::class)` was not reported and PHPStan reports it.** `Names::calledFunctionName()` reads a
  *name* and answers null for a call node, so the branch was unreachable — the callee is the first `Expression`
  child. A branch that never runs and a branch with nothing to match look identical from the outside.

Both were found by running the pair against real PHPStan, and neither by reading. A probe printing what the
resolver returned per node settled the cause in one run.

### Seven mutations, and the pair that no single mutation catches

| mutation | result |
|:--|:--|
| read the written text instead of the resolved name | good example reports `instanceof Node` |
| drop the `self` skip | good example reports |
| fold `static` in with `self` | bad example loses a finding |
| point the `is_a` branch back at the call node | bad example loses a finding |
| read `is_a`'s argument 0 instead of 1 | both examples move |
| **drop the resolver's `instanceof` operator test** | **passes** |
| **make `Support::isInstanceof()` always true** | **passes** |
| both operator tests broken together | good example reports on `$left + $right` |

The last three are the finding. The emitted guard asks `Support::isInstanceof()` and the resolver asks the
same question again, so **each test alone is unnecessary and the pair is necessary.** Breaking either one
leaves the gate green; only breaking both reports on `+`.

That is a gap in the mutation discipline as this file has practised it. *Mutation-check a filter you just
wrote* catches a filter that does nothing. It does not catch **two filters that do the same thing**, because
each one's own check passes — the other is silently standing in for it. The docblock I wrote for the
`arithmetic()` control claimed it proved the operator test load-bearing; the single mutation refuted that
claim, and the row had to be re-described as controlling the pair. Redundancy is invisible to a per-fold
mutation and needs a deliberate both-at-once row.

The redundancy is kept rather than removed, and that is a choice with a reason: `subjectType()` is a public
runtime helper and a `Binary` is every operator, so a caller that has not already narrowed would otherwise
resolve `+` as an `instanceof` and read its right operand as a class name. The docblock now says it is
redundant for this consumer instead of implying it is tested.

### Widening a hook, and reading what it cost

`HOOK_KINDS[Expr::class]` gained `Binary`. That moves the `getTargets()` line of the two other rules that emit
on this hook — `EveryExpressionRule` and `NoDynamicNameRule` — and nothing else in their bodies. Both open
every branch with an explicit kind test, so a `Binary` node reaches neither report; checked by reading the
emitted guards first and then measured, because a target a guard fails to decline is a finding the original
does not make. `NoDynamicNameRule`'s fires gate is green against real PHPStan after the widening, which is the
half that reading cannot supply.

It is also more faithful than what it replaced: PHPStan's `Expr` hook does visit binary expressions, so the
old six-kind list was a subset of what the original sees.

### Pint would have deleted the control, again

`self_static_accessor` rewrites `instanceof static` to `instanceof self` — which is precisely the row that
distinguishes the two keywords, and the rule reports one and skips the other. That is the **fourth** example
file a formatter would have silently turned into a passing test of nothing. It joins `pint.json`'s `notPath`.

### What moved

php 167 → 168; analyzer 34 and linter 25 unchanged. Emit-all diff across all three targets is six files: the
new rule, its manifest and worker entries, one `getTargets()` line in each of two existing plugins, and the
`--out` path. Census symplify 64 → 65 emit and 24 → 23 refuse. `EveryExpressionRule`'s snapshot updated for
the widened target list. README's row and `--status` figure re-derived and cross-checked — the emit column
sums to 106 and the portable column to 170, plus the 40 rules of `spaze` and `composer/pcre` that make the
`--status` denominator 210. Suite 1021/1021, PHPStan 0 errors, Rector and Pint clean.

## A `STOP_TRAVERSAL` that does nothing, and two mutations that measured nothing

`FileNameMatchesExtensionRule` emits. php 168 → 169. The rule was the easy part; the instrument was not.

### The rule reads a walk whose stop is dead code

`findExtensionName()` runs `NodeFinder::find($node, function (Node $node) { … return
NodeVisitor::STOP_TRAVERSAL; })`. That reads as "stop at the first `extension()` call", and the first port was
written to match it.

**It does not stop.** `NodeFinder::find()` takes a *predicate*, not a visitor callback:
`FindingVisitor::enterNode()` calls the filter, uses the result only for its truthiness, and returns `null`
itself. The traversal runs to the end. So the answer is the last string argument of the **last** `extension()`
call anywhere below the closure, and the rule's own author appears to have believed otherwise.

The gate caught it, not reading. A fixture written to pin the stop — a bare `extension()` followed by
`extension('mismatch')` — reported under PHPStan and stayed silent under the port. Reading
`NodeFinder::find()` afterwards explained why. The port matches the behaviour, not the intent.

Two smaller shapes, both ported as written: within one call the last string argument wins (the `foreach`
assigns without breaking), and the walk descends into nested closures.

### Two mutations passed while measuring nothing, for two different reasons

Both were expected to fail. Both passed. Neither was a missing control:

**A `return;` in a void recursion is not a stop.** The mutation meant to restore the first port's behaviour
inserted an early `return;` into a `void` helper. That exits one invocation, so the parent's `foreach`
continues to the next child — it skips a *subtree*, not the walk. Re-expressed as a bool the recursion
propagates, the same mutation fails immediately. **A mutation that does not express the change it names is a
false pass**, and it looks exactly like a fold with nothing to catch. This is the "guard that cannot fire"
rule turned on the instrument rather than on the code.

**A control that varies two axes controls neither.** The nullsafe row was written
`static function (?ContainerConfigurator $c): void { $c?->extension('mismatch'); }` — nullable hint *and*
nullsafe call. The detector rejects a nullable hint before the walk ever runs, so the row never reached the
code it was written to control, and the mutation that matches `NullSafeMethodCall` passed. Dropping the `?`
from the parameter isolates the axis and that mutation then fails. `ConfigClosureRule`'s own fixture had
already recorded that the nullable hint is rejected; the row was written without reading it.

Both are the same lesson from opposite ends: **a green mutation check is evidence only once you have shown the
mutation reached the fixture and the fixture reached the fold.** Four mutations now kill this rule — the
whole-walk stop, first-string-wins, matching nullsafe calls, and dropping `basename()`'s suffix.

Mago's kind name was verified separately rather than assumed: `$c?->extension('a')` is `NullSafeMethodCall`
and `$c->extension('b')` is `MethodCall`, one file, both rows.

### A `Good*` file cannot hold a reported row

The stop control first went into the good example, where it belongs semantically — it is a case the rule
leaves alone if you believe the stop. Once the stop turned out to be dead, PHPStan reported it, and the gate's
`test_the_emitted_plugin_stays_silent_on_the_good_example` is a stricter assertion than agreement: a `Good*`
file must be silent in **both** engines, not merely agreed upon. The row moved to the bad example, where both
engines report it and it still pins the walk.

### What moved

php 168 → 169; analyzer 34 and linter 25 unchanged, since both new capabilities refuse for the Rust targets.
The emit-all diff across all three targets is three files: the new rule, its manifest and worker entries, and
the `--out` path — no existing plugin moved a byte. Census symplify 65 → 66 emit and 23 → 22 refuse. README's
row and `--status` figure re-derived and cross-checked: the emit column sums to 107 and the portable column to
170, plus the 40 of `spaze` and `composer/pcre` that make the denominator 210. Suite 1025/1025, PHPStan 0
errors, Rector and Pint clean — and Pint left the new fixtures alone, which is worth stating given four
example files have been silently rewritten by formatters here.

## Three silent-plugin bugs in one build, and a control that never reached the code it guarded

`TaggedIteratorOverRepeatedServiceCallRule` emits. php 169 → 170, and symplify passes two thirds. The rule
needed two navigations and one ported finder; what it actually surfaced was three separate ways to emit a
plugin that runs and reports nothing.

### The per-item report line already existed

The census's "a report line that is not a node's own" reads like a missing capability. It is not:
`reportAnchor()` already translates `->line($stmt->getStartLine())` into `Support::anchor($context, $stmt)`,
and `TranslationContext::$anchorNeedsLoop` already guards a loop-bound anchor emitted outside its loop. So the
sizing that mattered was one grep, and it turned a four-part build into a two-part one. **A census need names
where a rule stopped, not what the tool lacks** — checked before building, this time.

### The marker on the wrong table

`ITERABLES` carries the item kind for an iterated descriptor, so marking `subtree` items `as: 'statement'`
there looks right. The loop binder copies `as` off the **iterated subject**, not off the `ITERABLES` row, so
the marker was never read — and `$stmt->expr` fell through to a mapping that answered about the *hook node*.
The emitted plugin read the closure's own first expression once per statement:

```php
if (!(Support::isMethodCall(Support::nthExpression($context, $node, 0)))) {   // $node, not $stmt
```

It parsed, loaded, called only helpers that exist, and reported the wrong expression. Reading the emitted
plugin caught it; nothing else would have, because every statement in the fixture happens to be a method call
and the guard passed. The marker belongs on the one producer of the `subtree` descriptor.

### `statementsOf(bodyOf($node))` finds nothing, and no rule had ever composed them

`ITERABLES['subtree']` renders as `Support::statementsOf($context, {rust})` and the `->stmts` descriptor
renders as `Support::bodyOf($context, $node)`. `statementsOf()` calls `bodyOf()` itself, so the composition
asked for a body *inside* a `Block` and returned the empty list. A `foreach` over a closure's statements ran
zero times.

`grep -l statementsOf` over the emitted corpus returns nothing, so that composition had never executed. The
fix is one guard: a body is its own body. It moves no emitted byte, and the full fires gate — every rule with
a pair, not only the new one — is what says it broke nothing.

### `positionalArgAt()` already unwraps, and a call hides under a wrapper

Two more null-answers inside the ported finder, both measured rather than reasoned about after the first
guess was wrong:

- `Calls::positionalArgAt()` is `argumentValue(argumentAt(..))`, so it hands back the argument's **value**.
  Calling `argumentValue()` on that again read one level too deep and answered null for every
  `->call('add', ..)`. Position 0 arrives as a `Literal` whose text is `'add'`, quotes included.
- An array element holding `service(..)` arrives as a `Call` category node, not a `FunctionCall`. Mago files
  every call kind under that wrapper, and `Calls` keeps its own list of wrappers that does not cover this
  position.

### The name test had no control, and the row that looked like one was caught elsewhere

Five mutations, each asserted to have landed. Four failed immediately. The fifth — replacing the
`ref()`/`service()` name comparison with `return true` — **passed**, and the good example's
`->call('add', ['SomeClass'])` row was supposed to be its control.

It was not. A plain string is not a `FunctionCall`, so that row returns false at the *kind* test and never
reaches the name test. The control the name test needs is an element that **is** a call and is not one of the
two names: `->call('add', [helper('X')])`. With that row added the mutation fails.

That is the third instrument failure of the day and the same shape as the other two: **a control has to reach
the fold it is written for, and "the fixture is silent in both engines" does not show that it did.** Two rows
can both be silent for entirely different reasons, and only mutating the fold tells them apart.

### `ref()` and `service()` are compared fully qualified

`SymfonyFunctionName::REF` and `::SERVICE` hold FQNs, and PHPStan's `NameResolver` rewrites an imported
function call to its FQN before a rule sees it. Mago keeps the written spelling and answers resolution
separately, so the resolved name is what the port reads. Measured, four spellings, one file:

| written | mago resolves to | matches |
|:--|:--|:--|
| `service(..)` under `use function` | `Symfony\…\Configurator\service` | yes |
| `ref(..)` under `use function` | `Symfony\…\Configurator\ref` | yes |
| the FQN written out | `Symfony\…\Configurator\service` | yes |
| `other(..)`, unimported | `App\Config\other` | no |

Reading the written text would have matched none of the first three. `Names::calledFunctionName()` answers
**null** for all four, because it resolves through `codebase->getFunction()` and Symfony's configurator
functions are not in the analysed set — so the right instrument here is `getResolvedName()`, not the helper
that usually answers this.

### And the third path that had to ask the table before inlining

`RepeatedServiceAdderCallNameFinder::find()` is reached as an *assignment value*, and
`inlineStaticProducer()` inlined it without consulting `COLLABORATOR_CALLS` — so the port refused inside the
finder's own body, on the walk it exists to replace. The condition path asks the table at
`staticHelperStandIn()`, the `$this->` path was corrected this morning, and this is the third. Three call
paths, one table, three separate lookups: the next one will need it too.

### What moved

php 169 → 170; analyzer 34 and linter 25 unchanged. The emit-all diff across all three targets is three
files: the new rule, its manifest and worker entries, and the `--out` path — no existing plugin moved a byte,
and the `bodyOf` change is runtime-only so the diff could not have seen it either way. Census symplify 66 → 67
emit and 22 → 21 refuse. README's row and `--status` figure re-derived and cross-checked: the emit column sums
to 108 and the portable column to 170, plus the 40 of `spaze` and `composer/pcre` that make the denominator
210. Suite 1029/1029, PHPStan 0 errors, Rector and Pint clean.

## No remaining rule is one step away, and the fourth displaced docblock

This step produced no new emission, and the reason is the result: **every rule left in the candidate pool
needs three or more capabilities, or is dead on configuration and reflection mago does not carry.** Sized rule
by rule rather than from the census's needs lists, because those name where a pass stopped.

### The pool, and what each one actually costs

| rule | recorded need | what it actually needs |
|:--|:--|:--|
| `AttributeRequiresPhpVersionRule` | `getTestMethodReflection()` | the ~200-line `AttributeVersionRequirementHelper`, a PharIo composer-constraint parser, `PhpMinorVersionIterator`, and four messages under one identifier — most branches gated on `bleedingEdge`, off by default |
| `ClassAttributeRequiresPhpVersionRule` | could not find the reported message | the same helper. Two rules, two different recorded needs, one operative blocker |
| `MatchingTypeInSwitchCaseConditionRule` | `->cases` iteration | that, plus `->cond` twice, a `VerbosityLevel::value()` describe mode, and a `->no()`-polarity supertype test |
| `DynamicCallOnStaticMethodsCallableRule` | `->getType()` | plus `canCallMethods`, `getMethod`, `isStatic`, `getDeclaringClass`, `getDisplayName` |
| `IllegalConstructorStaticCallRule` | `->getTraitAliases()` | mago carries no trait aliases; `getTraitNames()` answers a different question, and the branch cannot be stepped over without reporting a trait-aliased constructor the rule exempts |
| `OverwriteVariablesWithForLoopInitRule` | `->init` iteration | behind it the definedness test its `Foreach_` sibling already refuses on by name |
| the five config-closure rules | various | a decision-tree inliner, a `find()` with a closure filter whose every match is walked, or a bind-arg statement whose position decides the answer |
| `VariablePropertyFetchRule`, `ForbiddenFuncCallRule`, and six more | a constructor parameter | wired to a container parameter the package never declares, or to no neon at all |

Two of those rows are worth keeping for their own sake. **The two version rules have different recorded needs
and the same real blocker** — a reminder that a needs list is a lower bound per rule, so two rules can look
unrelated and be the same piece of work. And `kind: 'reports'` *does* have precedent for a findings-building
helper (`AnnotationHelper::processDocComment`), so "the helper builds the findings" is not automatically fatal
— it was worth re-asking, and the answer this time is the helper's size rather than its shape.

### Why the switch rule is five pieces and not one

Its recorded need is `->cases`. Two of the four behind it are measurement-sensitive:

- **`Support::describeType()` implements `VerbosityLevel::typeOnly()` only**, and the rule's message
  interpolates `value()` *and* `typeOnly()` in one `sprintf`. `Runtime\Describe`'s own docblock records that
  9.38 % of the types at these positions render differently between renderings, so reusing the one mode would
  produce a wrong message on a measurable share of findings — and the fires gate compares message text.
- **`typeIsSuperTypeOf()` is the `->yes()` polarity only.** PHPStan answers with a `TrinaryLogic`; the SDK's
  `TypeComparator::isContainedBy()` answers a plain `bool`, so `maybe` and `no` arrive as the same `false`.
  That is exact for `->yes()`, which is what its one consumer asks. The switch rule asks
  `! isSuperTypeOf(..)->no()`, and a port built on the existing helper would report on `maybe` — wider than
  the rule, which is the direction this repository designs against.

### The fourth displaced docblock, and it had been shipped

`Support::typeIsSuperTypeOf()` carried the docblock *"Whether every part of a type is a boolean, which is
`Type::isBoolean()->yes()`"*, and `typeIsBoolean()` three methods below carried none. Someone inserted
`typeIsSuperTypeOf` and `typeIsObject` between a docblock and the method it described.

That is the fourth instance in this file, and the first found in already-committed code rather than during the
edit that caused it. The three earlier ones were caught by PHPStan, because they displaced `@param` lines that
the analyser then read as missing types. This one displaced only prose, so nothing caught it: the docblock is
syntactically valid, describes a real method, and is attached to the wrong one. **The toolchain notices a
displaced docblock exactly when it carries a type, and never when it carries an explanation.**

Fixed, and `typeIsSuperTypeOf` now states the polarity gap above, where the next port to reach for it will
read it. Docblocks only: the emit-all diff across the php target is the `--out` path and nothing else, so no
emitted byte moved. Suite unchanged at 1029/1029, PHPStan 0 errors, Pint clean.

## The trap the repository had already probed, and the displacement I wrote up and then committed

`DynamicCallOnStaticMethodsCallableRule` emits. php 170 → 171; `phpstan-strict-rules` 22 → 23 of 45. Last
step's entry said this rule needed "five reflection questions"; four of the five were already in the runtime,
and the sizing row was wrong in the rule's favour. Corrected below.

### Recognising a chain because its parts mean nothing alone

The rule calls `$this->ruleLevelHelper->findTypeToCheck($scope, <expr>, '', <criteria>)->getType()` inline.
There is no rule-owned helper to key `COLLABORATOR_CALLS` on, and keying `RuleLevelHelper::findTypeToCheck`
generically would serve every caller from a stand-in built for one criteria. Only **two** rules in the corpus
call it directly — both `DynamicCallOnStaticMethods*` — and both pass the *same* closure, so the chain is
recognised whole and the closure is validated structurally: a one-parameter closure or arrow function whose
body is `canCallMethods()->yes() && hasMethod(..)->yes()`. Anything else refuses by name.

**The criteria is deliberately not applied.** PHPStan uses it to pick which member of a union to check, and
both callers re-test the same two questions on whatever comes back — so a member this port picks differently
is rejected one line later by the rule itself.

Anchoring the recognizer on the *resolved collaborator class* did not work and the reason is worth keeping:
`RuleLevelHelper` ships inside `phpstan.phar`, so `collaboratorClass()` finds no source and answered null for
the only two rules the recognizer exists to serve. It anchors on an injected collaborator property instead,
with the criteria check as the discriminator.

### `checkThisOnly` is what decides whether the pair can agree at all

The fires gate runs PHPStan at **level 0**, where `checkThisOnly` defaults true and `findTypeToCheck`
short-circuits every receiver that is not `$this` to `ErrorType`. Measured both ways on the same fixture: at
level 9 PHPStan reports twice, at level 0 it reports nothing. So the flag joins the two the recognizer already
carried, and `FiresGate::PARAMETERS` sets it false on both sides — the mechanism the boolean and arithmetic
families already use, for exactly this reason.

Without it the pair would have agreed on **zero**, which is the one result that proves neither side looked.

### The trap this repository had already probed, and I walked into it

The plugin resolved the receiver, narrowed it, found the class, confirmed the method — and reported nothing.
The static test was reading `flags->contains(MetadataFlags::STATIC)`.

`Runtime\Types` carries this, written before today:

> `FunctionLikeMetadata->static`, not `flags->contains(MetadataFlags::STATIC)`. The flag exists and is
> documented and reads false for a `public static function` — probed on this control, where the method was
> found and the bit was not set. Reaching for the bit is the obvious move and it would have made every
> `'Class::staticMethod'` report.

I reached for the bit. The record was one file away, the comment names it as the obvious move, and the only
thing that caught it was the fires gate saying the plugin was silent. **A probe's result protects the code it
was written for and nothing else** — it lives in the class that needed it, and the next caller of the same SDK
field never sees it. Both readers now cite each other.

### And the sixth displaced docblock, written up one step ago and committed anyway

Last step's entry ended by fixing the fifth displaced docblock and explaining the mechanism: inserting a
method above an existing one silently adopts its docblock. This step inserted two methods above
`inlineStaticProducer()` and took its `@return Descriptor|null`, which PHPStan reported as *two* errors — a
missing iterable value type on the helper and a shape mismatch in its caller, the second a pure consequence of
the first.

That is the point CLAUDE.md already makes about itself: *a rule you have to remember while writing is the
instrument that already failed*. Writing the rule down one step earlier did not stop it. What stopped it was
PHPStan, and only because this docblock carried a **type** — the fifth one carried prose and shipped.

### Four mutations, each asserted landed

| mutation | result |
|:--|:--|
| read the `STATIC` flag bit instead of `->static` | bad example goes silent |
| interpolate the looked-up name instead of the canonical one | message text diverges on both rows |
| reject the validated criteria | the rule refuses instead of emitting |
| answer the receiver's class instead of the declaring one | the inherited row names `CallableSubject` where PHPStan names `CallableBase` |

The last two rows of the bad example are one fixture doing two jobs: the methods are declared `OwnStatic` and
`InheritedStatic` and called in lower camel case, so the same file controls the canonical-name read and the
declaring-class read, and the inherited one is the only row where receiver and declaring class differ.

### What moved

php 170 → 171; analyzer 34 and linter 25 unchanged. The emit-all diff across all three targets is three
files: the new rule, its manifest and worker entries, and the `--out` path — no existing plugin moved a byte.
Census `phpstan-strict-rules` 22 → 23 emit and 23 → 22 refuse, and the sibling `DynamicCallOnStaticMethodsRule`
advanced from `->getType()` to its real next blocker, a three-statement `if`. README's row and `--status`
figure re-derived and cross-checked: the emit column sums to 109 and the portable column to 170. Suite
1033/1033, PHPStan 0 errors, Rector and Pint clean.

## The sibling is two pieces, and one of them has no SDK answer

`DynamicCallOnStaticMethodsRule` is now the closest rule in the corpus: the whole
`findTypeToCheck(..)->getType()` chain, the `ErrorType` null test, `canCallMethods()`, `getMethod()` on a
type, `isStatic()` and the canonical-name read all work for it, built for its `Callable` sibling one step ago.
It refuses on two things and neither is a step to take at the end of a long session.

### The branch shape is a bounded change with one precondition

The reporting branch is three statements where the sibling's is one:

```php
if ($methodReflection->isStatic()) {
    $prototype = $methodReflection->getPrototype();
    if (in_array($prototype->getDeclaringClass()->getName(), [TypeInferenceTestCase::class, PHPStanTestCase::class], true)) {
        return [];
    }

    return [ /* the report */ ];
}
```

`isConditionalReport()` already accepts leading *assignments* followed by a final report, in either the
`$errors[] = ..` or the `return [<error>]` spelling. It rejects this body on the middle statement, because a
nested exiting guard is neither. Extending it to accept a guard among the leading statements is bounded — but
only under a precondition worth stating: a `return []` inside a conditional branch becomes a `return;` from
the plugin's `analyze()`, which is correct **only while nothing follows the branch**. Here nothing does; the
method ends `return [];`. `refuseAHoistedExit()` already exists for the general case, so the extension has to
be gated on the branch being terminal rather than written as though hoisting were always safe.

### `getPrototype()` has no SDK lookup, and stepping over it reports on this corpus

`Codebase` exposes `getMethod()` and `getDeclaringMethod()` and nothing else at method level — there is no
prototype. PHPStan's `getPrototype()` answers the *topmost* declaration, walking interfaces and parents, and a
port would mean walking `getClassAncestors()` and picking the first declarer with PHPStan's resolution order
**measured** rather than assumed: with several interfaces declaring the same static method, "topmost" is a
choice, not a fact.

And the branch it guards cannot be stepped over. It exempts subclasses of `TypeInferenceTestCase` and
`PHPStanTestCase` — PHPStan's own test scaffolding. Those are absent from most consumers, which makes the
exemption look ignorable, and present in exactly one kind of codebase: a PHPStan extension. That is this
repository and every corpus package in it. So the shape that makes the guard look safe to drop is the shape
where dropping it reports.

### Why this is recorded rather than attempted

Two pieces, one of them a semantics port with no SDK support and an ordering question that needs a
measurement against real PHPStan output. The failure mode of rushing it is the one this file is mostly about:
a rule that emits, runs, and is confidently wrong on the codebases most likely to run it. The sizing is now
precise enough that the next step is small and known, which is the whole point of writing it down instead.

## `getPrototype()` measured: unportable in general, exact for the rule that asks

Last step recorded `getPrototype()` as needing "PHPStan's resolution order measured, not assumed". Measured.
The general answer is that it cannot be ported, and the answer *for this rule* is that it does not need to be
— which is the "another route to the same answer" question, and it changes the blocker rather than confirming
it. Probes kept at `internal/probe-prototype-vs-ancestors-*.php`.

### The general case: mago's ancestor list cannot express the ordering

A throwaway PHPStan rule printing `getPrototype()->getDeclaringClass()->getName()` beside
`getDeclaringClass()->getName()`, over a hierarchy written for the purpose:

| receiver | written | PHPStan declaring | PHPStan prototype |
|:--|:--|:--|:--|
| `OwnOnly` | declares it itself | `OwnOnly` | `OwnOnly` |
| `Leaf` | `extends MiddleBase implements OtherIface`, parent implements `TopIface` | `MiddleBase` | `TopIface` |
| `TwoIfaces` | `implements FirstIface, SecondIface` | `TwoIfaces` | **`FirstIface`** |
| `ReversedIfaces` | `implements SecondIface, FirstIface` | `ReversedIfaces` | **`SecondIface`** |

The last two are the control pair, and they settle it: **the prototype follows the written `implements`
order.** Mago answers `getClassAncestors()` for both as the *same* sorted, lowercased list —
`["app\firstiface","app\secondiface"]` — so no walk over it can distinguish two classes PHPStan distinguishes.
Reading the written order instead would mean reading the ancestor's own `implements` clause, and an ancestor
declared in another file is out of a node hook's reach, which this file already records twice.

So `getPrototype()` is a correct-forever refusal for a node hook, on a measurement rather than an assumption.

### The rule's use of it is not the general case

`DynamicCallOnStaticMethodsRule` asks one thing of the prototype: whether its declaring class is
`TypeInferenceTestCase` or `PHPStanTestCase`. Prototype and declaring class diverge only when an **ancestor
interface** declares the same method — that is what the `Leaf` row shows and what the `OwnOnly` row shows the
absence of. Both exempt names are *classes*, so the divergence cannot reach this comparison: where the
prototype differs from the declaring class it is an interface, and an interface is never either of those two
names.

`getDeclaringMethod()` is therefore an exact substitute **here**, with the bound stated: a rule comparing a
prototype against an *interface* name would need the ordering above and must refuse.

Worth noting beside it: neither exempt class is installed in this corpus, so the exemption is dead in every
run this repository makes. It is not dead for a consumer analysing a PHPStan extension against
`phpstan/phpstan-src`, which is the one shape that has them — the same asymmetry as the `phpOnly` row, where
what looks ignorable is ignorable everywhere except where it matters.

### What is left, and why it stopped here

One piece: the reporting branch is an assignment, a nested exiting guard and a report, and
`isConditionalReport()` takes leading assignments but not a guard. Folding the branch into a guard chain is
the natural translation and is valid **only while nothing follows the branch** — here nothing does. But
`translateStatement()` receives a statement and no position, so the terminality signal has to be threaded
through the dispatcher every rule's body flows through, and a mistake there is a rule that silently skips work
after a branch, caught by nothing but the emit-all diff.

That is the change this session stopped before, having already measured the part that could be measured. The
remaining work is one signal through one dispatcher, and the prototype question behind it is now answered
rather than open.

## The branch folded, the guard inverted, and a precondition that protected nothing until it had a fixture

`DynamicCallOnStaticMethodsRule` emits. php 171 → 172; `phpstan-strict-rules` 23 → 24 of 45. Two steps ago
this rule was sized at two pieces and stopped before; the measurement in between made one of them portable and
this step built both.

### Folding a branch into the chain around it, and the inversion that came free with it

The reporting branch is an assignment, an exiting guard and a report. It folds into the surrounding guard
chain because a plugin has one exit and `return []` means the same inside the branch as outside it — but the
condition has to be **negated**, and `translateGuard()` is same-polarity. It exists for
`if (COND) { return []; }`, where the condition already names the exit.

Passing this one through unnegated emitted a plugin that returned on every static method and reported on the
instance ones — the rule inside out, in a file that reads exactly like a guard chain. Caught by reading the
emitted plugin, and the mutation that restores the bug reports `PlainSubject::plain()` where PHPStan reports
`PlainSubject::OwnStatic()` and `PlainBase::InheritedStatic()`.

### The precondition, and what it protected before it had a fixture

Folding is sound only while **nothing follows the branch**: hoisting an exit out of a branch with statements
after it makes the plugin skip them, and the emitted file still looks like a rule. `isTheRulesLastBranch()`
checks it against the rule method's own statement list, by identity.

Disabling that check changed **no emitted byte across the whole corpus** — no installed rule has the shape it
guards against. So it was a precondition protecting nothing measurable, which is the shape this file records
as indistinguishable from one with nothing to catch. `NonTerminalReportBranchRule` is now a fixture with
exactly that shape: with the gate it refuses on the branch, without it the refusal moves to a later message,
and the census records which. The guard is exercised by the difference rather than asserted.

### `getPrototype()`, from the measurement one step earlier

Mapped to the declaring method, which the previous step measured as an exact substitute *here* and unportable
in general: the prototype follows written `implements` order and mago's ancestor list is sorted, so the two
`TwoIfaces`/`ReversedIfaces` classes PHPStan distinguishes are indistinguishable to any walk over it. The
substitution holds because prototype and declaring class diverge only through an ancestor **interface**, and
both names this rule compares against are classes. The mapping says so where the next reader will be.

`in_array()` over class names then needed one more shape: the subject here is a class name this port already
computed rather than a node to resolve, so it compares through `namesContain()` — folding case, because
metadata hands class names back lowercased.

### The extraction, made necessary by the complexity limit

`translateIf()` crossed 20 when the reading joined it, and a **new** baseline entry is the thing this
repository watches for. Three sequential readings moved to `takenByALaterBranchReading()`, which brought
`translateIf` back under the limit and let its baseline entry be **deleted** rather than raised. The
emit-all diff across all three targets before and after the extraction is the `--out` path and nothing else,
which is what says a refactor of the shared statement path changed no behaviour.

One thing to note against myself: the first attempt to delete that baseline entry rewrote the whole file by
splitting and rejoining it, and unbaselined everything — PHPStan went from 1 error to 13. Restored from git
and removed surgically. A generated file is not a text stream to reflow.

### What moved

php 171 → 172; analyzer 34 and linter 25 unchanged. The emit-all diff is three files: the new rule, its
manifest and worker entries, and the `--out` path — plus one new *refusing* fixture rule, which adds a census
line and no emission. Census `phpstan-strict-rules` 23 → 24 emit and 22 → 21 refuse. README's row and
`--status` figure re-derived and cross-checked: the emit column sums to 110 and the portable column to 170.
Suite 1037/1037, PHPStan 0 errors with one baseline entry fewer, Rector and Pint clean.

## Four more eliminated by reading, and what the pool is made of now

No emission this step. Seven rules in, the candidate list is down to sixteen with two or fewer recorded needs,
and the four best of them were read end to end this step rather than sized from the census. All four are dead
for reasons the needs list does not say.

| rule | recorded need | what the body actually does |
|:--|:--|:--|
| `PhpUpgradeDowngradeRegisteredInSetRule` | the 3-statement branch this step built | resolves a Rector set-list constant by **computed name** — `DowngradeSetList::{$constantName}`, where the name comes from a regex capture — then reads that file off disk and greps it |
| `RequireRouteNameToGenerateControllerRouteRule` | `->getNativeReflection()` | `InvokeClassMethodResolver::resolve()` hands back a native `ReflectionMethod` whose attributes the rule then walks |
| `MatchingTypeInSwitchCaseConditionRule` | `->cases` iteration | needs `! isSuperTypeOf(..)->no()`, and `->no()` is a **deliberate, measured refusal**: of 243822 inferred types 4.23 % would make an `isNull()` a `Maybe`, and `TypeComparator` answers a plain bool |
| `NoJustPropertyAssignRule` | a node predicate on a `bytes` | behind it, `PhpDocResolver::resolve()` and `getVarTags()` — docblock var-tag resolution and a type equality |

The first is worth noting for a reason beyond itself: **the capability this step built was one of its recorded
needs, and clearing it moved the rule not at all.** The branch shape was real and so was the next obstacle
behind it, which is the census's own lower-bound warning arriving on the rule that most looked like it had
been unblocked.

### What the pool is made of

Of the sixteen with two or fewer needs, after this step's reading: seven are a constructor parameter no neon
wires, three are a findings-building helper of substantial size, three are native reflection, a filesystem
read or a dynamic constant, two are refusals this file has already measured and recorded as correct
(`->no()` polarity, local definedness), and one is docblock resolution.

None is a step. The two capability builds that would each unblock more than one rule are unchanged from two
steps ago and both are measurement-sensitive: a `VerbosityLevel::value()` describe mode, and a three-valued
answer for `->no()` — which the SDK cannot currently supply, since `TypeComparator` returns bools where
PHPStan returns `TrinaryLogic`.

## `registered` means "wired in a neon the package ships", and one rule shows what that hides

No emission. Ranking the 69 refusals by their *first* obstacle rather than by need count put a new rule at
the front, and following it settled a question about the census's own denominator.

### The largest first-obstacle group is the withdrawn family

Grouping every refusal by its first recorded reason: the biggest group is six rules on `a chain of 1 elseif
and an else`, and all six are `OperandsInArithmetic*Rule` — the family withdrawn on a measurement, not on a
gap. Building for that group would be building for rules this file already decided not to emit. The next
groups are two apiece: `ParametersAcceptorSelector`, `->getResolvedPhpDoc()`, an unwired constructor
parameter, and `not a resolvable list of strings`.

### `SeeAnnotationToTestRule`: the reason names a symptom again

Its recorded reason is `not a resolvable list of strings`, which reads like a vocabulary gap. `stringList()`
does refuse it — the value is `$this->requiredSeeTypes`, a constructor argument, and the resolver reads
`%parameters%` rather than literal service arguments.

But the operative fact is one level up. `symplify/phpstan-rules` declares four neons under
`extra.phpstan.includes`, and **neither neon that registers this rule is one of them**. It is wired in
`config/rector-rules.neon` and `config/configurable-rules.neon`, both opt-in, and the two disagree about its
configuration: one passes `[Rector\Rector\AbstractRector]`, the other three PHPStan and CodeSniffer types. So
there is no package default to carry, and a consumer choosing a neon is also choosing the value.

That is the same category seven other rules already sit in, reached from a different direction.

### The census is self-consistent, and the definition still hides something

My first reading was that the census should annotate this rule `(the package registers it nowhere)` and does
not. That reading is wrong, and `RuleOutcome::$registered` says so in its own docblock: registered means
*wired in some neon the package ships*. The rule is wired in two, so the annotation is correctly absent.

What the definition does hide is the difference between **wired in a neon the package includes by default**
and **wired in a neon a consumer must opt into**. A reader of "89 portable rules the package registers"
reasonably hears the first. `SeeAnnotationToTestRule` is the second, and its two opt-in neons do not agree.

**No count is published for that split, deliberately.** Two attempts to measure it with a parallel regex over
the package's neons produced two artefacts in a row: the first missed every rule registered as a bare list
item and answered 9 against a census figure of 89, and the second over-matched, counting `AbstractRector`,
`Empty_` and `Encapsed` — node class names inside `ForbiddenNodeRule`'s *configuration* — as registered rules.
The right instrument is the repository's own `PackageConfiguration::registeredClassNames()` restricted to the
included set, not a second extractor built beside it. Until that exists, the distinction is stated and the
number is not, which is what this file asks for when a term has no committed definition.

## A union that is always valid, and two controls that were missing until a mutation said so

`RequireQueryBuilderOnRepositoryRule` emits. php 172 → 173; symplify 68 of 89. Found by the ranking the last
step introduced — group the refusals by first obstacle, skip the group that is the withdrawn family, and read
the smallest rule left.

### The union branch changes nothing, and reducing it would narrow the rule

`isValidRepositoryObjectType()` reads as a recursive union walk followed by three `isInstanceOf()` checks. Read
in order it is something else:

```php
if ($type instanceof UnionType) {
    foreach ($type->getTypes() as $unionType) {
        if ($this->isValidRepositoryObjectType($unionType)) { return true; }
    }
}
if (! $type instanceof ObjectType) { return true; }   // ← a UnionType is not an ObjectType either
```

A union with a valid member returns true from the loop. A union with **no** valid member falls through — and
satisfies the escape below, because a `UnionType` is not an `ObjectType`. **So every union answers true, and
the union branch is dead code.** Porting it as "any member is valid" is the obvious reduction and it is
narrower than the rule: a union of two rejected classes would report where the original is silent.

What is left is exact and small: false only for a single object type that is none of `EntityRepository`,
`DocumentRepository` or `Connection`. Everything that is not an object is valid.

### Two folds had no control, and only mutating them said so

The pair passed on the first run with three rows. Two of the three mutations then passed as well:

| mutation | first run | after adding a row |
|:--|:--|:--|
| reduce the union to "any member is valid" | **fails** | fails |
| drop the allow-list entirely | passes | **fails** |
| drop the non-object escape | passes | **fails** |

The allow-list had no row because **no fixture receiver was one of the three Doctrine classes** — Doctrine is
not installed, so the obvious fixture cannot produce one. The gate already copies
`tests/Fixtures/examples/stubs/*.php` into mago's source paths for exactly this, so a
`Doctrine\ORM\EntityRepository` stub joins the two already there and the row exercises the list.

The escape had no row because every receiver was an object. A `mixed` parameter supplies one: the original
answers true for anything that is not an `ObjectType`, and dropping that escape reports on it.

Three rows, three folds, and the pair only became evidence after the mutations named what it was not testing.
That is the fourth time this session a green fires gate turned out to be measuring less than it looked, and
the third distinct reason: not a mutation that failed to express itself, not a control that shared a confound,
but **a fold with no row at all in a pair that passed**.

### What moved

php 172 → 173; analyzer 34 and linter 25 unchanged. The emit-all diff across all three targets is three
files: the new rule, its manifest and worker entries, and the `--out` path — no existing plugin moved a byte,
and the new stub changes no emission because stubs are gate scaffolding rather than corpus. Census symplify
67 → 68 emit and 21 → 20 refuse. README's row and `--status` figure re-derived and cross-checked: the emit
column sums to 111 and the portable column to 170. Suite 1041/1041, PHPStan 0 errors, Rector and Pint clean —
and Pint's `fully_qualified_strict_types` rewrote the new example's stub reference, which the gate re-run
confirmed left every control intact.

## A second attempt the same step, reverted for the reason the table already gives

`RequireQueryBuilderOnRepositoryRule` shipped above. `WrongCaseOfInheritedMethodRule` was attempted straight
after and **the work was reverted**, which is worth recording because three of its four pieces were built and
correct.

Its first blocker, `$node->getMethodReflection()`, is close to identity on a method-declaration hook: the node
*is* the declaration, so the handle is its enclosing class and its own name. Mapped. Behind it, `findMethod()`
builds its own finding, which the `kind: 'reports'` shape already covers — `Members::reportInheritedCaseMismatch()`
was written for it, reading the native declaration rather than {@see Mixins::declaringMethod()} because the
original asks `hasNativeMethod()` and a mixin-supplied method has no written name to disagree in case with.
And `isReportedErrorBookkeeping()` was widened from `instanceof RuleError` to accept `!== null`, since a
helper returning `?IdentifierRuleError` invites the comparison and both are the same bookkeeping.

Then the fourth piece: the branch is `if (parent !== null) { $m = findMethod(..); if ($m !== null) { $errors[] = $m; } }`
— two statements whose *last* is the bookkeeping `if`, and `isConditionalReport()` wants the last statement to
be the report. It is not the rule's final branch either, so the fold built last step does not apply. That is a
fourth recognizer variant on the statement path every rule flows through.

**Emit-all across the corpus with all three pieces in place moved no byte.** So they were unexercised
vocabulary — the exact thing the `BinaryOp` fold and the arithmetic port were reverted for, and the argument
does not weaken because the pieces are individually correct. Reverted rather than left in the tree, and
recorded here so the next attempt starts with three of four already sized rather than rediscovering them.

What the rule needs, in one line: a branch whose body is a reporter call followed by bookkeeping that
translates to nothing.

## Eighteen plugins lost a real guard, and the emit-all diff was the only thing that saw it

`WrongCaseOfInheritedMethodRule` emits. php 173 → 174; `phpstan-strict-rules` 25 of 45. The step before
reverted this rule with three of four pieces built; this one finished it and found the reason the reverted
version would have been wrong to ship.

### A reported name is inert, and "reported" was read too widely

`findMethod()` builds its own finding, so it is ported as a reporter through `kind: 'reports'`. The rule then
writes `$m = $this->findMethod(..); if ($m !== null) { $errors[] = $m; }` — the assignment reports, and every
later read of `$m` is the *original* collecting what it will hand back. A plugin hands back nothing, so those
reads translate to nothing: a guard on the name, and an append of it.

Both were first gated on `TranslationContext::$reportedErrors`, which holds every name an inlined error
helper bound. **That dropped `if ($namespace === null) { return; }` from eighteen emitted plugins** — a real
guard protecting the work after it, on a name that had merely held a finding at some point. The plugins still
parsed, still loaded, and still looked like rules.

Nothing but the emit-all diff could have caught it. The fires gate covers the rules with pairs, and most of
the eighteen have none; the snapshots cover twenty-two of fifty-eight; and the new rule's own pair was green
throughout. `$passReported` is now a separate set holding only names a *runtime reporter* bound, and the
blast radius is back to the new rule's own files.

### Seven pieces, and each one revealed the next

For the record, because the shape is the point: `getMethodReflection()` on a method hook; `findMethod()` as a
reporter; `isReportedErrorBookkeeping()` widened from `instanceof RuleError` to `!== null`;
`isConditionalReport()` widened to accept a trailing bookkeeping `if`; `getInterfaces()` as the transitive
`parentInterfaces`; the reporter emitted through `pass-call` rather than a raw statement, which has no PHP
rendering; and `reportsThroughPass` set so the emitter stops looking for a message this rule never builds.

The key was also wrong once: the entry was written under `Symplify\PHPStanRules\…` for a rule that lives in
`phpstan-strict-rules`, and a `COLLABORATOR_CALLS` key that matches nothing fails exactly like an unmapped
method.

### `originalName` again, on the class this time

The pair's first run differed in one place: `examples\inheritance\namingbase` where PHPStan writes
`Examples\Inheritance\NamingBase`. Metadata lowercases class names — recorded in this file as *"fine for
looking a class up again and wrong for printing"* — and the message puts the ancestor in front of a reader.
`ClassLikeMetadata->originalName` fixes it. That is the second time this session the same lesson has been
paid for, the first being method names two rules ago.

### Four mutations, one of which needed a row that did not exist

The lowercased name, the `interface`/`parent` wording, and reporting when the case already matches all fail
immediately. **Reading `directParentInterfaces` instead of `parentInterfaces` passed**, because every
interface in the pair was implemented directly. `Labels`, reached only through `NamesThings`, is the row that
separates them — and the fifth green-gate-measuring-less finding of this session.

### What moved

php 173 → 174; analyzer 34 and linter 25 unchanged. Emit-all is the new rule's own files and nothing else.
Census `phpstan-strict-rules` 24 → 25 emit and 21 → 20 refuse. Two complexity limits were crossed and neither
was baselined: `translateIf()` went over again and two more readings moved into
`takenByALaterBranchReading()`, and `Members` reached 83 against 80, so the reporter moved to
`Runtime\InheritedNames` — a static bag splits and takes its complexity with it, which is the property
`Support` was split on. README's row and `--status` re-derived: the emit column sums to 112, portable to 170.
Suite 1045/1045, PHPStan 0 errors, Rector and Pint clean.

## Three upstream issues closed as completed, and what that changes here today: nothing yet

Reported by the user, verified against the tracker rather than taken on trust. Every issue this repository
cites by number:

| issue | state | closed | what it blocks here |
|:--|:--|:--|:--|
| `carthage-software/mago#2334` | **completed** | 2026-09-07 | the definedness refusal, and three rules behind it |
| `carthage-software/mago#2333` | **completed** | 2026-09-07 | the `is_callable` narrowing this file measured a table for |
| `carthage-software/mago#2311` | completed | 2026-09-04 | already shipped in 1.47.6 and already recorded |
| `carthage-software/mago#2219` | completed | 2026-08-19 | already shipped |
| `carthage-software/mago#2310` | **not planned** | 2026-09-04 | closed against us; the trailing-`[]` reading stands |

**None of the two new ones is installable yet.** The newest mago release is **1.47.6, dated 2026-09-04**, and
both were closed on **2026-09-07** — three days after it. This package requires `^1.47.6`, so every consumer
today runs a mago without them.

So the refusal text stays exactly as it is. `definednessTest()` now carries the dates and says the sentence is
**a version boundary rather than a ceiling** — true of every mago installable today, false of the next one.
Rewriting the refusal before the capability ships would date the file forward, and the reason a rule refuses
is what the census records.

### What ships when a release carries #2334

Three rules, and the third is only visible because of a reading made earlier this session:

- `OverwriteVariablesWithForeachRule` — names the definedness refusal in the census.
- `DisallowedImplicitArrayCreationRule` — names `$scope->hasVariableType()`.
- `OverwriteVariablesWithForLoopInitRule` — reaches the same guard **behind** an `->init` iteration the pass
  stops at first, so its census line names something else entirely. That was found by reading the For/Foreach
  pair rather than by the needs list, and it is why the count is three rather than two.

Each still needs its own work behind the guard; #2334 removes the first obstacle, not the rule.

### One recorded claim to re-check on that release, not before

`#2310` was closed **not planned**, which leaves this repository's reading of the trailing-`[]` closure return
type standing as a divergence rather than a pending fix. Worth stating because a closed issue reads like
resolution at a glance, and the two states point opposite ways.

Nothing to build today. The action on release is to bump the mago requirement, re-run the corpus, and read
which of the three moves — and the census's own alarm is what will say so.

## #2310 closed *not planned*, so the divergence becomes a PR and a document

The user's direction, once `carthage-software/mago#2310` came back **not planned**: PR the codebases that use
the ambiguous notation, document how to write the docblock, and **do not** work around it in the transpiler.
Nothing in `src/` changed for this.

### Every instance, derived rather than recalled

Grepping the installed dependency tree for a callable return type ending in `[]`:

| package | file | sites |
|:--|:--|--:|
| `symfony/console` | `Question/Question.php` | 3 |
| `symfony/console` | `Helper/QuestionHelper.php` | 1 |
| `laravel/framework` | `Illuminate/Console/Concerns/InteractsWithIO.php` | 1 |

Five, and **all five sit on a parameter or return whose native hint is `callable` or `?callable`** — so the
docblock contradicts the signature rather than merely being imprecise about an unannotated value.

### Seven spellings, measured here

`internal/probe-callable-return-spellings*.php`. One parameter declared `callable` in PHP, seven docblocks,
`Type::$atomicTypes` read from inside a plugin:

| docblock | Mago infers | callable? |
|:--|:--|:--|
| `callable(string):string[]` | `array` | no |
| `callable(string):(string[])` | `callable` | yes |
| `callable(string):array<string>` | `callable` | yes |
| `callable(string):list<string>` | `callable` | yes |
| `(callable(string):string[])\|null` | `array\|null` | no |
| `(callable(string):(string[]))\|null` | `callable\|null` | yes |
| `(callable(string):array<string>)\|null` | `callable\|null` | yes |

This reproduces the three rows the retraction earlier in this file recorded, and adds four. Rows five and six
are the pair that matters for the PR: **parenthesising the whole callable, which the `|null` union already
forces, does not disambiguate it** — the parentheses have to go round the return type.

### The evidence that makes the PRs worth filing

Running mago's own analyzer over the three real files, not the fixture:

```
symfony/Question.php:203  error[docblock-type-mismatch]   docblock return vs native `(callable(...mixed=): mixed)|null`
symfony/Question.php:217  error[docblock-type-mismatch]   same for the `$callback` parameter
symfony/Question.php:223  error[invalid-callable]         `array<…callable…>` cannot be treated as a callable
symfony/Question.php:195  error[possibly-invalid-argument]  knock-on at the call site
laravel/InteractsWithIO.php:180  error[possibly-invalid-argument]  knock-on
```

**Mago already reports these files today.** That changes the ask from "please help a third-party transpiler"
to "your docblock contradicts your signature and an engine says so", which is the version worth sending.
`invalid-callable` is the sharp one: the engine has been told the code invokes an array.

`QuestionHelper.php:230` also reports, on `array_map` with a callable-array — **unrelated**, and separated
here so a reader does not carry it into the PR.

### What was written, and what was not

- `internal/guidance-callable-return-types.md` — the guidance, with the measured table and the three
  spellings to prefer. Drafted under `internal/` rather than published: `/docs` is gitignored here, and
  where a *published* page should live is a repo-shape decision rather than one to make in passing.
- `internal/pr-symfony-console-callable-return.md` and `internal/pr-laravel-framework-callable-return.md` —
  drafts with exact diffs, each ending in a *Before opening* section: re-derive the diagnostics against the
  upstream default branch rather than the installed copy, and decide `array<string>` against `list<string>`
  on intent. Laravel's forwards to symfony's, so symfony goes first and Laravel matches it.
- **Nothing in the README.** It is 1236 words against a ~900 budget and a 1200 ceiling, so a section there
  would push it further over; the guidance is a file of its own and the README is unchanged. A one-line
  pointer is a trim decision, not a free addition.
- **Nothing in `src/`.** The transpiler reads what mago reads, which is the point of the direction: a
  workaround here would make this port disagree with the engine it targets in order to agree with a docblock
  that is wrong.

## Four of the five sites were already fixed, and the handover could not have known

A peer session reported that `symfony/console` was fixed two days before our handover carried a draft to fix
it. Verified here against the raw upstream branches rather than taken on trust, 2026-09-08:

| branch | `Question.php` `@var` / `@return` / `@param` | `QuestionHelper.php` |
|:--|:--|:--|
| `8.2` (default) | `list<string>` ×3 | `list<string>` |
| `7.4` | `list<string>` ×3 | `list<string>` |
| `8.0` | `string[]` ×3 | — |

`symfony/symfony#65860`, *[Console] Disambiguate the autocompleter callback return type*, merged into 7.4 on
2026-09-06. So the symfony draft is superseded and marked so in place rather than deleted.

**Two corrections, in both directions.**

The peer's message says our first site "was never broken", because the default branch reads `list<string>`
there. The rows above say otherwise: `8.0` carries `string[]` at that same `@var`, and `8.0` branched before
the fix. It reads `list<string>` on 7.4 and 8.2 *because it was fixed*, not because it never needed fixing.
The PR's own `+2/-2` on `Question.php` accounts for two of that file's three sites, so the third was fixed by
something else — provenance not traced, and it changes no action.

Ours: **our lockfile is `symfony/console v8.1.6`**, which is why this repository's vendor tree still shows the
old notation. The grep was accurate about our tree and our tree is behind. The record now says which branch
each row belongs to, because "five sites" without a branch is the same species of claim as a count without its
configuration.

### What the miss actually was

Every figure in the handover was verified where it said it was verified, and the line numbers were labelled as
installed-tree — which is what let the peer resolve the discrepancy in one call. Nothing in it was wrong about
what it claimed.

The gap was a question neither of us asked: **had someone already fixed it?** That is not a measurement error
and no control catches it, because it is not about the subject at all — it is about the world the subject sits
in. The rule this file already carries for absence claims — *is there another route to the same answer?* — has
a sibling worth stating: **before acting on a defect, look for the change that already fixed it.** The peer
named the same guard from their own side.

`laravel/framework` remains live and is a different site from that peer's own merged `#61444`
(`Connection::withFreshQueryLog()`): `InteractsWithIO.php` at `12.x:172` and `13.x:187`. It targets **12.x**,
because Laravel merges forward only. The draft carries `list<string>` now, since that is what symfony landed
and `$choices` forwards straight into it.

## The verification harness measured nothing, twice in one command

`ShouldCallParentMethodsRule` takes the census to 122 rules emitting on the php target across the seven
packages this repository installs — `grep -c '^EMIT ' tests/Fixtures/expected/census.md`, which is the only
figure here with a command behind it. The step is worth recording for what happened to the checks rather
than for the rule.

### An emit-all diff that was clean because it emitted nothing

`CLAUDE.md` warns that a `git worktree` baseline gives an empty diff for the wrong reason, and names the
symlink that causes it. This was the same failure from two causes neither of which is that one:

- **The subcommand does not exist.** The invocation was `bin/phpstan-to-mago generate <paths>`, and paths are
  positional here — so `generate` was read as a rule file, refused by name, and the run ended `emitted: 0,
  refused: 1`.
- **zsh does not word-split an unquoted parameter.** With `CORPUS="a b c"`, `bin/phpstan-to-mago $CORPUS`
  passes one argument holding all five paths. The refusal line said so verbatim — `no file at
  vendor/symplify/phpstan-rules vendor/hihaho/phpstan-rules …` — and still read as a normal refusal.

Both runs printed a **pass**: `diff -r` over the two trees reported no difference, because both trees held
five files and neither held a plugin. What caught it was not reading the diff but reading the *count* beside
it: 5 files where the corpus emits 209. The fix is the rule already in this file at a smaller granularity —
**an aggregate cannot separate "agreed" from "never looked"** — applied to the instrument itself rather than
to a measurement it produced. A byte-for-byte diff is an aggregate over zero when the emitter refused, and a
refusal is the emitter's *correct* behaviour, so nothing in the pipeline is in an error state.

One thing the corrected run surfaced and this step does **not** explain: 34 analyzer and 25 linter, where
`CLAUDE.md` records 42 and 33 for the same four packages plus `tests/Fixtures/Rules`. Coverage growth cannot
produce a decrease. Both sides of the diff agree, so it predates this change and is not a regression from it —
the likely candidate is the hihaho corpus bump, which changed what the denominator holds. Recorded rather than
chased, and not corrected in `CLAUDE.md`, which is boost-managed and reverts a hand edit on the next
`composer install`.

Recorded countermeasure, since the emit-all diff is the repository's primary invariant check: **print the
emitted count and the file count next to the diff, and assert the count rather than reading it.** Once the
invocation was right the run emitted 143 php, 34 analyzer, 25 linter, both sides, and the only difference
across 209 files was the `--out` path inside `mago.toml.snippet`.

### A fold with a row that could not discriminate

The rule's `parent::` test is that a statement is a static call whose class is the `parent` keyword. Deleting
the class test entirely — accepting *any* static call with the right method name — left the fires gate green.

The Bad fixture's violating methods call `$this->prepare()`. There is no static call in them at all, so a fold
that accepted every static call named `setUp` still reported them, and the class test was doing nothing the
gate could see.

This joins the run of green-gate-measuring-less findings recorded above — the nearest is *"Three rows, three
folds"*, which counted itself the fourth instance and the third distinct reason. **No tally is given here on
purpose.** "Instance" has never been defined in this file, the earlier entries count within a session while
this one would count across the repository, and a count whose unit is undecided is the carried figure this
file has a rule against. The entries are the record; the pointer is the honest form.

The cause is **not** new either, and saying so is the point: it is *a row that existed but did not
discriminate*, already named in this file. The Bad fixture does reach `callsParentMethod()` — it is the fold's
own subject — but every row in it holds the class axis constant at "no static call at all", so the half of the
fold that tests the class was never varied. A fold can be fully covered on one axis and untested on another,
and the coverage on the first axis is what makes it look tested.

The control is one class beside the violation in the same file: an override calling `SharedFixtures::setUp()`
— a static call, the right method name, the wrong class. PHPStan reports it, because it is not the parent.
With the control in place the same mutation fails the gate. Written the way this file prescribes — one row
that varies the axis under test, one beside it that must not move, in the same file rather than a second
fixture.

### A fold the gate cannot falsify, and why that is the right answer

`nativeMethodExists()` reads `getDeclaringMethod()` rather than the mixin-aware `Mixins::declaringMethod()`,
because the original asks `hasNativeMethod()`. Swapping it for the mixin-aware lookup **leaves the gate
green**, and no fixture can change that: `TestCase` natively declares `setUp()` and `tearDown()` — real
PHPUnit at `TestCase.php:271` and `:302`, and the gate's own stub at `Framework.php:69` and `:72` — and every
subject of this rule is a `TestCase` descendant, so the parent class is always somewhere in that chain and
`hasNativeMethod()` is always true for the only two method names the rule looks at. The native/mixin
distinction is unobservable *for this rule*, not merely unexercised by these examples.

So the fold is recorded as **inert by construction rather than as verified**. It is still the right call —
the next rule to reach this helper will not be asking about `setUp`, and a helper that matches the original's
question is the one to keep — but the mutation result is evidence of nothing, and the mutation was run to
learn which of those two it was. This is the distinction the file's own rule about probes asks for: the
mutation answered "does any example separate these two lookups", and the question that decides the code is
"can any example separate them". Only reading `TestCase` answers the second.

### The top-level walk is faithful, and now pinned

`hasParentClassCall()` in the original iterates `$stmts` and never recurses, so `parent::setUp()` nested in an
`if` is not a call as far as the rule is concerned and PHPStan reports the class. The plugin reads the same
top level, which was faithful by accident of how `statementsOf()` works rather than by decision — nothing in
the fixtures said so either way.

`GuardedParentCall` says so now: both engines report it. Making the walk recursive — `Tree::findKind()` over
the whole body, the obvious improvement — fails the gate, because PHPStan still reports and the plugin falls
silent. The first attempt at that mutation failed *for the wrong reason*, a missing `NodeKind` import giving a
fatal and a red gate that proved nothing; the recorded rule about a mutation that does not express its change
applies to a mutation that does not compile too, and `php -l` on the mutated file is the cheap guard.

### Two folds that were already load-bearing

Both mutations failed the gate on the fixtures as they stood, so they are recorded as measured rather than
assumed: an exact-case selector compare in place of `strcasecmp` (`parent::setUp()` never matches a rule
looking for `setup`, so the plugin reported every class that *did* call its parent), and skipping the `Block`
descent inside a `MethodBody` (the statement list comes back empty, so every subject reports).

### `Members` crossed the limit and the split changed no byte

Adding the reflected-method readers took `Members` to 82 against a limit of 80 — a **new** baseline entry,
which is the thing this repository watches for. `ReflectedMethods` takes the six methods that reach metadata
through `Mixins::declaringMethod()` and none that touch the tree, so the group is the transitive closure of
one lookup, per the rule that has been splitting this runtime since `Support` was 448 (`ls src/Runtime` is
the class count; it is not a figure worth writing down here). The baseline holds 13 entries before and
after. The emit-all diff above is the evidence the extraction moved nothing: it ran with the extraction in
place on one side and `Members` intact on the other.

The three `Translator` counts that drifted with it (2590 → 2598, `methodPredicate` 115 → 120,
`resolveReflection` 461 → 464) were patched as three string substitutions, not by regenerating the file — a
generated file is not a text stream, and rewriting this one has already unbaselined everything in it once.

## Sizing the remaining refusals, and what the needs list understates

Fourteen refusals state exactly one need. None of the fourteen is a one-row fix, and checking why is worth
recording, because the needs list is a lower bound by construction — an expression-level blocker ends the
pass — and this is the first time the gap has been measured across the whole tail rather than per rule.

Three of the fourteen, read at the source rather than off the census:

- **`OverwriteVariablesWithForLoopInitRule`** states `->init` iteration. Behind it sits
  `$scope->hasVariableType($expr->name)->yes()` — the definedness test its sibling
  `OverwriteVariablesWithForeachRule` already refuses on, blocked upstream at carthage-software/mago#2334. The
  stated need is the cheap half of a rule whose other half cannot be built today.
- **`MatchingTypeInSwitchCaseConditionRule`** states `->cases` iteration. Behind it are
  `describe(VerbosityLevel::value())` and `Printer::prettyPrintExpr()`, both interpolated into the message.
  The translator supports `typeOnly()` and refuses every other verbosity **by name** at
  `src/Translator.php:13528`, so the second rendering is a known gap rather than an unknown one.
- **`IllegalConstructorStaticCallRule`** states `->getTraitAliases()`. `ClassLikeMetadata` has no
  trait-method-alias field — `typeAliases` is `@phpstan-type` and `usedTraits` is names only. The CST does
  carry `NodeKind::TraitUseAliasAdaptation`, so a route may exist, but it depends on whether Mago analyses a
  trait body once or once per using class, which decides whether the branch has a using-class context at all.
  Not probed.

### The arithmetic family is one blocker, not six rules

Ten files in `phpstan/phpstan-strict-rules` reference `OperatorRuleHelper`. Six are the
`OperandsInArithmetic*` rules — Addition, Subtraction, Multiplication, Division, Modulo, Exponentiation — and
all six refuse with an identical blocker set. That makes it the largest single-cause cluster left in the
census, which is the reason to size it before picking another single rule.

Their messages use `typeOnly()` only, so the rendering that stops
`MatchingTypeInSwitchCaseConditionRule` does not apply here. And **the two-identifier report shape is already
supported**: the refusal reads *"a second identifier before the first was reported"*, and the guard at
`src/Translator.php:8321` fires only when the first identifier was never reported under — a rule that reports
two different things one after the other is expressly allowed. So that census line names a symptom of an
earlier failure, not a missing capability.

What is left is the entry gate and one semantic question:

- **A branch that binds rather than guards.** `if ($node instanceof BinaryOpDiv) { $left = ..; $right = ..; }
  elseif ($node instanceof AssignOpDiv) { $left = ..; $right = ..; } else { return []; }` is a dispatch on
  node kind that binds two locals per arm. Every `if` shape the translator recognises today either guards or
  reports.
- **A synthesized AST node, which is the one that may have no port at all.**
  `isValidForArithmeticOperation()` tests operator overloading with
  `$scope->getType(new Expr\BinaryOp\Plus($expr, new Int_(1)))` — a node that does not exist in the analysed
  file. A plugin receives span-keyed inferred types for positions it declared an interest in, so a node with
  no span has no type. **Inferred, not measured:** this branch has no port.

Whether that kills the family depends on a question this repository cannot answer from its own tree: the
branch is guarded by `$type->isObject()->yes()`, and if core PHPStan returns `ErrorType` for `object + int`
with no operator-overloading extension installed, then treating every object operand as invalid agrees with
PHPStan exactly, and the six rules could emit under a stated bound — faithful unless the consumer installs
such an extension. If instead GMP or `BCMath\Number` support ships inside phpstan-src, the branch is live on
every install and the bound is worthless.

Asked of the `phpstan-src-e7` peer, with the reading marked as inferred and a request to mark their answer
the same way. **Nothing is built on it yet, and the sentence above is the claim to check first** if this
entry is read before the answer arrives — it is the load-bearing inference, and a bound stated on a wrong
reading of it is the *"every number right, and the sentence still wrong"* failure this file already records.

### Superseded: the bound above was worthless, and the level was the bigger miss

The entry above asks whether core PHPStan errors on `object + int` with no operator-overloading extension,
calls that the load-bearing inference, and says to check it first. It is **false**, and the `phpstan-src-e7`
peer refuted it with measurements. Marked here rather than edited away, because the reason it was wrong is
the useful part.

**Measured by the peer, in their tree, not by me:** `phpstan-src` ships four operator extensions of its own —
`GmpOperatorTypeSpecifyingExtension`, `GmpUnaryOperatorTypeSpecifyingExtension`,
`BcMathNumberOperatorTypeSpecifyingExtension` and its unary twin — all carrying `#[AutowiredService]`, so they
register on every install with no config. `GMP + 1` is `GMP`, not an `ErrorType`. And the decisive pair, which
no source read gives you: `strict-rules` **2.0.10 has no object branch and reports a false positive on GMP**;
**2.0.12 has the branch and correctly reports nothing**. The branch is the only difference in that method
between the two versions, so it is load-bearing on a stock install and exists to fix that false positive.
Treating every object operand as invalid would reproduce the bug 2.0.12 fixes.

Their caveat, flagged by them rather than buried: the `BcMath\Number` row is **inconclusive**, because
`BcMath\Number` was not in their build's stubs and PHPStan reported `class.notFound`, so the extension could
not fire whatever its version gate says. GMP is decisive on its own.

Note what the wrong inference was *not*. It was labelled as inferred, it named itself as the thing to check
first, and the peer was asked to mark their answer the same way — every countermeasure this file prescribes
was applied, and the sentence was still wrong. **Marking a claim as unverified does not make it less wrong; it
only makes it cheaper to correct.** That is the whole value, and it is worth stating plainly rather than
treating the marking as a substitute for the check.

### The route needs no node synthesis

The inference that a synthesized node has no port stands, and the peer confirms it from their own SDK work:
the protocol carries span-keyed types for positions the plugin declared, so a node with no span has no type
and there is no way to ask. But the branch does not need synthesis to be *reproduced*. It fires only for
objects, and on a stock install the overload-capable set is knowable by class name — an allowlist of `GMP` and
`BcMath\Number` gives the same answer. The bound then moves to where it belongs: faithful unless the consumer
installs a **third-party** operator extension, which is a far smaller caveat than the one this entry was
about to state.

### The level, which is mine and changes the gate

**Measured here, in this repository, by the probe at `$SP/lvl/src/Probe.php`** — the peer measured the same
thing on their own file and this table is the re-derivation, not a repeat of theirs:

| probe row              | level 0 | level 8 and 9                             |
|:--|:--|:--|
| `?int $n / 2`          | nothing | `div.leftNonNumeric`, `int\|null`         |
| `int $n / 2` (control) | nothing | nothing                                   |
| `int\|string / 2`      | nothing | core `binaryOp.invalid`, no `div.*`       |
| `\GMP / 2`             | nothing | nothing                                   |
| `\stdClass / 2`        | nothing | core `binaryOp.invalid`, no `div.*`       |

`Type::toNumber()` takes no scope and no level, so that branch cannot vary; `isSubtypeOfNumber()` goes through
`RuleLevelHelper`, whose five flags are `#[AutowiredParameter]` and flipped per level in
`conf/config.level*.neon` — `checkUnionTypes` at 7, `checkNullables` at 8, `checkExplicitMixed` at 9,
`checkImplicitMixed` at 10 (the peer's reading of the mechanism; the table above is what this tree does).

**The fires gate runs PHPStan at level 0, so it sees nothing at all from these six rules.** At level 0 a
faithful port and a stub that always returns false are indistinguishable — this file's own *agreement on zero
is not evidence* rule, arriving at the instrument rather than at a measurement. The gate already has this
problem once and already has the fix: `FiresGate::PARAMETERS` sets `checkThisOnly => false` for the
dynamic-call rules for exactly this reason, and `Runtime\RuleLevel::narrowedReceiverType()` already takes
`checkNullables` and `checkUnionTypes` as parameters. So the pattern to reuse is in the tree; it is the
per-rule parameter override, not a new mechanism.

**Two broken instruments before that table, both silent.** The first probe included
`vendor/phpstan/phpstan-strict-rules/rules.neon` explicitly while `extension-installer` already registers it;
PHPStan printed *"This file is included multiple times"* and analysed nothing, so every level reported zero.
The second added `strictRules: allRules: true` and kept the include — same warning, same empty result, and the
zero now looked like a confirmed finding across five levels. Only dropping the include produced a run. **A
five-row table of zeros reads exactly like a measurement**, and the fix was to notice that the run had no
findings *of any kind*, not that it had no `div.*` findings.

The rules are also gated behind `%strictRules.numericOperandsInArithmeticOperators%`, defaulting to
`%strictRules.allRules%`. A probe that forgets it measures a rule that never registered — the same
configuration-belongs-to-the-count rule, on the input side.

**The peer's own process note, which is the sharper version of mine:** their first run included 2.0.12's
`rules.neon` but executed `phpstan-src`'s binary, so the autoloader resolved `OperatorRuleHelper` to
phpstan-src's bundled 2.0.10 copy. They measured GMP being reported and nearly sent it — a result that would
have **confirmed my dead-branch inference for the wrong reason**. Including a package's neon does not choose
that package's code; the autoloader does. This is the artefact-that-confirms-the-hypothesis case, caught by
the person who could run the instrument, which is the only place it can be caught.

## A false positive that had shipped in four plugins, and the sentence that caused it

`OperandInArithmeticPostIncrementRule` and its three siblings emit and have emitted for some time. All four
reported `GMP`, `SimpleXMLElement` and `SimpleXMLIterator` where PHPStan reports nothing:

    GoodOverloadableObjectOperand.php
      26: Only numeric types are allowed in post-increment, GMP given.
      27: Only numeric types are allowed in post-increment, SimpleXMLElement given.
      28: Only numeric types are allowed in post-increment, SimpleXMLIterator given.

Nothing was wrong with the gate. The example pair had no row for these classes, so there was nothing to
disagree about — the failure this file keeps recording, arriving in the one place it has not been recorded
before: **in shipped output rather than in a measurement**.

### The sentence

`Runtime\RuleLevel::isValidForIncrementOrDecrement()` carried a measured table with this row:

    | `bool`, `null`, `array`, a named object   | always |

Every cell of it was measured. "A named object" was measured on **one** named object, and the phrase has no
row under it — this is *"a generalisation to an unmeasured cell"*, the first entry in this file's own list of
sentences that were wrong while every figure was right. It was even written with the honest reason beside it:
`isValidForIncrement()` has no `toNumber()` pass, so an object *is* this rule's own finding rather than
core's. True for `stdClass`, and the conclusion drawn from it was that objects are the rule's population.

The correction, read off phpstan-src rather than guessed: `ObjectType::toNumber()` answers `float|int` for
the `SimpleXMLElement` and `GMP` hierarchies and `ErrorType` for every other object. So `++` and `--` are
defined for exactly those two, PHPStan stays silent on them, and the port has to as well.

### How it was found, which is not by looking

The `phpstan-src-e7` peer volunteered it. They had just corrected their own advice — their allowlist was
wrong for the six arithmetic rules, where **no object is ever reported**, and they said so — and then noted
that the same helper's increment half has the object branch with no `toNumber()` gate above it, so there the
branch is the only discriminator. That sentence is what sent me to look, and the four rules were already
shipped. **The peer was not reviewing my code and could not see it**; they were describing a mechanism, and
the consequence was mine to find.

Worth naming precisely, because it is the third time in two days a peer has caught something no instrument
here would have: this repository's gate cannot fail on a row nobody wrote, and no amount of re-running it
produces that row. What produced it was someone describing the *original's* structure well enough that a gap
in my fixtures became visible.

### Measured before the fix, at the gate's own configuration

Level 0 with `checkThisOnly` off — the flags `FiresGate::PARAMETERS` sets for this family — with `bool++` in
the same run as a control that must fire, and it did:

| operand              | `$x++`  | `$x--`  | `++$x`  | `--$x`  |
|:---------------------|:--------|:--------|:--------|:--------|
| `GMP`                | silent  | silent  | silent  | silent  |
| `SimpleXMLElement`   | silent  | silent  | silent  | silent  |
| `SimpleXMLIterator`  | silent  | silent  | silent  | silent  |
| `stdClass`           | REPORTS | REPORTS | REPORTS | REPORTS |

One accepting set for both directions and both fixities, which is why one ported function still serves all
four rules.

### The fix, and what it costs

`acceptsAnIncrementOperator()` is the original's last branch, in its original position — below
`isSubtypeOfNumber()`, not above it. It cannot ask the original's question: that branch reads
`$scope->getType(new Expr\PreInc($expr))`, a node with no span, and a plugin gets span-keyed types for
declared positions only. It can *answer* it, because the branch is reached only for objects and the accepting
set is knowable by name — by ancestry, not by name compare, since the original asks `isInstanceOf()`.

The bound is one-directional and worth stating: a third-party `OperatorTypeSpecifyingExtension` can make `++`
valid for some other class and this port will still report it. It cannot go the other way, because an
extension cannot change `toNumber()`.

Three mutations, each failing the gate with 8 failures: dropping the branch (which is the bug as shipped),
dropping `SimpleXMLElement` from the table, and comparing the two names exactly instead of by ancestry — that
last one is what the `SimpleXMLIterator` row is in the fixture for. A fourth attempt failed *for the wrong
reason* — it called a private method and did not compile — which is the second time this session a mutation
died at parse time and reported a red gate that proved nothing. `php -l` on the mutated file, every time.

**The emit-all diff changed, deliberately, and this is the shape to expect from a bug fix:** four files, one
line each, `$context` added as the first argument to the ported helper. Counts identical on all three targets
(173 php, 34 analyzer, 25 linter), so no rule gained or lost emission, and no other plugin moved a byte.

### One thing the fix had to state rather than hide

`Types::typeIsInstanceOf()` needs a codebase for ancestry, and the unit test beside `RuleLevel` has no
context to give — a `NodeAnalysisContext` is built from an `AfterFileAnalysisContext`, a `SourceFile`, a
`Node` and a `NodeAnalysisData`, and nothing in `tests/` constructs one. So the parameter is nullable and a
null context answers the **narrower** question: the two names exactly, no subclasses.

That is not an equivalence and it is documented as not being one. Every emitted plugin passes a real context,
because the vocabulary entry declares `'takes' => 'context'`, so nothing shipped takes that path — and
`SimpleXMLIterator` is therefore checked by the fires gate rather than by the unit test. A fallback that
quietly answers a different question is how a port diverges with every test still green.

### Superseded: the bound was two-directional, and the reason I gave belonged to the other half

The entry above states the bound as one-directional — an extension can make `++` valid for another class and
this port still reports it, but *not* the reverse, "because an extension cannot change `toNumber()`". The
`phpstan-src-e7` peer demonstrated the reverse direction end to end, and it is the direction that ships a
**false negative**.

**Their demonstration**, on strict-rules 2.0.12 at level 9, one variable changed: with a twelve-line
third-party extension whose `isOperatorSupported()` matches on the operator sigil alone and whose
`specifyType()` returns `new ErrorType()`, PHPStan reports `GMP++` and `SimpleXMLElement++`. This port stays
silent, because its accepting set is fixed by ancestry and cannot know an extension is installed.

**The mechanism, verified here rather than taken from their prose** — both halves, because this is what the
correction rests on:

    union(GMP, ErrorType) = PHPStan\Type\ErrorType    instanceof ErrorType: YES
    union(GMP, NeverType) = PHPStan\Type\ObjectType   instanceof ErrorType: no
    ErrorType extends MixedType: yes

and `OperatorTypeSpecifyingExtensionRegistry` — read in the phar, so the artefact is named — filters *every*
extension whose `isOperatorSupported()` matches and returns `TypeCombinator::union(...$extensionTypes)`,
picking no winner. So one contributor returning `ErrorType` decides the answer and the built-in GMP extension
cannot outvote it. The peer expected `mixed` and probed to confirm it; the probe said `ErrorType`, and that is
the whole finding.

**Where my reason went wrong, and it is the shape worth keeping.** "An extension cannot change `toNumber()`"
is *true*, and it is exactly why the six arithmetic rules need no bound at all: every object there either
fails the `toNumber()` gate and returns true two branches early, or is one of these two, and no extension
reaches that gate. The increment half **has no `toNumber()` gate** — its only discriminator is `expr + 1`,
which is precisely what an extension reaches. I took a justification that holds for one half of a helper and
applied it to the half where the gate it depends on is absent.

That is the same error the peer had made two messages earlier, in the opposite direction, about plain objects.
Twice in one exchange, from both sides: **a true sentence about one branch, restated about a sibling whose
preconditions differ.** It is the *"cell stated as a property"* failure this file already records, with the
sibling relationship supplying the false confidence — the two halves sit in one class, share a name stem, and
read as one mechanism.

The code does not change. There is no tighter port: the discriminator is the type of a node that does not
exist in the file, so the bound is the answer rather than a gap. What changes is the sentence, in
`Runtime\RuleLevel::acceptsAnIncrementOperator()`, now stating both directions and which half each reason
belongs to.

### And a sizing sentence of mine that over-generalised the same way

"The `OperandsInArithmetic*` family is six rules with an identical blocker set" is wrong by this
repository's own census: `OperandsInArithmeticAdditionRule` carries `access path outside the vocabulary:
->getArrays()` that the other five do not, because `array + array` is valid there and the rule reads it. It is
five plus one. Flagged by a reviewer reading the census rows I had already printed — the count was in front of
me and the word "identical" was not checked against it.

### What neither of us was doing

Worth recording as its own result, because it has now happened in both directions in one exchange. The peer
could not see the false positive in my four shipped plugins; they described a structure and the consequence
was mine to find. I could not see their tree either; the thing that broke my bound was a two-line probe of a
library function neither of us had reason to doubt.

**Neither of us was reviewing the other's code. We were each testing a sentence the other had written**, which
is cheaper than review and catches a different class of defect — the class this file is almost entirely made
of. The countermeasure already recorded here is "budget for a second party rather than for a more careful
self-review". This sharpens it: what the second party should be handed is not the diff. It is the sentence.

## The arithmetic dispatch is not a design change, and the blocker moved

`CLAUDE.md` says statement and expression translation cannot be separated by extraction and to not attempt it
as a refactor. The `OperandsInArithmetic*` dispatch looked like a smaller cousin of that — a branch that
*binds* rather than guards, which no `if` shape the translator recognises does. It is not. The shape
dissolves, and what is left is one recognizer, one plumbing fix and two table rows.

### The binding problem does not exist

`internal/probe-binary-operands.php` was already in the tree, written for exactly this question. Re-run:

    Binary      $a / $b     Expression $a │ BinaryOperator     /   │ Expression $b
    Assignment  $a /= $b    Expression $a │ AssignmentOperator /=  │ Expression $b

**Identical children, identical order.** So `$node->left` / `$node->var` are one navigation and
`$node->right` / `$node->expr` are one navigation — `Support::nthExpression($context, $node, 0)` and `1`,
which the increment rules already emit. Both arms of the dispatch bind the same two things, so there is
nothing to rebind and no per-arm fork to build.

This is the second time this collapse has been measured here. `internal/handoff-multi-kind-hook-is-not-a-redesign.md`
found it for the three call kinds — identical children, so `Support::selector()` and `argumentList()` search
by kind rather than walking a field path and already worked on all three. The census refusal named the shape
both times; the shape was php-parser's field names, not Mago's tree.

### What the dispatch actually is

A target-set declaration, which is the same conclusion that handoff reached for the four `Assert*` rules from
the negated-conjunction spelling. Here it is spelled as a positive chain with an `else { return []; }`, and it
says: this rule acts on `Binary` and on `Assignment`, and on nothing else.

That matters because `getNodeType()` is `Expr::class` — the catch-all. The census refusal *"no PHP navigation
for node.var (kind expr) on a Expr node"* is that, seen from below: the hook is every expression, so nothing
downstream knows which kinds the rule can actually see. The dispatch is the only place that says.

### The blocker, now named exactly

`Vocabulary::HOOK_KINDS[Expr::class] = ['Binary', 'Assignment']` would work today and is wrong: `HOOK_KINDS`
maps a *node type* to kinds, rule-independently, and `Expr::class` is the catch-all other rules hook for other
reasons. The mapping belongs to this rule's dispatch, not to `Expr`.

Deriving it from the body needs one plumbing change, and it is the load-bearing fact:
`TranslationContext::$hookKinds` is set at `Transpiler.php:278` **before** the body loop at `:287`, and it is
read *during* translation at `Translator.php:9679` to turn `instanceof X` into `node_kind_is($context, $node,
'X')`. So a first-statement dispatch could set it and every later statement would see it — the ordering is
already right. But `Emitter.php:640` calls `targetKinds($hook)` **again** at emit time rather than reading the
context, so a body-derived set would be silently ignored and the emitted `getTargets()` would disagree with
the kind tests in the same file. Two sources of truth for one list.

That is a plugin that compiles, loads and is wrong — the failure mode this repository designs against — and
nothing in the current checks would catch it, because the corpus has no rule whose targets come from its body.

### The step, sized

1. **One plumbing fix.** One source of truth for the target list: `targetKinds()` consults the derived set, or
   the hook carries it. Not a new mechanism, but it must land before anything reads a body-derived kind.
2. **One recognizer.** A leading `if/elseif/else` whose arms bind the same locals to the same navigations and
   whose `else` is `return []` — declare the union as targets, emit the arms' conditions as one guard, emit the
   bindings once.
3. **Two table rows.** `BinaryOp\Div` is `Binary` with operator text `/`; `AssignOp\Div` is `Assignment` with
   `/=`. `EXPRESSION_KINDS` maps a class to a kind and cannot carry the operator, so this needs a predicate
   that reads the operator child — which `DisallowedLooseComparisonRule` then reuses for `Equal`.

### The gate can test it, which was not obvious

These rules report nothing at level 0, and the gate runs level 0 — the point recorded above. Measured with
`checkThisOnly: false` alone, the flag `FiresGate::PARAMETERS` already sets for this family:

| row                | level 0 + `checkThisOnly: false` |
|:--|:--|
| `bool $b / 2`      | `div.leftNonNumeric`             |
| `bool $b /= 2`     | `div.leftNonNumeric`             |
| `array $a / 2`     | silent — `toNumber()` owns it     |
| `int $n / 2`       | silent (control)                  |

One flag, and **both arms of the dispatch report**, so the fixture pair can exercise the union rather than
half of it. No second flag and no new gate mechanism.

Division alone is the first target, because one emitting rule is what keeps new vocabulary from being
unexercised — the condition three reverts in this log were made under. The other four follow as predicate
rows; Addition last, since it reads `->getArrays()` as well.

### The prefer-lowest leg caught what the fix depends on: a version

The commit above went red on CI in one leg of the matrix — `P8.4 - prefer-lowest`, four failures, every other
leg green and the local suite 1050/1050. Reading the diff direction is the whole diagnosis:

    --- Expected      (PHPStan)
    +++ Actual        (the plugin)
    -    'GoodOverloadableObjectOperand.php' => [
    -        0 => '26: Only numeric types are allowed in post-decrement, GMP given.',

A `-` line is in **Expected** and not in Actual: on that leg **PHPStan reports GMP and the fixed plugin is
silent.** Not a regression — the object branch does not exist in the version `prefer-lowest` resolves, so
PHPStan there has the false positive the branch was added to fix, and my port correctly does not reproduce it.

**Re-derived from the tags rather than taken from the peer's two data points**, counting
`isObject()->yes()` in `src/Rules/Operators/OperatorRuleHelper.php`:

| tag    | occurrences |
|:-------|------------:|
| 2.0.10 | 0           |
| 2.0.11 | 0           |
| 2.0.12 | 3           |

Three in 2.0.12, one each for the arithmetic, increment and decrement helpers. The peer had 2.0.10 and 2.0.12;
2.0.11 was the cell neither of us had, and it matters because it is what makes `^2.0.12` the right floor
rather than a guess one patch too high.

The constraint was `^2.0`, so `prefer-lowest` resolved 2.0.0. Raised to `^2.0.12`, which is a `require-dev`
entry — the corpus is installed to be read, consumers inherit none of it — so this changes what CI and a
contributor resolve and nothing a consumer installs. Asserted positively rather than by reading an absence:
`composer why-not phpstan/phpstan-strict-rules 2.0.11` now names the constraint as the blocker, and 2.0.12 is
installed. `composer.lock` needed no change and `composer validate` passes.

**What this actually says, and it is bigger than the constraint.** A port's fidelity belongs to a version of
the original, the same way a count belongs to its configuration. This is the first place in the repository
where two supported versions of one corpus package disagree about what a rule reports, and where being
faithful to one means diverging from the other. The census already prints `phpstan/phpstan-strict-rules
2.0.12` beside its counts, so the version is on the record next to the numbers it produced — that convention
was written for the census's own auditability and it turns out to carry this too.

And the `pre-release` skill's warning is now a measured event here rather than advice: the matrix has a
`prefer-lowest` leg precisely because local green is one point in a resolution space. Four failures, one leg,
and the local run could not have found it — the machine only ever had 2.0.12.

### The bound holds; "not contrived" did not, and no fixture can carry it

Two corrections to the entry above, both from the peer walking back their own framing, and one of them is
about a sentence I had already shipped in a docblock.

**The mechanism is verified; the trigger is hypothetical.** The demonstration used an extension written to
trigger it, and calling the pattern *"not contrived"* — pointing at phpstan-src's own GMP extension returning
`ErrorType` — was an argument rather than a measurement. The peer then searched, and **the search reproduces
here on an independent instrument** (`gh search code 'implements OperatorTypeSpecifyingExtension'`): after
removing phpstan's own source, its docs, its tests, and vendored copies of it — `ondrejmirtes/phar-git`,
`ithery/cf`, `cresenity/cf` — plus one code-snippet dataset, exactly one third-party implementation exists,
`jbboehr/yumemi.php`. Its gate requires one of its own types on a side, so it cannot claim a GMP pair and
cannot trigger this. It *does* return `ErrorType`, so that half of the pattern is real in the wild; the loose
gate is the half with no example.

Two agreeing searches are still one bounded search — mine was capped at 20 results — so this is "no known
extension triggers it", not "none exists". The docblock now says that, and says the bound is stated because
it is a correctness claim costing a sentence, not because it has been observed.

**And there is nothing for a fixture to assert, which is a better reason than the one I gave.** I declined to
ship the reproducer extension on the grounds that it would encode a third-party authoring shortcut as though
it were the contract. True, but weaker than the actual reason: the accepting set is fixed by ancestry, so
installing an extension changes PHPStan's answer while changing **nothing the plugin can observe**. No input
distinguishes the two worlds. A fixture would have to fake PHPStan's side of the comparison and would then be
testing the fake. **The bound is unfalsifiable from inside the plugin**, which is precisely why prose is the
honest artefact and a test would be theatre.

That is worth generalising, because this repository's instinct is to answer every claim with a fixture: a
divergence the port cannot observe cannot be gated, and the only place it could legitimately live is the
differential gate's own PHPStan configuration — a gate entry, not a fixture. With no known trigger, that row
is not worth spending either.

### What the exchange was, mechanically

Recorded because it is reusable and neither of us designed it. Over four rounds, every real finding came from
one of us probing a *sentence* the other had written, never from either of us reviewing the other's code —
which neither could see. In order: my dead-branch bound refuted by running two versions; their plain-object
explanation corrected by my `stdClass` row; a false positive in four of my shipped plugins found because they
described a helper's two halves; my one-directional bound refuted by a two-line probe of `TypeCombinator`;
their "not contrived" walked back by a search that took two commands; and my `^2.0.12` floor made correct by
counting the 2.0.11 cell they had not.

Six findings, six sentences, zero code reviews. This file already says to budget for a second party rather
than a more careful self-review; the refinement is that **what you hand the second party is the sentence, not
the diff** — and that the exchange works because each side can run an instrument the other cannot.

### Superseded: there is no plumbing blocker, and the table already answers the question

The entry above names `Emitter.php:640` re-deriving `targetKinds($hook)` at emit time as the blocker, calls it
"two sources of truth for one list", and says it must be fixed before anything reads a body-derived kind. That
is **wrong**, and it is wrong because I wrote it without reading `Vocabulary::HOOK_KINDS` first.

`HOOK_KINDS[Expr::class]` already exists and already registers `Binary`:

    Expr::class => ['ClassConstantAccess', 'StaticPropertyAccess', 'MethodCall', 'StaticMethodCall',
                    'FunctionCall', 'PropertyAccess', 'Binary'],

Re-deriving from that table at emit time is not a second source of truth — **the table is the source**, and
`TranslationContext::$hookKinds` is a cache of it for the translator. There is nothing to fix.

Worse, the design I proposed is one this repository has already tried and rejected, twice, and the rejection
is recorded three lines from the code I was about to change. `Emitter::targetKinds()`'s own docblock:

> Two earlier attempts tried to decide the breadth from whether the rule narrows: a syntactic pre-pass over
> the source, then the flag the fold set during translation. Both were wrong in the same direction, because
> neither the presence of the predicate nor its translation proves the *rule* is class-only: compounded or
> negated, it is not. Not deciding is exact.

And the `FunctionLike` row states the principle directly: the kinds a node type *covers* are a fact about the
type, and letting a rule's own `instanceof` decide the registration would make the targets depend on the body
rather than on what PHPStan would have visited. The `Binary` row adds the corollary — one entry registers what
php-parser splits over two dozen classes, **and the rule's own guard declines the operators it does not
read.**

So the dispatch is not a target-set declaration after all. It is a guard, and guards are where this design
already puts that work. My previous entry reached the opposite conclusion by generalising from the `Assert*`
handoff, which said "the guard is a target-set declaration" about a *different* hook — `CallLike`, where the
narrowed set genuinely is what PHPStan visits. Carried across to `Expr::class`, where it is not.

**Reading the table before writing the design would have cost one command.** This is the third correction in
this log arriving from prose I wrote about code I had not opened, against zero from generated output. The
failure rate still tracks whether a thing is executed.

### What the step actually is, with what already exists

Everything the dispatch navigates is in the tree:

| piece                                    | status                                                      |
|:--|:--|
| `Binary.left` / `.right`                 | `REFINEMENTS['Binary']`, operands 0 and 1                   |
| `Assignment.var` / `.expr`               | `REFINEMENTS['Assignment']`, operands 0 and 1               |
| operator text of a `Binary`              | `Operators::binaryOperatorIs()`, and `operatorIs()` is generic over the operator kind |
| `Binary` registered on the `Expr` hook   | `HOOK_KINDS[Expr::class]`                                   |

So the remaining work is four small things and one recognizer:

1. `Assignment` into `HOOK_KINDS[Expr::class]`, because an assignment is an expression and these rules read
   it. It widens the two rules already emitting on that hook, so it needs the check that row's docblock
   already models — that their guards decline the new kind — and it moves their `getTargets()` line, which is
   a deliberate emitted-byte change.
2. `assignmentOperatorIs()` beside `binaryOperatorIs()`, over `NodeKind::AssignmentOperator`. The generic
   `operatorIs()` already takes the kind.
3. A table carrying the operator dimension, which `NODE_PREDICATES` cannot: its values are predicate *names*,
   one string per php-parser class, and `BinaryOp\Div` needs a kind *and* an operator. Twenty-odd operator
   classes behind one row each, not twenty predicates.
4. `instanceof <BinaryOp|AssignOp subclass>` on a hook-node emitting the conjunction of the kind test and the
   operator test.
5. The `if/elseif/else` recognizer — arms binding the same locals to the same navigations, `else` declining —
   emitted as one guard plus the bindings once. Unchanged from the previous entry, and now the only genuinely
   new mechanism.

`DisallowedLooseComparisonRule` reuses 3 and 4 for `Equal`, which is why the table is worth having rather than
special-casing division.

### The `Expr` widening measured clean and was reverted anyway

Adding `Assignment` to `HOOK_KINDS[Expr::class]` was tried and taken back out. Recording it because the
measurement was the good outcome and the decision went the other way.

**What it measured.** One failure in 1050: `EveryExpressionRule`'s reviewed snapshot, whose `getTargets()`
line gains `NodeKind::Assignment`. Every fires gate passed. So the two real rules on that hook —
`NoDynamicNameRule` and `NoInstanceOfStaticReflectionRule` — still agree with PHPStan under the wider target
set, which is exactly the check the `Binary` row's own docblock prescribes: *a target a guard fails to decline
is a finding the original does not make.* All three rules branch on concrete kinds
(`StaticPropertyFetch`, `MethodCall`, `FuncCall`) and an `Assignment` reaches no report.

**Why it came out anyway.** Harmless is not the same as useful. Until a rule reads an assignment through this
hook, the widening buys nothing and costs three shipped plugins a hook call on every assignment in every
analysed file — pure overhead, plus a snapshot change with no behaviour behind it. That is the
unexercised-vocabulary shape this log has reverted three times before, and the argument does not weaken for
the change being small. It lands in the same commit that makes Division emit, or not at all.

**And the row has a tension worth naming rather than resolving quietly.** `HOOK_KINDS` carries two doctrines.
The `FunctionLike` and `ClassLike` rows say the kinds a node type covers are a fact about the *type*: all
four, not the subset a rule narrows to. The `Expr` row cannot follow that — `Expr` covers some two dozen
expression kinds and the row lists seven — so in practice it is the kinds corpus rules on this hook actually
read, each justified by a rule plus a check that the others decline. Adding `Assignment` for Division's
benefit is target selection driven by a body, arriving through the table instead of through
`targetKinds()`, which is the thing that function's docblock rejects twice.

That does not make the addition wrong; the row's established practice is exactly this, and PHPStan does visit
assignments for an `Expr` hook, so the seven-kind list is a **known false negative** for any rule on it. It
means the addition should be made deliberately, with the rule that needs it, and the tension stated where the
row is — not smuggled in as a one-word edit that looks like a table update.

## `OperandsInArithmeticDivisionRule` emits, and the dispatch was the last of three blockers

The census goes to 123 rules emitting on the php target, `phpstan/phpstan-strict-rules` to 26 of 45, and
`--status` to 114 of 210.

The interesting part is that `EmittedRuleFiresTest` had been tracking this exact rule as the last example pair
with no emitting rule, and its comment named all three blockers and watched two of them close. **Both facts I
"discovered" this session were already written down there**: that the operand-binding shape dissolves because
a `Binary` and an `Assignment` hold their operands in the same two positions, and that mago's mis-reporting of
a compound assignment's right-hand operand was fixed upstream in 1.47.6. I re-measured the first with a probe
that was also already in the tree. Reading the orphaned-pair list before starting would have saved most of a
turn — the same lesson as the `HOOK_KINDS` retraction one entry above, twice in two turns.

### What was actually built

- **`Vocabulary::OPERATOR_KINDS`**, carrying a kind *and* a token. `EXPRESSION_KINDS` maps a class to one
  kind and `NODE_PREDICATES` to one predicate name, and neither has anywhere to put `/` — but `BinaryOp\Div`
  and `AssignOp\Div` are one kind each plus an operator.
- **`Operators::assignmentOperatorIs()`**, the fourth sibling of a family whose docblock already set out the
  pattern. The emitted guard is the operator test *alone*, with no node-kind test beside it, and that is exact
  rather than a shortcut: `operatorIs()` matches a child of a named `NodeKind`, so the `Binary` reader is false
  for an `Assignment` and vice versa. One call decides kind and token together.
- **`Assignment` in `HOOK_KINDS[Expr::class]`**, which is the widening reverted one commit earlier for buying
  nothing. It buys something now, and the emitted plugin proved it was needed: without it `getTargets()` omits
  `Assignment` and the `/=` arm can never fire.
- **`Translator::translatesAnOperatorDispatch()`**, the one new mechanism.

### The recognizer proves the collapse instead of assuming it

The arms bind the same two navigations, so the dispatch emits as one guard plus the bindings once. But the
identity is **proved per rule, not assumed**: each arm is translated with its own Mago kind in scope, so
`->left` resolves through `REFINEMENTS['Binary']` and `->var` through `REFINEMENTS['Assignment']`, and the
resulting descriptors are compared before a single line is emitted. Where they differ the recognizer declines
and the rule keeps its old refusal.

That is the difference between this and the design the `targetKinds()` docblock rejects twice. It does not
infer what the rule reads; it checks, per rule, that two spellings resolve to the same thing, and refuses when
they do not.

### What the gate measured, and the row it was missing

The pair passed **before** the arm rows existed, which is the session's recurring failure arriving one more
time: `BadDivision` and `GoodDivision` held only the binary `/`, so the `Assignment` target,
`assignmentOperatorIs()` and the whole `/=` arm were unexercised and the gate could not have failed on any of
them. Three rows added — a `bool /= 2` that reports, an `int /= 2` control that does not, and `+`/`+=` in the
Good file so that registering the kinds without reading the token would report.

Four mutations, each measured with `php -l` first because two earlier mutations this session died at parse
time and reported a red gate that proved nothing:

| mutation                                          | result                                      |
|:--|:--|
| drop `Assignment` from the `Expr` targets         | 1 failure — the `/=` arm cannot fire        |
| `assignmentOperatorIs()` always true              | 2 failures — the `+=` control reports       |
| the binary arm's token `/` → `+`                  | 2 failures                                  |
| `Assignment`'s operands swapped, so arms disagree | the rule **refuses**, surveyed EMIT → REFUSE |

The last is the identity check working, and it needed a positive control rather than a test count: with the
arms disagreeing the rule leaves `coveredRules` entirely and PHPUnit reports *"No tests found"*, which my
summary line rendered as `failed 0 failed` — a red result with nothing behind it. `--survey` printing REFUSE
against EMIT is the assertion that actually says what happened.

### Emit-all, and why it changed on purpose

php 173 → 174; analyzer and linter unchanged, because the operator test refuses outside the php target. Seven
files move: the new plugin plus the three registration files, and the `getTargets()` line — **and only that
line** — in the three rules already on the `Expr` hook. Their fires gates pass with the wider target set,
which is the check that row's docblock prescribes: a target a guard fails to decline is a finding the original
does not make.

Suite 1054/1054, PHPStan 0 errors with 13 baseline entries and no new one, Rector clean. Pint rewrote imports
in `Translator.php` and `Vocabulary.php` after the change and the snapshots still passed, which is the
standing evidence that a formatter cannot move an emitted byte. README's row and `--status` figure re-derived,
all seven rows cross-checked against the census.

The other five arithmetic rules are now one `OPERATOR_KINDS` row each — Addition also reads `->getArrays()` —
and `DisallowedLooseComparisonRule` wants `Equal` and `NotEqual` from the same table.

## Four more of the arithmetic family, and the control that a prefix match would fool

Subtraction, Multiplication, Modulo and Exponentiation emit. The census goes to 127 rules on the php target,
`phpstan/phpstan-strict-rules` to 30 of 45, `--status` to 118 of 210. Each rule cost **one
`OPERATOR_KINDS` row per spelling** and an example pair; the recognizer built for Division needed no change,
which is what a vocabulary row buying a rule is supposed to look like.

Addition remains, and its refusal has moved from the dispatch to `access path outside the vocabulary:
->getArrays()`. That is the extra blocker the sizing named — `array + array` is valid, so the rule reads the
array types — and it is now the primary rather than a `needs:` line behind the dispatch.

### The operators were measured, not assumed to behave like `/`

At the gate's configuration, level 0 with `checkThisOnly` off, with a numeric row in the same run as a control
that must stay silent, and it did:

| operand         | `-`  | `*`  | `%`  | `**` |
|:--|:--|:--|:--|:--|
| `bool` binary   | reports | reports | reports | reports |
| `bool` compound | reports | reports | reports | reports |
| `bool` on the right | reports | — | — | — |
| `int` (control) | silent | silent | silent | silent |

Each identifier is its own (`minus.leftNonNumeric`, `mul.`, `mod.`, `pow.`), so the pairs compare messages
rather than a shared shape.

### The crossed control, which is the part worth keeping

Every Good fixture carries an operator its rule declines, so that registering the node kinds without reading
the token would report it. For four of the six that operator is `+`. For **Multiplication and Exponentiation
they are crossed** — `**` sits in the `*` fixture and `*` in the `**` one — because those two are the pair an
inexact comparison would confuse, and no other pair in the family is.

Measured rather than argued: replacing `===` with `str_starts_with()` in `Operators::operatorIs()` fails
**exactly two** of the twenty tests, and they are those two. A `+` control cannot catch that mutation at all,
because `+` is not a prefix of anything here. The general form is one this log keeps arriving at from
different directions — **choose the control by the mechanism you are excluding**, and where two values are
compared as text, the control is the value that shares a prefix rather than the value that is merely
different.

A second mutation, dropping the operator child's *kind* test so a `Binary` and an `Assignment` stop being
told apart, fails ten of twenty. Both mutations were linted before running, because two earlier mutations this
session died at parse time and reported a red gate that proved nothing.

### Emit-all

php 174 → 178; analyzer and linter unchanged. Four new plugins and the three registration files, and **no
existing plugin moved a byte** — unlike the Division commit, which had to widen `HOOK_KINDS` and moved three
`getTargets()` lines. Suite 1069/1069, PHPStan 0 errors, Rector clean, README's row and `--status` figure
re-derived and all seven rows cross-checked against the census.

## Addition emits, and its array guard is inert — proved rather than assumed

All six `OperandsInArithmetic*` rules now emit. The census goes to 128 rules on the php target,
`phpstan/phpstan-strict-rules` to 31 of 45, `--status` to 119 of 210.

Addition's extra blocker was `count($leftType->getArrays()) > 0 && count($rightType->getArrays()) > 0`, which
declines when both operands are arrays because `array + array` is a valid union.

### `getArrays()` does not mean what it reads like

My first reading was "the array atomics within this type", which would make the test *any atomic is an
array*. Probed against PHPStan directly, it is not:

| PHPStan type                             | `count(getArrays())` |
|:-----------------------------------------|---------------------:|
| `array`, `non-empty-array`, `list`       | 1                    |
| a constant array shape, the empty array  | 1                    |
| `array<int,string>\|array<string,string>`| 1                    |
| `array\|int`                             | **0**                |
| `array\|bool`                            | **0**                |

A union carrying a non-array member answers **zero**. So the question is *every* atomic, the same shape
`RuleLevel` already asks of strings and numbers — and `Runtime\ArrayTypes::typeIsWhollyArray()` is named for
that rather than for the API it ports.

This mattered before it was built: the union rows I first wrote to separate "any" from "every" could not
separate anything, because a type containing an array is *valid* for arithmetic either way and both readings
are silent. **The discriminating row was in the type API, not in a fixture.**

### The guard cannot change what the rule reports

Two independent proofs, and I built the honest implementation anyway:

- **Mechanism.** Every type above with a non-zero count has an `ErrorType` from `toNumber()`, and
  `isValidForArithmeticOperation()` returns *valid* two branches earlier for exactly that. Eight shapes, zero
  counterexamples. So an array operand never reports, and declining when both are arrays removes a finding
  that was never going to be made.
- **Behaviour.** Removing the guard from PHPStan's own copy left its findings byte-identical over a twelve-row
  fixture whose other rows *do* report — a positive control in the same run.

And the emitted plugin agrees: making `typeIsWhollyArray()` return **false unconditionally** — the guard never
declining — leaves the fires gate green. The helper is therefore **inert by construction**, the second such
case this session after `nativeMethodExists()`, and it is recorded as inert rather than as verified.

Kept regardless, because it is the question the original asks. Folding it away would make this port depend on
`isValidForArithmeticOperation()` keeping its `toNumber()` gate — upstream's to change, and a version bump has
already broken one of this repository's assumptions about that exact file this week.

### Two instruments that measured nothing, and one that measured the wrong axis

- A corpus differential over three vendor trees reported *identical* findings with and without the guard.
  PHPStan had **aborted**: one of the three paths did not exist, so nothing was analysed. The signature is by
  now familiar — a clean result from a run that never happened.
- Fixed, the same differential produced 30 findings and **zero** `plus.*` ones, so real code never triggers
  this rule and the comparison still said nothing. The positive control is what made that visible rather than
  reading as a pass.
- Two of the three mutations on `typeIsWhollyArray()` failed to express their change: replacing only the final
  `return` left the loop deciding non-array atomics, so "always true" behaved like the original. The mutation
  that *did* bite bit on the wrong axis, and reading its 2 failures as "the every/any distinction is
  load-bearing" would have been wrong.

### `Types` and `RuleLevel` are both at their ceiling

Adding the reader to `Types` took it to 82 against a limit of 80; moving it to `RuleLevel`, beside
`everyAtomicIsString()` where it belongs by shape, took *that* class to 81. Both are full.

Extracting the nine pure atomic-shape readers out of `RuleLevel` is the right split — a `Type` in, a `bool` or
`Type` out, no flag and no context, so it is a genuine transitive closure and a static bag that takes its
complexity with it. **It was attempted and reverted**: a mechanical extraction swallowed a neighbouring method
whose body ended the same way, left visibility unchanged, and stranded two constants, for sixteen PHPStan
errors. Reverted from git rather than repaired, because a split under a feature commit is how a refactor stops
being reviewable. `Runtime\ArrayTypes` holds the one reader instead, and the split is the next standalone
piece of work.

Suite 1074/1074, PHPStan 0 errors on 13 baseline entries with no new one, Rector and Pint clean. Emit-all: php
178 → 179, analyzer and linter unchanged, and the only files that move are the new plugin and the three
registration files — no existing plugin moved a byte.

## `DisallowedLooseComparisonRule`: four gates opened, a fifth found, all of it reverted

The rule went from its first refusal to its fifth in one pass and still does not emit, so the work came out.
Recorded because the four gates are real and the fifth is a defect in this repository rather than a missing
capability.

The rule hooks `BinaryOp::class`, gates on `Equal`/`NotEqual`, and builds its message as a ternary on a
constructor flag. Each piece was built and each moved the refusal on:

| built                                                        | opened                                        |
|:--|:--|
| `HOOKS` and `HOOK_KINDS` rows for `BinaryOp::class` → `Binary` | the hook mapping                            |
| `OPERATOR_KINDS` rows for `Equal` (`==`) and `NotEqual` (`!=`) | the operator gate, reusing Division's table |
| a ternary in the message builder, both arms messages           | `message expression outside the vocabulary: Expr_Ternary` |
| `CORE_PARAMETER_DEFAULTS`, holding `featureToggles.bleedingEdge => false` | the unresolved-parameter refusal  |

The ternary is worth keeping in mind for whoever builds it next: the emitted plugin carries the *same* ternary
and the flag as a constructor bool, rather than resolving it at emit time against the package default. Baking
one arm in would make the other unreachable, which is the `emitted: 4` against `emitted: 3` mistake this log
already records — a message belongs to its configuration as much as a count does.

### The fifth gate is ours, and it is a locator landing in the wrong structure

The flag resolves to **`config-list`** where it should be `config-bool`. `CORE_PARAMETER_DEFAULTS` never runs,
because `PackageConfiguration::hasParameter('featureToggles.bleedingEdge')` answers *true* — and `defaultFor`
then returns a list.

The package's `rules.neon` mentions that parameter five times, and only one is a constructor value:

    15:  reportNonIntStringArrayKey: %featureToggles.bleedingEdge%              a parameter default
    20:  booleansInLoopConditions: [%strictRules.allRules%, %featureToggles.bleedingEdge%]   inside an array
    112: phpstan.rules.rule: [%strictRules.numericOperandsInArithmeticOperators%, %featureToggles.bleedingEdge%]
    114: the same
    169: includeOperandTypesInErrorMessage: %featureToggles.bleedingEdge%       the constructor value

A resolver reading the name rather than the structure it sits in can take the array at line 20 or 112 as the
parameter's value. This is the failure this file already names — *after locating a structure by name, assert
that what you found is that structure* — arriving in our own configuration reader rather than in an audit
script. The signals are all benign: the value is a real list, the kind is computed correctly *for a list*, and
nothing downstream can tell that the list came from a different key.

**No shipped plugin is affected**, checked rather than assumed: no emitted plugin carries a constructor
argument wired to that parameter, and the two loop rules it gates are handled through
`FiresGate::REGISTRATION`. So this is a blocker on new work, not a defect in output.

`featureToggles.bleedingEdge` itself is `false` on a stock install — read from the phar, `conf/config.neon`
declaring it and `conf/bleedingEdge.neon` setting it `true` — so once the resolver answers the right shape the
plugin can carry a bool defaulting to `false`, exactly as it carries a package's own default.

### Why it was reverted rather than committed

All five additions are unexercised: the rule still refuses, so not one of them changes an emitted byte. That
is the condition three earlier reverts in this log were made under, and the fourth now. The pieces are
individually correct and individually useless until the rule emits, and leaving them in the tree would mean
shipping vocabulary no test can reach.

**The next step is the resolver, not the rule.** `hasParameter`/`defaultFor` should answer about the key they
were asked for, and asserting that the match is a scalar assignment rather than an array element is the fix.
That is one change with a fixture, and the four gates above then apply unchanged.

## `DisallowedLooseComparisonRule` emits, and the cause I published last turn was wrong

The census goes to 129 rules on the php target, `phpstan/phpstan-strict-rules` to 32 of 45, `--status` to 120
of 210. The four gates from the reverted pass all applied unchanged, which is what that entry predicted.

### Correcting the record first

The previous entry says the fifth gate was `PackageConfiguration::hasParameter('featureToggles.bleedingEdge')`
answering *true* and `defaultFor` returning a list, because the parameter's name also appears inside arrays
elsewhere in the package's neon. **That is false, and I asserted it without tracing it.** Measured directly:

    hasParameter('featureToggles.bleedingEdge')  ->  bool(false)
    defaultFor('featureToggles.bleedingEdge')    ->  NULL

The resolver is correct and always was. What I had was a real observation — the flag resolved as `config-list`
— and an invented mechanism, published as the traced cause and as the next step. This is precisely the *wrong
"why"* this repository forbids, in the form it warns about: reproduction steps and the fix both get built on
the stated cause, and mine would have sent the next session into a resolver that has no defect.

The plausible story was available and the trace was two `var_dump`s away.

### The real cause, traced

`Transpiler::traceConstructorBody()` records `$this->x = $x` as a **derived** property, because
`isPureDerivation()` answers true for a bare variable. `Translator`'s derived-property branch then returns
`kind => 'config-list'` for every such property, so a `bool` parameter is read as a list and the ternary
condition refuses. Instrumenting both ends is what settled it — *set* `config-bool`, *read* `config-list`, and
the read never reaching the configured branch at all.

The fix is upstream of the kind: a constructor assigning its own parameter through derives nothing, so
`isPassThrough()` skips it and the promoted constructor property carries the value as it already did. A first
attempt patched the *kind* instead and left the declaration, which produced a plugin the emitter wrote the
property into twice — promoted with the parameter's type and again as the `private readonly array` every
derived value gets — and PHP rejected the file with "Cannot redeclare".

**That broken file is worth stating precisely, because it is easy to overclaim.** It was not a pre-existing
defect and the tool never emitted it on its own: without the kind patch the rule *refuses*. The duplicate
declaration became reachable only once my own half-fix let the condition through. The correct summary is that
a wrong fix produced a non-loading plugin, not that the emitter had been producing them.

`php -l` on the emitted file is what caught it, and no existing check would have: the backend's operand check
passes, the census records an EMIT, and the fires gate never ran because the rule is not one it covers yet.

### What the pair pins

Both loose operators, since they are the rule's two identifiers and two branches, with `===` and `!==` as the
Good controls. Those are chosen for the mechanism again rather than for being different: **`==` is a prefix of
`===` and `!=` of `!==`**, so replacing the exact comparison in `Operators::operatorIs()` with
`str_starts_with()` fails exactly two of the four tests. A `<` row is there too, a kind this hook registers
and the rule declines.

The pass-through fix is load-bearing through the refusal path, confirmed with a positive control rather than a
test count: without it the survey prints REFUSE where it now prints EMIT, and PHPUnit reports zero tests
because the rule leaves `coveredRules` — the third time this session that a red-looking `0 failed` needed
`--survey` to say what actually happened.

### The message keeps its flag

`includeOperandTypesInErrorMessage` is wired to PHPStan's `%featureToggles.bleedingEdge%`, `false` on a stock
install — read from the phar, where `conf/config.neon` declares it and `conf/bleedingEdge.neon` sets it true.
The emitted plugin carries the same ternary and the same flag as a constructor bool rather than resolving it
at emit time, so a consumer on bleeding edge gets the longer message. Baking one arm in would make the other
unreachable, which is the `emitted: 4` against `emitted: 3` mistake already in this log.

### Two new complexity entries, both extracted rather than baselined

`translateMessageExpression()` reached 21 and `collectConfiguration()` 22 against a limit of 20 — new entries,
which is the thing this repository watches for. Extracted to `messageChosenByAFlag()`, `isPassThrough()` and
`takeCoreParameter()`; the baseline holds 13 entries before and after, and the two class totals were patched
in place.

One of my own edits also produced the ninth displaced docblock in this log: adding
`CORE_PARAMETER_DEFAULTS` directly above `OPERATOR_KINDS` left it wearing that constant's docblock and
`@var`, which PHPStan caught only because the stolen `@var` carried a type the value did not match.

Emit-all: php 179 → 180, analyzer and linter unchanged, and only the new plugin and the three registration
files move — so the two extractions changed no emitted byte and no shipped plugin ever had the duplicate
property. Suite 1078/1078, PHPStan 0 errors, Rector and Pint clean, README re-derived and all seven rows
cross-checked.

## The atomic-shape split, done as its own change

`AtomicShapes` now holds the eight readers that take a `Type` and answer a `bool` or another `Type` with no
flag and no context: `everyAtomicIsString`, `everyAtomicCoercesToNumber`, `everyAtomicIsNumber`, `isNullOnly`,
`withoutNull`, `isBareObject`, `isThis`, `isMixed`, plus `typeIsWhollyArray` folded in from `ArrayTypes`,
which existed only because both candidate classes were full and is now deleted.

`RuleLevel` keeps the questions PHPStan answers differently per analysis level. The two scalar-kind tables
moved with the readers, and `NUMERIC` is public there because `RuleLevel::keepTheNumbersOf()` filters a union
by the same table — shared rather than duplicated, which is the rule the runtime's earlier splits followed.

**Zero emitted diff across all three targets**, counts identical at 180 php, 34 analyzer, 25 linter. That is
the pass condition for a refactor and the reason this is a separate commit from the rule that motivated it.

### Why the mechanical version failed twice and this one did not

The first attempt is recorded above as reverted with sixteen PHPStan errors. Both failures came from the same
assumption — that a method's docblock is the nearest `/**` above its signature:

- `isThis` and `isMixed` have **no docblock at all**, and `isBareObject` above them has a one-line one. So
  walking back to the previous `/**` gave all three the *same* start offset, and the extractor removed
  `isBareObject`'s body three times over while leaving the other two behind.
- Two constants the moved methods read stayed put, because a method-shaped extractor has no reason to look
  for them.

What made the second attempt work was asserting the structure instead of trusting the locator: take a
docblock only when it ends on the line immediately above the signature, brace-match the body rather than
searching for `\n    }`, and **assert that no two spans overlap** before touching the file. That assertion is
what would have caught the first attempt at the point of location rather than sixteen errors downstream —
this file's own rule about locating a structure by name, applied to a refactor rather than to an audit.

The two methods with no docblock got one while they were moved.

### And the reason to do it now rather than later

Not headroom for its own sake. `Types` reached 82 against a limit of 80 the moment one type reader was added
to it, and `RuleLevel` 81 — so the *next* reader had nowhere to live, which is why the previous commit shipped
a single-method class. `RuleLevel` has now shed eight methods and two constants, so the next one goes where it
belongs.

Suite 1078/1078, PHPStan 0 errors on 13 baseline entries with no new one, Rector and Pint clean.

### The one-need list is not a queue, measured again

Before starting this, the fourteen refusals stating a single need were checked rather than counted, and none
is one capability away:

- **`MatchingTypeInSwitchCaseConditionRule`** states `->cases` iteration and has three hard blockers behind
  it: `!$t->isSuperTypeOf($c)->no()`, where `->no()` is refused by name with a measured reason;
  `describe(VerbosityLevel::value())`, refused at `Translator.php:13528`, which supports `typeOnly()` only;
  and `Printer::prettyPrintExpr()`, which has no equivalent.
- The **largest** family by primary blocker is four rules sharing a `Node::class` hook narrowed by
  `instanceof`, and the multi-kind handoff's own condition for building it — a second rule of that shape — is
  finally met. It is still not the target: one is a *collector*, one takes its kinds from a configured value
  rather than written class names, and two build their findings in helpers. Each carries three or more
  further needs, so the shared blocker buys no emit on its own.

Both readings come from the source rather than the census, which is the point: **the count ranks blockers,
the rules say what a blocker is worth.**

## Three candidate capabilities sized, each buying zero emits

No rule emitted this pass, and the useful result is why. Three named gaps were sized by counting their
dependents in the installed corpus rather than by reading how important they sound.

**`describe(VerbosityLevel::value())`** — refused by name at `Translator.php:13528`, which supports
`typeOnly()` only. I guessed last pass that "several rules sit behind it". **Exactly one does**:
`MatchingTypeInSwitchCaseConditionRule`, which also needs `!…->no()` and `Printer::prettyPrintExpr()`. For
scale, 27 files use `typeOnly()`, which is already supported. So the capability buys nothing on its own, and
the guess cost one command to refute.

**`->no()` on a trinary** — refused with a measured reason (of 93 trinary tails in the installed packages, 86
are `->yes()`, six `->no()`, one `->maybe()`). It is also *expressible*, which the refusal does not claim: the
existing readers answer "every atomic satisfies" for `->yes()`, and `->no()` is "no atomic satisfies", with
Maybe the remainder. Three census rules use it and every one is blocked elsewhere —
`MatchingTypeInSwitchCaseConditionRule` as above, `ArrayFilterStrictRule` with fifteen further needs, and
`DisallowedImplicitArrayCreationRule` which turns *entirely* on `$scope->hasVariableType()`.

**Definedness** — that last rule is the interesting one, because it is one capability away and the capability
is not ours. `carthage-software/mago#2334` is **CLOSED/COMPLETED on 2026-09-07** and the newest release is
**1.47.6, 2026-09-04**, which is what we install. The fix is merged and unreleased, so the highest-value
unlock left is a wait rather than a task.

### The multi-kind family is reachable, and still not worth building yet

Four rules share the largest primary blocker — a `Node::class` hook narrowed by `instanceof` — and the
refusal that reports it already names the design: a plugin can register several targets, so what is needed is
a hook and a field mapping per kind plus a body that reads the same child in every branch.

That also resolves a tension this log flagged one entry earlier. `Emitter::targetKinds()`'s docblock rejects
deriving targets from the body, twice, and the `FunctionLike` row says the kinds a node type covers are a fact
about the *type*. Both hold where a `HOOK_KINDS` row is possible. **`Node::class` covers 227 `NodeKind` cases**
— counted, not estimated — so no row is practical there and the body's own `instanceof` set is the only
statement of what the rule can see. The narrow rule that follows: derive from the body *only* where the node
type has no usable kind list.

It still buys zero emits today. `NewWithFollowingSettersCollector` is a collector, `ForbiddenNodeRule` takes
its kinds from a configured value known only at analysis time, and `NoReferenceRule` and `PreferredClassRule`
each carry three or more further needs. Building it now would be the unexercised-vocabulary revert for the
fifth time.

### Where the effort goes next, named so the next pass starts with a plan

`NoReferenceRule` is the most tractable of the four: eight kinds (`AssignRef`, `Closure`, `ArrowFunction`,
`Function_`, `ClassMethod`, `Arg`, `Foreach_`, `ArrayItem`), a `Closure` node predicate, and guard bodies that
return a value rather than `return []` or `continue`. Three pieces, all mechanical, none depending on
upstream. `PreferredClassRule` is worse: it narrows to `InClassNode`, a PHPStan *virtual* node with no
syntactic counterpart, and builds its findings in helpers.

**The cheap seam is exhausted.** Eight rules emitted in this session by adding a row or two to a table; what
is left needs either an upstream release or three-to-fifteen capabilities per rule. That is worth stating
plainly rather than discovering it once per pass — and it is why this entry is a sizing rather than a rule.

## `NoReferenceRule` needs five or six pieces, not three, and `byRef` has no model

### Correcting my own sizing first

The previous entry names this rule as "three pieces, all mechanical". That number came from the census's three
`needs:` lines, **not from reading the rule** — the lower-bound mistake this log records against the one-need
list, made in my own plan one entry after writing it down. Read at the source, `processNode` needs:

1. eight kinds off a `Node::class` hook (the multi-kind blocker);
2. `$node instanceof AssignRef` reporting immediately, per kind;
3. the negated conjunction over the other seven declining;
4. **`$node->byRef`**, on all seven kinds;
5. a second dispatch — `Function_|ClassMethod` — into a helper;
6. that helper's `$this->parentClassMethodNodeResolver->resolveParentClassMethod()`, a service, plus
   iteration over declared params reading `byRef` again, and a finding per param.

### `byRef` is readable, and only from the text

Measured with `internal/probe-by-reference-shapes.php`, kept for the next attempt:

    FunctionLikeParameter   int &$out       children: Hint, DirectVariable
    FunctionLikeParameter   int $out        children: Hint, DirectVariable      <- identical
    Foreach  ... as &$row                   children: Keyword, Expression, Keyword, ForeachTarget, ForeachBody
    Foreach  ... as $row                    the same, ForeachTarget text `$row` instead of `&$row`

**There is no `&` node and no by-reference flag.** A by-ref parameter and a plain one have the same children
in the same order; the ampersand exists only inside the node's own source text. This repository already
*refuses* on `byRef` in two places (`Translator.php:2237` and `:8155`) rather than reading it, and now there is
a measurement saying why.

That makes this the one case where this log's hardest rule — **read the model, never a rendering** — does not
apply as written. It forbids reading a rendering *instead of* an available model, and here the model does not
carry the fact at all. The honest form is that the text is the only source, and the design has to be robust in
a way a `str_contains($text, '&')` is not: a hint, a default value or a comment can all contain an ampersand.

The sound shape, since every kind gives the operand its own child with a span: **look at the character
immediately before that child's span, inside the parent's**. That is positional rather than pattern-matching,
and it answers the same question for a parameter, a `ForeachTarget`, an argument and an array element without
a table of seven text shapes.

### Not started, deliberately

Five or six pieces with one shared runtime helper is a real build, and the piece that decides it —
`resolveParentClassMethod`, a service asking whether an ancestor declares the method — is the kind of
collaborator that has ended two attempts in this log already. Recording the measurement and the design is
worth more than a half-built rule, and the `byRef` fact is reusable: `NoReferenceRule`,
`DisallowedImplicitArrayCreationRule`'s sibling shapes and the two `byRef` refusals all want it.

## The `NoReferenceRule` design, corrected twice before a line was written

The `phpstan-src-e7` peer answered both questions and both answers changed the design. Recorded because the
corrections are reusable and one of them would have shipped a false negative.

### The parent-method predicate is cheap, and has two divergences

**Measured by the peer, in their tree.** `ParentClassMethodNodeResolver::resolveParentClassMethod()` walks
`getAncestors()` — parents and interfaces, self filtered — takes the first with `hasMethod()`, then
*reparses the declaring file* to produce a `ClassMethod`. My inference from the return type was right about
that. But `NoReferenceRule` observes **only nullness**:

    if ($parentClassMethod instanceof ClassMethod) { return []; }

So no cross-file AST is needed and the cheap route — "an ancestor declares a method of this name" — is
viable. Two things it must not do naively:

- **Internal parents.** `ClassReflection::getFileName()` is null for an internal class, so the resolver
  returns null even though the ancestor plainly declares the method. Their rows: a userland parent is silent,
  a *vendor* userland parent is silent, and `extends \ArrayObject` **reports**. A port answering "an ancestor
  declares it" would go silent where PHPStan reports — a false negative, and the line is
  internal-versus-userland rather than in-the-analysed-paths.
- **A caching defect.** `ReflectionParser::parseFilenameToClass` caches the **first** `ClassLike` per
  filename and returns it for every class declared in that file. They demonstrated it by flipping declaration
  order in one file and nothing else: the same two classes with the same two parents give opposite answers.

So the faithful predicate is three clauses — an ancestor declares it, **and** it is userland with a source
file, **and** it is the first class-like in that file. The third is a defect rather than a contract, and the
call is to port the first two and record the third as a known divergence. Emulating it would pin someone
else's bug, which is the same call this log already made about not shipping a fixture for their twelve-line
extension.

### My `byRef` design was wrong in three ways

**`Param` was missing from my list**, and it is the node the second half of the rule reads —
`$param->byRef` at `NoReferenceRule.php:83`, reached through `$functionLike->params` rather than through the
hook. Building against my seven would have implemented the hook half and silently dropped the parameter half.

Two corrections to their correction, verified here rather than repeated:

- **`Arg` is not another rule's business.** It sits in this rule's own narrowing list at line 42 beside
  `Closure`, `ArrowFunction`, `Function_`, `ClassMethod`, `Foreach_` and `ArrayItem`, so the hook set of seven
  was right; `Param` is an eighth node reached by iteration, not a replacement for one of them.
- **Ten php-parser classes carry `byRef`**, not eight: those eight plus `ClosureUse` and `PropertyHook`.
  Counted here.

**And the `&` has three homes, not one.** Their measured offsets: return-by-reference puts it after the
`function`/`fn` keyword and *before the name* — and a `Closure` has no name, so there is no operand child for
it to precede at all. A declared by-ref parameter puts it after any type hint and before the variable. A
by-ref value binding puts it before the value expression.

So one positional helper cannot serve the return-by-ref family, and **my "character immediately before the
operand's span" is wrong even for the family it was aimed at.** Their rows, each a real spelling:

    int & $p                  whitespace between & and the variable
    int &...$p                the variadic ellipsis sits between them
    int /* & not this */ &$p  a comment between hint and &, containing a decoy &
    ?array &$p                & at +7
    int|string &$p            & at +11
    private array &$promoted  promoted constructor parameter, & at +14

I predicted that a text search would fail and did not predict that adjacency would. What works is a
**backward token scan** from the operand's span start: skip whitespace, skip comment trivia, skip `...`, then
require `&`. The trivia store this repository already probed — `SourceFile::getTrivia()`, comment kinds with
spans — is what makes it possible. One such helper serves `Param`, `Foreach_` and `ArrayItem`;
return-by-reference needs its own, anchored on the keyword.

### What this exchange keeps demonstrating

Their line on it is the sharper one and worth keeping verbatim in substance: a tool that reports a lower
bound and a person who reports an unsurveyed one fail the same way, and **neither is caught by re-reading the
number.** My census's needs list is the first; their "not contrived" was the second. Both were real
measurements with the wrong scope, and both were caught by someone asking what the number was a number *of*.

### The rule's real surface: nine concerns, two out of scope, one unreachable

The peer verified my two corrections, found three more gaps in the process, and one of them this port cannot
close at all. Their rule-shape rows re-derived here against
`vendor/symplify/phpstan-rules/src/Rules/NoReferenceRule.php`, so the line numbers are this tree's:

| line | what it does |
|--:|:--|
| 47 | `$node instanceof AssignRef` → reports **unconditionally**, before any narrowing |
| 51 | narrows to `Closure`, `ArrowFunction`, `Function_`, `ClassMethod`, `Arg`, `Foreach_`, `ArrayItem` |
| 55 | `$node->byRef` on whichever of those seven arrived |
| 59 | the parameter half runs for **`Function_` or `ClassMethod` only** |
| 83 | `$param->byRef`, on `Param` |

So the surface is **nine** concerns: `AssignRef`, the seven narrowed kinds, and `Param`. My eight missed
`AssignRef` — it is not a `byRef` node at all, which is exactly why a list built by asking "which nodes carry
`byRef`" could not contain it.

**Two of the ten `byRef` carriers are out of scope**, and that is the rule's behaviour rather than an
omission: `ClosureUse` and `PropertyHook` appear nowhere in the file — grep count zero — so `use (&$a)` and a
by-reference property hook must be **silent**. And the line-59 gate means a by-reference parameter on a
*closure or arrow function* is silent while the same parameter on a named function or method reports. The
peer checked all four function-likes in one file; the asymmetry is real.

That is good news for the build: the parameter scan anchors on named functions and methods only.

### The bound this port cannot close, measured here

`Arg::byRef` is live only on source **PHP itself refuses**. Verified in this tree, with a control:

    php -l                          Parse error: unexpected token "&" on line 4
    php-parser                      parses, Arg byRef=true
    mago 1.47.6                     4 parse errors on line 4, no tree
    the same file without the `&`   mago: "No issues found"

Call-time pass-by-reference went out in PHP 5.4; php-parser is lenient and produces the node, so PHPStan
reports it. Mago's parser refuses the file, so there is **no tree for a plugin to inspect**. That is a
structural divergence rather than a defect in the port, and it is unreachable in any runnable project — the
same register as the operator-extension bound: real, stated in one line, no fixture possible. The control
matters here, because "mago reported errors" would otherwise be consistent with it rejecting the file for some
unrelated reason.

### Both of us produced a lower bound, one message apart

Mine was from a tool: the census's `needs:` list, which stops at the first expression-level blocker. Theirs
was from a fixture — their count of eight `byRef` carriers came from what their fixture happened to contain,
written to demonstrate the ampersand's three homes, which it did correctly. They then read a count off it that
it was never built to support, in the same message that told me a rule with no fixture is agreement on zero.

Their framing is the one worth keeping: **a probe answers the question it was built for and silently answers
every adjacent one wrong**, and no marking convention catches it, because the number *was* measured — just
not of the population quoted. This log's existing rule is about a value being right and answering a question
nobody asked; this is the same failure one level up, where the instrument is sound and the population is
substituted.

The one countermeasure of ours that would have caught either before a second reader did is the harness rule I
committed to: **do not report a rule as unchanged without a row that fires for it.** Aimed at the same
failure, and it generalises to counts as well as to verdicts — do not report a population without a row that
would have appeared had it been larger.

## The rule I needed was already in the census, enforced, with my own example

The peer proposed **enumerate from the closed set, not the open one** — `processNode` is closed and readable
to exhaustion, while "which nodes carry `byRef`" is an open grep over a dependency that gave them eight and
me ten. It is better than my axis rule and it subsumes both the census case and the `AssignRef` case, and
they marked it as analysis rather than measurement, which is why the next paragraph is worth more than
adopting it.

**Before adopting it I checked whether I already had it. I did.** `tests/Fixtures/expected/census.md`'s own
header, asserted line by line at `TracksUpstreamDriftTest.php:160-188`:

> **A needs list is a lower bound, and it is short in a direction rather than at random.** […] So a rule whose
> first blocker is expression-level under-reports, and every ranking built from these lists inherits that bias
> in the same direction.
>
> Measured, not deduced: `OverwriteVariablesWithForLoopInitRule` lists one need, and its body also asks
> `$scope->hasVariableType()`, which no SDK method answers. […] Where a count decides work, read the rules it
> is made of.

The bold sentence is the rule. The paragraph under it is the mechanism. **The worked example is
`OverwriteVariablesWithForLoopInitRule` hiding `hasVariableType()` — which I recorded earlier this session as
a finding, having re-derived it from scratch.** There is a further paragraph telling me to grep the whole line
rather than the label, and another telling me to grep a capability to count what it is worth before building.
All of it test-enforced. I read past every word three times.

### Why the enforcement could not reach me, and the one thing that fixes it

`needs:` exists in exactly one artefact, the census, whose header carries the caveat thirty lines above the
data. Every time I sized this session I ran a **Python extractor** over that file, pulling `needs:` lines by
regex. **A header cannot reach a parser.** That is the whole mechanism, and it is this log's own
scope-boundary entry arriving on the artefact rather than on a draft: the rule was enforced where a test could
see it and read by a route no test covers.

So the label now carries the bound. `needs:` is **`needs-at-least:`** in the census, because the key is what
an extractor sees. 193 lines renamed; with the label normalised the only other diff is the header sentence
saying why, which now states that every reader who has got this wrong read the file with an extractor.

**It costs something and the trade is worth stating**: nine more characters per line pushes 95 over-long lines
to 122 in a generated file. The bound travelling with the data is worth a wider column.

This is the only countermeasure from this whole exchange that reaches a machine rather than a reader, which by
my own record is the only kind that holds. The axis rule and the closed-set rule are both better *thinking*
than what I had; neither would have fired, because I had strictly better thinking than both sitting in a file
a test reads aloud.

### Where the closed-set rule stops, in their words and worth keeping

`processNode` is closed only if the rule does not dispatch elsewhere. `NoReferenceRule` calls
`collectParamErrorMessages` and the parent resolver, and the resolver's interesting behaviour was **not** in
its own code — the internal-parent gate and the first-class-like-per-file cache were one call further, in
`ReflectionParser`, and only came out of runs. So closed-set reading gives the *surface* reliably and says
nothing about the semantics of what the surface calls. Two jobs; the second still needs instruments.

Rector also caught a leftover from the previous commit while this was in flight: `MixedType` was still
imported in `RuleLevel` after `isMixed` moved to `AtomicShapes`. Removed.

### The same fix one layer out: the survey now says what its refusal is the scope of

The peer conceded their closed-set rule was a sixth reminder and reframed the rename in a way that is more
useful than my own account of it. Their version: in each of their four errors this week a **convenience proxy
sat closer to hand than the authoritative source and rendered indistinguishably in the output** — eight
carriers and ten look the same on the page. A reminder cannot separate them, because at the moment of writing
they are not in conflict; the proxy has already answered. So the fix shape is **change the cheap thing until
it is the right thing**, not add a rule against using it. Their test for whether an idea is worth anything: if
it tells a reader what to do it is a reminder and will fail; if it changes what an artefact says where
something consumes it, it is a fix.

Applied rather than agreed with, because I had the same defect one layer out from the one I fixed.
**`--survey` prints a single refusal and says nothing about it being the *first* obstacle.** I ran it dozens
of times this session and turned its output into two wrong sizings. It now prints, beside the count:

    each REFUSE is the first obstacle only, not what the rule needs — see `needs-at-least:` in
    tests/Fixtures/expected/census.md for the rest of a body

Only under `--survey`, and only when something refused. The precedent was already in that function: the
target is printed next to the count for exactly this reason, with a comment saying a number means nothing
without the configuration it belongs to.

It is asserted, in `StatesWhatSurveyAssumedTest` — whose own docblock already records a handoff that ranked
work from first obstacles and ranked it wrong. Dropping the line fails that test. **That assertion is the
whole difference between this and the census header**, which said the same thing in bold, with a worked
example, for longer, and was read past three times.

Their qualifier on my five-disciplines count is right and worth keeping: two of the five are not reminders.
A positive control changes the *run*, and marking inferred-versus-measured changes what the *artefact says*.
Those two fired. The other three, including both of ours from this week, addressed a reader and did not. So
the ratio is not five-for-nothing — it is that the two which altered an artefact worked, which is the rename's
lesson arriving a second time from a different direction.

### The third instance, found by sweeping rather than by being bitten

The peer checked my clause against their own case before agreeing and found something worse than they had
described: the artefact they said they would build **already exists**. `vendor/bin/phpstan diagnose` prints
the resolved extension versions, has for years, and they never ran it — through forty `analyse` runs, an
afternoon lost to a version confusion, and a paragraph to me proposing they add exactly that line.

That sharpens my clause rather than weakening it. *"Only a fix once something fails when it is removed"*
assumes the artefact is **on the route**. Their prior question is better: is anything I read the artefact at
all? Mine was a caveat off the route; theirs was a caveat in another building.

They also declined to ship my clause, correctly: the defect is in their invocation habit, not a repository
they own, and patching phpstan-src to print extension versions during `analyse` would be an unrequested change
to their user's tree for their own convenience. Recorded because a peer saying "by your standard I have a
reminder and I will not dress it as a fix" is worth more than a third agreement.

**And the transferable half is theirs, not mine.** Their phpstan-src guidance — after fixing one bug, look for
the same bug in adjacent code — applied to my own commit: I had the fix shape in that function, on the
target-and-count pair, and added the survey line only because a refusal had already been misread. Swept for
the pattern and the **third instance was three lines away**:

    emitted: 1, refused: 0 (target: php)
    an emit means the file was generated and every operand rendered — not that the plugin loads
    or reports; the fires gate is what establishes that

`EMIT` carried its configuration (the target) and not its scope, while *"it emitted is not a result"* is this
repository's single most-repeated finding — ten rules once emitted where six did not parse and two parsed
while still containing Rust. The warning has been in the guidelines throughout and was read past anyway,
which is the same route problem the survey line records.

Asserted, and dropping it fails. That is now three instances of one pattern in one function, all three
defended by tests, and the one that was found by sweeping is the only one that had not already cost something.

Suite 1080/1080, PHPStan 0 errors, Rector and Pint clean.

### `NoReferenceRule`'s first branch is gated by `checkMode`, and one attempt was reverted

The rule's first blocker is not the hook targets. It is line 47, `if ($node instanceof AssignRef) { return
[$this->createRuleError()]; }`, which reaches the **guard-exit** handler — a branch returning a finding is a
report, and that handler only knows exits.

The nearest existing shape is `delegatedCheck()`, which accepts `return $this->processX(..)` and hands the
branch's whole case to a helper that builds the error. Extending it to accept the same call wrapped in a
one-element array literal is two lines and semantically identical. **It moved the refusal by nothing, so it
was reverted.**

The reason is upstream of the shape: `delegatedCheck()` is reached only through `isBranchCheck()`, which
`translateIf()` consults only when `context->checkMode` is on, and `Transpiler::independentChecks()` requires
**two or more** independent checks to turn it on. It counts top-level `$x = $this->helperThatBuildsAnError()`
assignments plus `branchChecks()`. `NoReferenceRule` has neither shape at top level — its accumulation is
`$errorMessages[] = ...` and its one helper assignment calls `collectParamErrorMessages()`, which builds its
findings through `createRuleError()` rather than through `RuleErrorBuilder` directly.

So the piece is correct, contained, and unreachable for this rule, which is the fourth revert in this log made
under the same condition. Recorded rather than kept, because a two-line change that no rule can reach is
vocabulary a test cannot defend.

**What the next attempt has to decide first**, and it is a design question rather than a missing row: a
top-level branch that reports and returns is the same thing `isConditionalReport()` handles for the
`$errors[] = ..` spelling, and the honest options are to widen that recognizer to the `return [<error>]`
spelling or to widen what `independentChecks()` counts. The second changes `checkMode` for every rule and
would move emitted bytes across the corpus, so it is not a change to make while chasing one rule.

### Correcting the revert's stated cause, which was wrong in two ways

The peer raised a hypothesis about the previous entry, marked as reasoning from my description alone: the
counter and the recognizer might share a predicate, in which case my null result measured half of a coupled
change — I widened the recognizer while the count was still computed by the old predicate, so the flag stayed
off for the reason the widening was supposed to remove.

**The coupling is real.** `Transpiler::branchChecks()` calls `Translator::isBranchCheck()`, which calls
`delegatedCheck()` — the function I widened. So they are one predicate, and the objection was correct in
form.

**But it is not what my null result was about, and my stated cause was wrong twice.** Instrumented at both
ends:

- `OperandsInArithmeticDivisionRule` — which **emits** — reports `independentChecks assignments=0 branch=0
  total=0`. So `checkMode` is off for a rule that emits, and "the path is gated by `checkMode`" was never a
  sufficient explanation of anything.
- `NoReferenceRule` — the `independentChecks` trace **never fires at all**, while a backtrace at the throw
  site shows `Cli::run → Transpiler::transpile → translate → translateOrCollect → translateStatement →
  translateIf → guardExit`. That is the loop three lines *below* the line that sets `checkMode`, so the
  setter should have run. Re-applying the widening with the instrumentation still in place changed nothing.

So: the coupling exists, the refusal is not explained by it, and **I do not know why the line that sets
`checkMode` does not execute for this rule.** Stated that way on purpose. The previous entry asserted a
mechanism — "this rule has none of the counted shape" — that the measurement does not support, which is the
*wrong why* this log forbids, committed one entry after recording a peer's version of the same error.

The revert itself stands: the widening moves no refusal, so it is unexercised either way. What does not stand
is the reason I gave for it.

**What the peer's framing got right beyond the specific hypothesis** is the part worth keeping: a null result
is the easiest kind to accept without asking what it was a null result *of*, and four reverts under one
condition suggests testing the condition rather than the changes. I accepted my own null result and explained
it, instead of measuring it. The measurement took three commands and refuted my explanation twice.

### The measured answer, and my retraction was wrong

The previous entry says I do not know why the `checkMode` setter is skipped for `NoReferenceRule`. Measured
now, with a trace on each side of the setter and a control that emits:

| configuration                              | assignments | branch | `checkMode` | refusal |
|:--|--:|--:|:--|:--|
| HEAD                                       |     —       |   —    | never computed | line 47 |
| survey path also sets it                   |     0       |   0    | false          | line 47 |
| survey path sets it **and** the widening    |     0       | **1**  | false (needs ≥2) | line 47 |
| `OperandsInArithmeticDivisionRule` (control)|     0       |   0    | false          | **emits** |

Three findings, and two of them correct my own last two entries:

- **`translate()` has two body loops.** The survey's assume-a-hook branch at `Transpiler.php:236-252`
  translates `processNode` in **its own loop and returns early**, never reaching the setter three dozen lines
  below. That is why no trace fired, and it is a real, second divergence between survey and emit that nothing
  recorded — the branch's own docblock argues that *the assumption has to travel with the answer*, and the
  flags did not travel.
- **My original cause was right and my retraction was wrong.** With the setter running, the count is **0**.
  "This rule has none of the counted shape" was accurate; I withdrew a correct explanation because I could
  not see a trace, and the reason I could not see it was a different fact entirely. Retracting under
  uncertainty is better than asserting under it, but the retraction was published with the same confidence
  the original had.
- **The peer's coupling is real and operative — their *mechanism*, not their prediction.** Widening
  `delegatedCheck()` takes `branch` from 0 to 1, so the counter and the recognizer are one predicate exactly
  as they guessed from my description alone. But they predicted the widening "could flip `checkMode` on",
  and 1 against a threshold of 2 flips nothing: the consequence was refuted by this same table. Recorded that
  way because writing "hypothesis confirmed" would credit a prediction my own measurement killed — a count
  quoted a notch wider than what was run, which is the failure this whole thread is about, committed by me
  while crediting someone else. Their correction.

**Both changes reverted.** The survey-path setter alters no census line and no refusal, so nothing can defend
it — and by the discipline this session established, an artefact change is a fix only once something fails
when it is removed. It is recorded here instead, because the next person to touch that branch should know the
flags do not travel.

What actually blocks the rule is the **threshold**, not the recognizer: two counted checks, and this rule has
one even after the widening. Raising it is the corpus-wide change I already declined to make while chasing one
rule, and that decision is unchanged.

The peer declined to guess at the cause on the grounds that handing me a hypothesis in place of a measurement
would repeat my own error with a worse-informed author. That was the right call and it is why this table
exists: nobody supplied an explanation, so I had to go and get one.

### The threshold is a compatibility guarantee, and it costs 13 rules to lower

I twice declined to raise what counts as an independent check "while chasing one rule", which was a judgement
rather than a reason. The reason is written down at `Transpiler.php:1382`, in `independentChecks()`'s own
docblock:

> Counted before translation because the answer decides how the whole body is emitted, and **a rule asking one
> check must emit what it emits today.**

So the `>= 2` is a deliberate compatibility line, not an arbitrary number: at exactly one check the body must
be emitted the old way. Which makes the operative question how many rules sit at one — a vacuous guarantee
would make the change free.

**Measured across all 246 transpile attempts in the seven installed packages:**

| independent checks | rules |
|--:|--:|
| 0 | 226 |
| **1** | **13** |
| 2 | 4 |
| 3 | 3 |

Not vacuous. Thirteen rules sit at exactly one, and lowering the threshold changes how each of their bodies is
emitted — a thirteen-rule diff for a change made to advance one. The decision is unchanged and now it has a
figure behind it rather than a preference.

The distribution is worth keeping for its own sake: **226 of 246 rules have no independent checks at all**, so
`checkMode` is off for the overwhelming majority and `NoReferenceRule` sitting at 0 — 1 with the widening — is
typical rather than unusual. A reading of `checkMode` as the normal path would have been wrong in the other
direction.

### And one correction to how I credited the peer

My previous entry said their coupling hypothesis was confirmed. Their own correction: the *mechanism* held —
one predicate, and widening it moves the count — but the *consequence* they predicted, that it "could flip
`checkMode` on", was refuted by the same table, since 1 against a threshold of 2 flips nothing. Writing
"hypothesis confirmed" credited a prediction my own measurement killed, which is a count quoted a notch wider
than what was run: the failure this entire thread is about, committed by me while crediting someone else.
Narrowed in place.

## Two rules emit: a constructor-derived class handle, and a name-to-name subclass test

`CombinedStaticCallRule` and `StaticChainedNoDebugInNamespaceRule` emit. The census takes
`hihaho/phpstan-rules` to 7 of 8, php 180 → 182, `--status` to 121 of 210.

Found by reading the distribution measured one entry earlier rather than by picking a rule: seven rules run
with `checkMode` on, and `CombinedStaticCallRule` was the only one of them refusing while its siblings
`CombinedMethodCallRule` and `CombinedFuncCallRule` emit — a family whose shape is already proven portable,
with one member held by one blocker.

### Two pieces, the first of which the translator's own docblock had already named

- **A class handle is not the service it came from.** `$this->facadeReflection =
  $provider->hasClass(Facade::class) ? $provider->getClass(Facade::class) : null;` was recorded as the
  *provider*, because `serviceBehind()` finds any injected service anywhere in the expression and the provider
  is in it. Hence the refusal *ClassReflection test on a service*, whose message the translator already
  attributed to "the facade reflection two debug rules take in their constructor".
  `Transpiler::classHandleBehind()` recognises the shape first and records the class it names, and the
  property then resolves as a `named-class` — which the existing `instanceof ClassReflection` path already
  turns into a `classExists()` test.
- **`isSubclassOfClass()`** is the same question `isSubclassOf()` maps, with a handle on both sides instead of
  a name on the right. `Reflect::namedClassIsSubclassOf()` answers it by ancestry, case-insensitively because
  `getClassAncestors()` returns lowercase — a fact this runtime has been bitten by before.

The emitted condition is the shape intended: `classExists($context, 'Illuminate\Support\Facades\Facade')` for
the null guard, and `namedClassIsSubclassOf($context, resolvedName(classPart($node)), '…\Facade')` for the
test.

### The fixtures had to reach a branch the prefix check hides

Both rules ask first whether the called method's declaring class starts with `Illuminate\`, and only then
whether the class descends from the facade base. A fixture using a real Laravel facade takes the *first*
branch and says nothing about the second. So each pair uses a **project** facade declaring its own `dump()`:
the declaring class is `App\…`, the prefix check is false, and the subclass test is what decides.

Both gates pass — PHPStan reports the bad example, the plugin reports it, the good example is silent on both.

### One mutation direction is unpinned, and the instrument cannot say why

| mutation                                        | result |
|:--|:--|
| subclass test always **false**                  | 4 failures |
| case-**sensitive** compare (ancestors are lowercased) | 4 failures |
| subclass test always **true**                   | **passes** |

So the helper is load-bearing for the reporting path, and the lowercase fact is load-bearing. The negative
answer is not pinned: no fixture distinguishes a stub that always says "yes". Moving the non-facade control
into its own file did not change that.

**And I could not trace it.** A `fwrite(STDERR)` inside the helper produced no output during the gate, which
I first read as "the helper is never called" — impossible, since removing its loop fails four tests. The
plugin runs in a **mago worker subprocess**, so its stderr never reaches PHPUnit. That is an artefact of the
instrument, not a fact about the code, and it is the same class of error this log has recorded repeatedly:
the first reading of a null observation was wrong, and the control that caught it was an earlier measurement
that contradicted it.

Recorded as a named gap rather than left implicit: the true path is pinned, the false path is not, and the
reason the usual probe cannot reach it is the worker boundary.

Suite 1088/1088, PHPStan 0 errors on 13 baseline entries with no new one, Rector and Pint clean. Emit-all:
only the two new plugins and the three registration files move.

### And the tenth displaced docblock, mine, caught by its consequence

Inserting `$classHandles` above `$refinements` in `TranslationContext` took that property's `@var` with it.
PHPStan caught it — not as a misplaced comment, but as **four type errors** in the code that reads
`$refinements`, which became untyped. One cause, two symptoms, and the symptoms are what made it visible.
Nine of the previous instances were found by reading; this one announced itself, because the stolen docblock
carried a type something depended on.

### A session-wide delta I could not stand behind, and the parser that hid it

Reporting the state after the two rules above, I reached for a headline: the census at this session's first
commit against the census now. `grep -c '^EMIT'` gave **58** then and **131** now, a delta of 73 that no
amount of work in this context window accounts for.

Checked rather than published. Two things were wrong with the framing and one with the instrument:

- **The session is 368 commits.** Most of it precedes this context window, so a session-wide delta is not a
  claim about work I can name. The per-package figures quoted throughout — each cross-checked against the
  census at the time — are sound; the headline is not mine to make.
- **The header format changed mid-session.** It read `N of M the package registers emit` and now reads
  `N of M portable rules the package registers emit`. My reconciliation regex was written against the current
  format and returned **0** against the old file — a parser silently reading zero from a file whose shape
  moved, which is the same class as the double-included neon that analysed nothing. Summed by hand instead:
  **52** package-rule emits then, **121** now.
- **58 against 52** is the local `tests/Fixtures/Rules` fixtures, which have no package section and so appear
  as `EMIT` lines with nothing in the headers to match them. Two correct counts of two different populations,
  which is this thread's whole subject.

Recorded because the near-miss is instructive: a generated file's *format* is as much a version as its
contents, and an extractor pinned to today's shape reads old snapshots as empty rather than as unparseable.
The `needs-at-least:` rename earlier in this session changed that same file's keys — so any extractor written
against it before that commit now reads zero `needs:` lines from every later snapshot, and would report a
corpus with no needs at all.

## The search is saturated, measured four ways

Recorded so the next session does not repeat the search. Four independent cuts at the 48 remaining refusals,
each one made because the previous one came back empty:

1. **Primary blockers, ranked.** The largest family is four rules on a `Node::class` hook narrowed by
   `instanceof`. One is a collector, one takes its kinds from a value known only at analysis time, and two
   carry three or more further needs.
2. **The thirteen refusals stating one need.** Each checked at the source; none is one capability away.
3. **Named capabilities, counted rather than assumed.** `describe(VerbosityLevel::value())` has exactly one
   dependent, itself multiply blocked. `->no()` has three, each blocked elsewhere — including one that turns
   entirely on definedness, which is merged upstream and unreleased.
4. **All needs by full text**, which is the only valid grouping and the census header says so. The top entry
   touches seven rules and dissolves on inspection: four are `*TypeDeclarationCollector`s from
   `tomasvotruba/type-coverage`, and **exactly one need is shared by all four** while each carries four to six
   unique ones. A shared wrapper, not a shared capability.

The distribution of `needs-at-least` per refusal, which is the summary figure worth keeping:

| needs | refusals |
|--:|--:|
| 1 | 13 |
| 2 | 9 |
| 3 | 5 |
| 4–7 | 19 |
| 9, 14 | 2 |

Read with the bound the label now carries: these are lower bounds, and the thirteen at one were each verified
to hide more. **No remaining rule in the installed corpus is one capability from emitting.**

### The cross-cutting residue, which is where leverage would be if there were any

Needs touching three or more rules, grouped by full text:

    7  guard body is neither `return []` nor `continue`, but Stmt_Expression
    5  statement outside the vocabulary: Stmt_Expression
    5  guard body is neither `return []` nor `continue`, but Stmt_Return
    4  $errorMessage is not a message built in this rule
    4  collector returns something other than a list of values
    4  array_merge() as an access path
    4  Expr_Ternary as an access path
    3  ->getLine()   ·   3  $scope->getTraitReflection()   ·   3  an accumulator as a message argument

Every one of them advances rules without emitting any, and vocabulary that changes no emitted byte is the
condition five reverts in this log were made under. So the residue is a list of things worth building **when a
rule needs them**, not a queue.

### What the seams have actually produced

Two rules this context window (`CombinedStaticCallRule`, `StaticChainedNoDebugInNamespaceRule`), both from
the `checkMode` distribution rather than from any needs ranking — a rule refusing while two siblings of the
same family emit. **That is the shape that worked and the one to look for first next time:** not the smallest
need list, but a member of a family whose shape is already proven.

The three remaining seams are named rather than guessed: an upstream release for definedness, a six-piece
aggregation rule (`SlowMigrationDdlRule`), and a `checkMode` threshold whose cost is thirteen rules' emitted
bytes.

### The one gap in the search, closed: `spaze/phpstan-disallowed-calls`

That package has 38 rules and **no census section**, so none of the four cuts above covered it. Checked
rather than assumed to be covered by the header's prose:

    survey     emitted: 14, refused: 24
    emit run   emitted:  0, refused: 38

The survey figure is the assume-a-hook path and the emit run is the truth, which is what the header already
records as *0 of 38* against *a survey says 14*. The refusal distribution on the real run:

    13  could not find the reported message
     3  $disallowedCalls is computed in the constructor and the package wires no configured values
     …  the rest one apiece, mostly missing hooks for statement kinds

So the package is a measured dead end, not an unexamined one. The thirteen are the documented case: the
message is built by an injected `DisallowedKeywordRuleErrors` over a keyword list the package wires nowhere,
and both ways past it are wrong — step over the filter and the plugin reports every `break`, carry an empty
list and it reports nothing.

**And the twelve hook rows the header describes are not in the tree.** `Stmt\Echo_`, `Break_`, `Goto_`,
`Return_` and `Unset_` all return zero from `Vocabulary`. They were added, measured to move the emit run by
zero, and reverted — the same discipline five reverts in this log follow. The header says *"twelve rows added
to `HOOK_KINDS` … moved the emit run by zero"*, which is true of the experiment and reads as true of the
current state. One clause would fix it, and it is the same failure this whole session has been about: a
sentence read a notch wider than what it says.

### Where that leaves the work

Nothing in the installed corpus is one capability from emitting, now including the package that had no
section. The four remaining routes are all outside what I can do unilaterally or cheaply:

- **An upstream release** for definedness — `carthage-software/mago#2334` closed completed 2026-09-07, newest
  release 1.47.6 from 2026-09-04.
- **A six-piece rule** — `SlowMigrationDdlRule`, whose blocker is a sorted findings aggregation over tuples.
- **The `checkMode` threshold** — measured at thirteen rules' emitted bytes.
- **A new corpus package**, which is a dependency this repository's own guidelines say not to add without
  approval, and which would change the denominator every figure here is quoted against.

### Re-sizing `SlowMigrationDdlRule`: the aggregation may be zero pieces, not six

I called this rule six pieces on the strength of its first blocker, a findings aggregation:

    $found = [...$this->inspectSchemaCalls(..), ...$this->rawAlterFindings(..)];
    usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    return array_map(static fn (array $f): IdentifierRuleError => $f[1], $found);

That was sized from the shape rather than from what the shape does. The tuples carry a line number for one
purpose — the sort — and the `array_map` exists only to unwrap them again. A plugin reports each finding at
its own span, so all three steps are about *ordering a list*, which a plugin does not have.

**And ordering is not observable through the gate**, which is the part worth checking rather than assuming:
`FiresGate::sorted()` sorts the findings of each file and `ksort`s the files before comparing, so both sides
are order-normalised. A rule's own `usort` cannot show up as a disagreement.

So the aggregation is plausibly zero pieces and the rule is its five reported shapes, a `NodeFinder` over the
class, the `MigrationTableNameResolver`, and configuration it is inert without. Still substantial; not what I
described.

**One caveat, and it is the one that stops me acting on this now.** "My harness cannot detect it" is the exact
reasoning I have been wrong with twice this week — the always-true mutation that passed because no fixture
reached the axis, and the null result I explained instead of measuring. Whether PHPStan itself preserves the
order a rule returns, and whether any consumer depends on it, is not a question my repository can answer. Put
to the peer, with the standing instruction that if the honest answer is "someone would have to check", the
sort stays load-bearing until they do.

### Q1 settled: PHPStan discards the order a rule returns, so the sort is decoration

The peer answered from source and two of the three claims re-derive here, in this tree's phar:

- `src/Analyser/AnalyserResult.php` names the field **`unorderedErrors`** — seven occurrences — and carries
  two `usort` calls.
- The sort key, read out verbatim: `[$a->getFile(), $a->getLine(), $a->getMessage()] <=> [...]`. **The
  tertiary key is the message**, so two findings on one line come out alphabetised by message text whatever
  order the rule returned them in. A rule that sorts carefully does not control output order; PHPStan
  overrides it.
- `src/Testing/RuleTestCase.php` carries three `usort` calls, so the rule's own upstream tests cannot observe
  order either.
- Their third claim — that `AnalysisResult`'s constructor sorts again and every formatter consumes it — is
  **not verified here**: no file at that path in the phar. Recorded as theirs rather than as mine.

`unorderedErrors` plus a sort keyed on the message is decisive on its own, so `SlowMigrationDdlRule`'s
`usort` is droppable and the aggregation is about ordering a list that nothing downstream preserves.

**And the peer corrected my reasoning while confirming my conclusion**, which is the more useful half. I was
about to justify dropping the sort on "my harness cannot see it", and flagged that as the reasoning I have
been wrong with twice this week. The sound ground is different: the invisibility is **upstream** of my
harness. PHPStan destroys the order itself, so `FiresGate::sorted()` agrees with something PHPStan already
does rather than hiding a divergence. Same answer, and the distinction is exactly the one that would have made
me wrong for the third time.

### Q2 answered with a table rather than an opinion: the case for a dependency is weak

The peer classified every rule package on their disk, and stated the population first: an **availability
sample of 14 packages that happen to be installed there**, not a survey of the ecosystem.

| package | rules | collectors | literal message | zero-arg ctor |
|:--|--:|--:|--:|--:|
| symplify/phpstan-rules | 96 | 1 | 73 | 73 |
| phpstan/phpstan-strict-rules | 42 | 0 | 41 | 17 |
| spaze/phpstan-disallowed-calls | 38 | 0 | **0** | 0 |
| **larastan/larastan** | **18** | 8 | **15** | **8** |
| phpstan/phpstan-phpunit | 14 | 0 | 8 | 6 |
| tomasvotruba/unused-public | 5 | 13 | 0 | 0 |
| tomasvotruba/type-coverage | 5 | 5 | 0 | 0 |
| phpstan/phpstan-nette | 3 | 0 | 3 | 2 |
| phpstan/phpstan-mockery, phpstan-webmozart-assert, tomasvotruba/class-leak | 0 | 0 | 0 | 0 |

**Everything large is already in my corpus** — symplify, strict-rules and spaze are the three biggest and all
three are mine. The only genuinely new pool on their machine is **larastan: 18 rules, 15 with a literal
message, 8 with no constructor dependencies**, which is exactly the portable shape, plus 8 collectors that are
not. After that it is `phpstan-nette` at 3. The official `phpstan-*` bridges are type extensions, not rules:
mockery and webmozart-assert have **zero** rule classes.

Two corrections to what I had recorded:

- **spaze is 0 of 38 with a self-contained message, not thirteen.** The injected-message pattern is the entire
  package, so the dead end is all 38 rather than the third I had measured from its refusal distribution.
- **Their own first pass was wrong in this week's characteristic way**, and they said so: counting only
  `message(sprintf(` and string literals scored symplify at 14 of 96, because symplify's dominant style is
  `message(self::ERROR_MESSAGE)`. One spelling measured and reported as the concept. Re-run with the constant
  counted gives 73, matching the 73 zero-argument constructors exactly.

Two packages they named as **unread rather than recommended**: `ergebnis/phpstan-rules` and
`shipmonk/phpstan-rules`. Neither is on either machine, and the only thing sayable is that both are rule
packages rather than extension bridges.

**This is a decision for the user, not for me or the peer.** Adding a corpus package is a dependency, which
this project's guidelines gate on approval, and it moves the denominator every figure in this file is quoted
against. The measured case: larastan is worth about 18 candidate rules in the portable shape; everything else
locally checkable is already mine, a collector package, or not a rule package at all.

## Why every ranking of the needs list dissolves: it groups by syntax and blocks by capability

The `array_merge()` need touches four rules and the spread in `SlowMigrationDdlRule` is the same concept, so
it looked like one capability for five. Read at the source:

    NoReferenceRule              array_merge($errorMessages, $this->collectParamErrorMessages(..))   own method
    ClassCoversExistsRule        array_merge($errors, $this->coversHelper->processCovers(..))        service
    ClassMethodCoversExistsRule  array_merge($errors, $this->coversHelper->processCovers(..))        service
    DataProviderDeclarationRule  array_merge($errors, $this->dataProviderHelper->processDataProvider(..))  service

**Three of the four merge an injected service's result**, so the merge is the stated blocker and the real work
is porting `CoversHelper` or `DataProviderHelper`. The fourth merges an own method and is blocked upstream by
the `checkMode` threshold anyway. The merge capability alone unlocks nothing.

That is the fifth promising grouping to dissolve on inspection, and they all dissolve the same way, which is
the part worth generalising:

**The needs that group are the ones describing *syntax*; the needs that block are the ones describing
*capability*.** Of the twelve needs touching three or more rules, the top eight are wrappers —
`Stmt_Expression`, `Stmt_Return`, `Expr_Ternary`, `array_merge()`, `2 statements: Stmt_Expression +
Stmt_If`, `assignment to something other than a simple local`. A wrapper appears wherever the syntax appears,
which is everywhere, and says nothing about what is behind it. The things that actually stop rules — an
injected message service, a configured list the package wires nowhere, definedness, a threshold whose cost is
thirteen rules' bytes — appear once or twice each and never rank.

So **no ranking of this list can find leverage**, and that is a property of the list rather than of my four
attempts. It explains all five dissolutions at once and it predicts the sixth. Two entries at count three are
genuine capabilities — `->getLine()` and `$scope->getTraitReflection()` — so the rule is a dominant pattern
rather than a law; but the pattern is strong enough that the census's own advice, *read the rules a count is
made of*, is the only method that works here, and reading them is what found both rules that landed this
window.

The corollary for the shape that did work: `CombinedStaticCallRule` was not found by ranking anything. It was
found by asking which rules sit in a family whose shape is already proven and which member is held back. That
question is answerable from the emit/refuse split rather than from the needs list, and it is the one to ask
first.

## Running my own corollary, and a correction to the saturation claim

The corollary said: ask which rules sit in a family whose shape is proven and which member is held back,
answerable from the emit/refuse split rather than the needs list. Run systematically over every source
directory:

| family | emit | refuse |
|:--|--:|--:|
| symplify `Rules/Symfony` | 20 | 2 |
| symplify `Rules` | 14 | 7 |
| symplify `Rules/PHPUnit` | 9 | 1 |
| symplify `Rules/Rector` | 8 | 3 |
| symplify `Rules/Doctrine` | 8 | 1 |
| strict-rules `BooleansInConditions` | 6 | 2 |
| strict-rules `VariableVariables` | 6 | 1 |
| symplify `Rules/Complexity` | 4 | 2 |

`BooleansInConditions` looked strongest — six siblings emitting and the two refusals being `&&`/`||` operator
variants, the shape that made the arithmetic family work. It is not: both need seven things including
`$node->getRightScope()`, a per-operand scope on a PHPStan *virtual* node, which a plugin does not get.

### The correction: one rule **is** one capability away

`NoJustPropertyAssignRule` sits in a family where four emit, and its single need traces to one concrete
capability. So *"nothing in the installed corpus is one capability from emitting"*, recorded twice above, is
**too strong** — and it was reached by ranking needs, which the entry above establishes cannot find leverage.
The corollary found in one pass what four rankings missed.

The rule asks `$varName instanceof Expr` where `$varName = $variable->name`. php-parser types a variable's
name as `string|Expr`, so the question is *is the name computed*. In Mago that is the variable's own node
kind: a written `$x` is a `DirectVariable`, `$$x` and `${expr}` are `IndirectVariable` and `NestedVariable`.
`HOOK_KINDS[Variable::class]` already registers all three and its docblock already says a rule asking
`is_string($node->name)` is asking which of them fired — so the semantics are settled and recorded.

**What stops it is provenance, not semantics.** Attempted and reverted: a branch answering `instanceof Expr`
on a `bytes` subject by testing the base's node kind works only when the tested expression *is* the property
fetch. Here it is a local assigned from one, so the name's descriptor has to carry the node it came from.
The descriptor shape already has an `of` field for exactly this kind of provenance — used to carry a source
operand for constant-string reads — so the change is to set it where a local is assigned a variable's
`->name` and read it in the `instanceof` branch. That is the local-assignment path rather than one edit,
which is why this is recorded rather than half-built: sixth revert in this log, same condition.

### What the two entries together say

Ranking the needs list cannot find leverage, because it groups by syntax. Ranking the **emit/refuse split by
family** found the two rules that landed this window and has now found a third that is genuinely one
capability away. That is the method to use, and the needs list is for reading a candidate once the family
has nominated it.

### `NoJustPropertyAssignRule`: both shortcuts closed, by measurement

The previous entry said threading provenance is "the local-assignment path rather than one edit" and left it
there. That was an assertion, so both halves of it are now measured.

**Is it one edit?** No, and the reason is structural rather than a matter of effort. The local writes at
`Translator.php:5615-5677` are helper-call *inlining*, not plain assignment, and a plain `$x = $y->name`
has no single write site: `expr instanceof Assign` appears at 2342, 2633, 2674, 2687, 2822, 2755, 2950,
3738 and on, each a different shape recognised in its own place. There is no one line where a local's
descriptor is minted from a resolved value, so `of` cannot be set in one.

**Is there a route that needs no provenance?** No, and this is the more useful half. The rule guards with
`isLocalPropertyFetchAssignToVariable()` before reaching the test, and I expected that guard to have already
narrowed the target to a *written* variable — which would make `$varName instanceof Expr` provably false and
foldable with a stated reason. It does not. The guard requires php-parser's `Variable`, and php-parser's
`Variable` covers all three of Mago's kinds:

    $x  = $this->service;      DirectVariable     name is a string
    $$x = $this->service;      IndirectVariable   name is an Expr
    ${$k} = $this->service;    NestedVariable     name is an Expr

So `$$x = $this->service;` passes the guard, reaches the test, and PHPStan answers **true** there and stays
silent. Folding the test to false would make the plugin report it — a false positive on a construct that is
rare but legal, which is exactly the *plausible-but-wrong rule* this repository's refusal invariant exists to
prevent.

Both shortcuts closed, so the capability is what it is: the name's descriptor has to carry the node it came
from. That is worth doing when a second rule wants it, and it is now characterised well enough that whoever
does it starts from the design rather than from the survey.

**And the pattern in my own work is worth naming.** I dismissed the provenance route as "not one edit"
without looking, then measured it and was right; I assumed the guard narrowed the variable, then measured it
and was wrong. Same session, same kind of claim, opposite outcomes — which is the argument for measuring both
rather than for trusting the instinct that happened to be right.

## The computed-name test, built — and kept, which is a departure from five reverts

`NoJustPropertyAssignRule` still refuses, but on `$this->phpDocResolver->resolve()` at line 90 rather than on
`instanceof Expr on a bytes` at line 81. The capability is built and the change is kept rather than reverted.
Both halves need justifying.

### The provenance was one edit after all, and I had looked in the wrong place

The previous entry concluded that carrying the owner node meant the local-*assignment* path, which is diffuse
— `expr instanceof Assign` is recognised at eight or more separate sites. That was true and irrelevant.
Descriptors are **created** in `resolve()` and a local stores whatever it returned, so the field rides along
through whichever write path applies. Instrumenting the refusal printed the descriptor and settled it:

    kind  bytes
    key   $node->expr->var->name
    php   Support::constantNameText(Support::nthExpression($context, Support::nthExpression($context, $node, 0), 0))

The owner node was already in the operand, wrapped by `constantNameText`, at a single creation site — `->name`
on a plain `expr`, `Translator.php:13295`. Setting `of` there is one line, and it uses a convention the
descriptor shape already has for constant-string reads. **I had reasoned about where a value is stored
instead of where it is made.**

### Why it is kept when five comparable changes were reverted

Emit-all is byte-identical across all three targets and the counts are unchanged at 182, 34 and 25. So by the
usual test — does it move an emitted byte — this is unexercised vocabulary and the five previous reverts
apply.

It is kept because it moves something else that is pinned. The census now records:

    - no node predicate for instanceof PhpParser\Node\Expr on a bytes
    + assignment value outside the vocabulary: access path outside the vocabulary: $this->phpDocResolver->resolve()

That is the census header's own stated value — *a refusal naming the wrong obstacle is how work gets sized
wrongly* — and the drift test pins the new message, so removing the capability fails a test. The five reverts
were changes that moved **nothing observable at all**; this one is defended by a snapshot. The distinction is
between "no rule uses it" and "nothing can detect it", and only the second is undefendable.

### What the rule needs now, and it is not small

`shoulSkipMoreSpecificTypeByDocblock()` resolves the statement's doc comment through an injected
`PhpDocResolver` and compares the `@var` tags against the assigned expression's type. Reading the docblock
text is reachable — the peer confirmed `SourceFile::getTrivia()` returns comment trivia with spans — but
resolving `@var` into a type and comparing it is PHPStan's own machinery, not a navigation. So the rule is
behind a service port, which is where three of the four `array_merge` rules also sit.

**And the shortcut is still closed for the reason recorded last entry**: folding the docblock skip away would
report where PHPStan stays silent, because the skip exists precisely to suppress a finding.

Suite 1088/1088, PHPStan 0 errors on 13 baseline entries with no new one, Rector and Pint clean. The branch
was extracted to `computedNameTest()` rather than left in `instanceofPredicate()`, which is already baselined;
that method still drifts 128 → 131 and the class 2643 → 2647, both patched in place.

## `NoTestMocksRule`: the best-characterised candidate left, and a note I misread on the way

The family method nominated it — `symplify/Rules/PHPUnit` has nine emitting and one refusing.

### First, a note I took for a refusal

The census line reads `REFUSE  NoTestMocksRule  (the package registers it nowhere)` with no refusal text, and
I read the parenthetical as the blocker. It is a **note**: the rule's constructor is
`__construct(private array $allowedTypes = [])`, one parameter with a default, so nothing needs wiring and
registration is not what stops it. I had already started reasoning about narrowing the unregistered-rule
policy, and had measured that of the nine rules carrying that note **exactly one** has an all-defaulted
constructor — a real measurement aimed at the wrong question.

Surveying the rule directly gives the actual refusal: `access path outside the vocabulary: Expr_New (line
82)`. Reading the rule rather than the census is what corrected it, which is the census's own instruction and
the third time this session it has paid.

There is also a precedent worth recording against the policy: `StaticChainedNoDebugInNamespaceRule`, which
emits, is *also* unregistered by its package. So the note describes discovery, not translatability.

### What actually blocks it, and how close the machinery already is

    private function resolveMockedObjectType(MethodCall $methodCall, Scope $scope): ?ObjectType
    {
        $variableType = $scope->getType($methodCall->getArgs()[0]->value);
        foreach ($variableType->getConstantStrings() as $constantStringType) {
            return new ObjectType($constantStringType->getValue());
        }

        return null;
    }

The `ObjectType` wrapper dissolves, the same way `getClass(Facade::class)` did two entries ago: everything the
rule does with it is `instanceof ObjectType` (was there a constant string), `getClassName()` (the string
itself) and an allow-list compare. **And most of that is already built** —
`Translator::objectTypeName()` reads `new ObjectType(..)` written inline, and
`bindConstructedObjectType()` at `:9243` handles the *assignment* form, with a docblock already saying "there
is no `ObjectType` at runtime here, only the class name the comparison needs".

Three pieces, in order:

1. `return new ObjectType(<x>)` from an inlined helper — the same recognition `bindConstructedObjectType()`
   applies to an assignment, applied where a helper hands the value back.
2. `foreach ($type->getConstantStrings() as $s) { return ..; }` — a loop that returns on its first item, which
   is "the sole constant string" rather than an iteration.
3. `$s->getValue()` — the string off that.

Not started, and that is a judgement rather than a blocker: three pieces at the end of a long session is how
the previous six reverts began, and this candidate is now characterised well enough that starting it fresh
costs nothing. The machinery to extend is named, the collapse is the one already proven twice, and the family
has nine emitters vouching for the shape.

## `NoTestMocksRule` emitted, and the plugin was wrong — reverted

Six blockers cleared in sequence and the rule emitted. The emitted plugin was **wrong**, the fires gate caught
it, and the whole attempt is out. This is the clearest instance in this log of *"it emitted" is not a result*,
and it is worth recording in full because everything up to the last step looked right.

### The six, each small and each real

1. `return new ObjectType(<name>)` read as a value — `objectTypeName()` already read the inline form and
   `bindConstructedObjectType()` the assignment form, so this was the third spelling.
2. `instanceof ObjectType` on that descriptor — the construction is conditional in every rule that writes
   one, so the test is whether there was a name to construct from.
3. **`takeDeclaredDefault()`** — a parameter the neon does not wire still has a value when the constructor
   declares one, and that is what PHPStan uses for a consumer registering the rule with no arguments.
   `array $allowedTypes = []` is the case.
4. `->getClassName()` on a constructed type — the identity, beside the `getValue()` arm that already does the
   same for a constant string.
5. `isInstanceOf()` on a constructed type against a runtime name — both sides are names, so ancestry.
6. `Reflect::namedClassIsInstanceOf()` — the **inclusive** sibling of `namedClassIsSubclassOf()`, because
   PHPStan's `isInstanceOf` counts the class itself and `isSubclassOf` does not. Two helpers rather than one
   with a flag, for the reason the docblock states.

I also caught and fixed a defect of my own along the way: the emitted `@param` docblock said
`PHPStan's %allowedTypes%` for a value that came from the constructor. That line is **read by
`tests/Support/ConsumerParameters`** to map a plugin's arguments back to PHPStan parameters for differential
runs, so naming a parameter nobody declares would send that lookup after nothing. Declared defaults now get
`the rule's own constructor default`, which its regex deliberately does not match.

### What was wrong, and why nothing before the gate could see it

    foreach (Support::constantStringsOf(Support::expressionType($context, $arg_value)) as $constant_string_type) {
    }

    if (!($constant_string_type !== null)) {

**The loop body is empty.** The rule's `return new ObjectType(..)` sits *inside* the `getConstantStrings()`
walk, and my change made that return resolve to a descriptor without emitting a statement — so the loop binds
a variable, does nothing, and the code after it relies on PHP leaving the loop variable set. On a file where
the loop never runs, that reads an **undefined variable**, and the test then falls the wrong way.

Every check before the gate passed: it parsed, `php -l` was clean, no Rust leaked, every `Support::` helper
existed, and the census recorded an EMIT. The plugin reported nothing on its bad example, which is the one
thing only running it can show.

### The real gap, named

A `return` inside a loop that produces the helper's value is a **fold**, not a statement: it means "take the
first item the loop reaches and stop". The vocabulary has no such shape, and my change made the return
resolve as though the loop were not there. Building it means the loop and the return together — the loop
becomes the search and the return its result — which is a different piece from the six above and the one this
rule actually needs.

Reverted rather than patched, because a half-built fold is how a plugin that loads and misbehaves ships. That
is the seventh revert in this log and the first where the reverted work had already produced an `EMIT`.

## The first-match fold: the first genuine capability cluster in this corpus

The reverted attempt named the gap — a `return` inside a loop is a fold, *take the first item and stop*, not
a statement. Sized before building it, and unlike every other cluster this session it holds.

**Six refusing rules carry the shape.** Five found by matching a loop whose body returns a value, plus
`NoTestMocksRule`, whose single-line body the brace match missed:

    ClassNameRespectsParentSuffixRule   ForbiddenFuncCallRule   ForbiddenNodeRule
    PreferredClassRule                  RectorCheaperGuardsFirstRule   NoTestMocksRule

**And it is one shape, read at source rather than grouped by label** — the check my own rule about syntactic
grouping demands. Three of them, side by side:

    foreach ($this->parentClasses as $parentClass)       if (! $classReflection->is($parentClass)) continue;  …  return $expectedSuffix;
    foreach ($requiredWithMessages as $requiredWith)     if (! $matcher->isMatch($funcName, [..])) continue;   …  return $message;
    foreach ($stmts as $index => $stmt)                  if (! $stmt instanceof ..) continue;                  …  return $index;

Same fold in each: a guarded search that yields the value derived from the first item satisfying it. One walks
a configured list, one a statement list, one an inferred type's constant strings — the collection differs and
the fold does not.

That makes it the **first cluster this session that is not a syntactic wrapper**. Every previous one — the
`Stmt_Expression` guard bodies, `array_merge()`, `Expr_Ternary`, the four `*TypeDeclarationCollector`s —
dissolved because the shared item described syntax and the real blockers sat behind it. This one describes a
capability, which is exactly the distinction the entry above predicted would matter.

### What it is worth, honestly

The fold alone probably unlocks none of the six on its own, and the familiar reason applies: each carries
other blockers — `ForbiddenFuncCallRule` an unwired constructor parameter, `ClassNameRespectsParentSuffixRule`
a helper that builds findings rather than answering, `PreferredClassRule` five node kinds including a virtual
one. `NoTestMocksRule` is the exception and the reason to start here: its other six blockers are **built and
measured**, reverted only because the fold was missing, so the fold is the whole remaining distance.

So the build order is settled: the fold, then re-apply the six pieces the revert took out, then the pair with
the configured allow-list that the gate already needs. And the fold has to emit the loop and the return
*together* — the loop becomes the search, the return its result — rather than resolving the return as though
the loop were not there, which is precisely what shipped a plugin reading an undefined variable.

### The fold, located: a producer with no consumer

The sharpest form of the gap, found by grepping the two kinds rather than by reading the loop:

    'constant-strings'  produced at Translator.php:12127, consumed nowhere
    'constant-string'   consumed at Translator.php:12106, produced nowhere

`getConstantStrings()` yields the plural over `Support::constantStringsOf(<type>)`. The `getValue()` arm
consumes the **singular** and calls it "an element of `getConstantStrings()`" — so someone built the consumer
expecting a loop to bind one element of the plural to it, and that loop arm was never written.
`translateForeach()` has no `constant-strings` case, which is why the loop emitted an empty body.

And the value it needs already exists with matching semantics: `Types::constantStringOf($type)` is literally
`constantStringsOf($type)[0] ?? null` — *the first constant string, or null* — which is exactly what
`foreach (…getConstantStrings() as $s) { return …$s…; } return null;` computes.

So the piece is a `constant-strings` arm in `translateForeach()` binding the item as a `constant-string`
descriptor, and for a value-producing helper whose loop body is a single `return`, binding it to
`constantStringOf(<type>)` and guarding the result on non-null. Two kinds, one existing helper, one loop arm.

**Stopped here deliberately.** The previous attempt on this rule reached `EMIT` and shipped a plugin that read
an undefined variable, and the difference between that and a correct one is precisely this arm — emitting the
loop and the return *together*. Starting that at the end of a long session is how the last one went wrong; the
design is now at code level, with file and line for every piece, so a fresh start begins by writing rather
than by looking.

## Second attempt at the fold, and the check that would have caught it

The `constant-strings` loop arm turned out to be a **table row** rather than code —
`translateForeach()` dispatches on `Vocabulary::ITERABLES[$kind]['item']`, so one row binding the item as a
`constant-string` connects the producer at `Translator.php:12127` to the consumer at `:12106`. Added, and the
loop then translated: the refusal moved off the loop and back onto `Expr_New`, so the body was reached.

Re-applying the six pieces from the reverted attempt then reached `EMIT` again. **The plugin was still
wrong**, and worse than last time:

    foreach (Support::constantStringsOf(Support::expressionType($context, $arg_value)) as $constant_string_type) {
    }

    return;
    if (!($constant_string_type !== null)) {

An empty loop body *and* a bare `return;` after it, making everything below dead code — the plugin does
nothing at all. So the ITERABLES row was necessary and not sufficient, exactly as the previous entry said: a
`return` inside a loop in a value-producing helper needs the **fold**, and without it the return becomes a
statement-level exit from the hook rather than the loop's result. My own analysis was right and my hope that
the row alone would carry it was not.

Reverted again — eighth revert here, second on this rule.

### The check that caught the other half, and the hole in it

The same plugin also called `Support::namedClassIsInstanceOf()`, a helper the earlier revert had taken out
from under it. `TranspilesToPhpTest::test_every_helper_the_corpus_calls_exists()` exists for exactly that and
its docblock says *"cheap enough to run over the whole corpus"* — but its glob named
**`vendor/symplify/phpstan-rules` alone**. A missing helper emitted by a `hihaho`, `phpstan-*` or
`tomasvotruba` rule went unchecked, and the docblock had claimed otherwise since the check was written.

Widened to every installed package's `src`. It now makes **1267 assertions** where it made a fraction of
that, and the widening is load-bearing rather than decorative: removing `Support::namedClassIsSubclassOf()`
— called only by `CombinedStaticCallRule`, a *hihaho* rule — now fails with

    CombinedStaticCallRule.php emits a call to Support::namedClassIsSubclassOf(), which does not exist.
    The plugin would load and then kill the worker.

Before the widening that removal passed silently. So the rule work produced nothing and the attempt still
closed a real hole in a load-bearing gate: **a claim in a docblock wider than the code under it**, which is
the failure this log is largely made of, found because a broken plugin of mine walked into the gap.

Suite 1088/1088, PHPStan 0 errors, Rector and Pint clean. Three defects of my own in the widened check —
a short ternary the project forbids, a `=== false` that became unreachable once `$rules` was a list, and a
missing blank line — all caught by the gauntlet rather than by me.

### Third look: the reduction already exists, and the wall is unchanged

`ConstantStrings::at()` opens with exactly `Types::constantStringOf(Support::expressionType($context,
$subject))` and adds two fallbacks — magic constants and a declared literal — that PHPStan's
`$scope->getType()` also covers. So it is a faithful *superset* of what the helper computes, not a looser
answer, and `resolveMockedObjectType()` reduces whole to `constantStringAt($context, <the argument node>)`.

**And that reduction is already wired.** The `getValue()` arm at `Translator.php:12109` reads:

    'php' => isset($of['of'])
        ? 'Support::constantStringAt($context, ' . $of['of'] . ')'
        : 'Support::constantStringOf(' . $this->operand($of) . ')',

A type descriptor carrying the node it came from already emits the whole question. So the pieces are all
present: the collection kind, its item kind, the reduction, and the node to ask it of.

**The wall is unchanged and it is the same one.** A `return` inside an emitted loop, in a helper whose value
the caller consumes, has to become the loop's *result* rather than a statement. Three attempts have now ended
there: the first emitted an empty loop and leaked the loop variable, the second added the missing ITERABLES
row and emitted an empty loop plus a dead `return;`, and this one traced the reduction far enough to confirm
that nothing short of the fold closes it.

`inlineValueProducer()`'s chain is the right home — `lastNameSegmentHelper()` shows the shape, about forty
lines of statement matching ending in a descriptor — but a recogniser there must match the *whole* helper,
and this one's body is four statements of chained locals before the loop. Matching that chain is brittle;
letting the leading statements bind normally and folding only the tail is not something the chain supports.

**Stopping on this rule.** Three attempts, two broken plugins, and the remaining gap is a design change to how
a value-producing helper's return interacts with an emitted loop — not a row, and not something to start at
this depth. Everything a fresh attempt needs is now recorded down to file and line, including the reduction
it should aim at rather than the fold it might otherwise build from scratch.

## larastan added and measured: 0 of 26, and the estimate it was chosen on did not hold

Added as a dev dependency on the user's decision, to test a peer's classification that it held ~18 rules in
the portable shape. Measured:

    emit run    emitted: 0, refused: 26
    survey      emitted: 0, refused: 26

**Zero, on both paths.** The peer's figure was a proxy — rules with a literal message and no constructor
dependencies — and it does not predict emission. Stated plainly because the decision rested on it: 18
candidates in the *shape* became 0 rules translated, which is the proxy-versus-authoritative-source failure
this thread has recorded five times, this time inside the estimate a dependency was taken on.

Two things were done right and are worth keeping:

- **`larastan/larastan` went into `extra."phpstan/extension-installer".ignore` before the require**, beside
  `hihaho/phpstan-rules`. Registering a corpus's rules against this repository's own source is not what a
  corpus is for, and larastan expects a Laravel application — this repository's own PHPStan run is still 0
  errors because the ignore landed first.
- **The census denominator did not move.** It reads a curated package list rather than everything installed,
  so every figure in this file and the README still means what it meant.

### What the package does contain, which is a real cluster

Its largest refusal is `Expr_New` in **six** rules, and they are one idiom rather than six problems:

    (new ObjectType(Mailable::class))->isSuperTypeOf($type)->yes()      UsedEmailViewCollector
    (new ObjectType(Translator::class))->isSuperTypeOf(..)             UsedTranslationTranslatorCollector
    (new ObjectType(Factory::class))->isSuperTypeOf(..)                UsedViewMakeCollector
    (new ObjectType(Auth::class))->isSuperTypeOf(..)                   NoAuthFacadeInRequestScopeRule
    (new ObjectType(AuthManager::class))->isSuperTypeOf(..)            NoAuthHelperInRequestScopeRule
    (new ObjectType(Enumerable::class))->isSuperTypeOf(..)             NoUnnecessaryEnumerableToArrayCallsRule

A constructed type on the left and an *inferred* one on the right — "is this type a `Foo`" — which is
`Runtime\Types::typeIsInstanceOf()`, already built, already carrying the union behaviour PHPStan's `yes()`
means. `Translator.php:10350` already handles the case where **both** sides are constructed, so this fell
through to the general type query and refused on the construction.

**Built, measured, reverted.** The arm moved two of the three rules past `Expr_New` — onto
`->getTemplateType()` and `Expr_Match` — and the package still emits 0. Emit-all over the seven census
packages is byte-identical, so nothing exercises it. Ninth revert here, and the first for a capability aimed
at a package outside the census.

### Where that leaves the dependency

It costs a `require-dev` entry and buys no rule today. Its justification did not survive measurement, and
removing it is `composer remove --dev larastan/larastan` plus dropping the ignore entry. Kept for now because
the six-rule idiom above is a genuine cluster and the arm that serves it is recorded here ready to re-apply —
but that is a judgement the user should overrule freely, since the reason it was added turned out not to be
true.

### The dependency made three test-enforced figures stale, and the tests did not notice

Adding larastan broke claims in the census header, which is asserted line by line by
`TracksUpstreamDriftTest`. Re-derived rather than re-read:

| claim | was | is |
|:--|--:|--:|
| `--status` portable rules | 209 | **236** |
| rule lines in this file | 190 | **192** |
| other packages beyond the corpus | two | **three** |

**The suite passed with all three wrong**, which is the mechanism worth naming. The header is asserted as
literal strings, so a test compares the generated file against the expected file and both carry the same stale
number — the assertion pins the text against drift in the *generator*, and cannot see the text drifting away
from the *world*. A figure inside a test-enforced artefact is only as fresh as whoever last re-derived it.

The `190` was already stale before this session touched anything: the census has held 192 rule lines for some
time. So of the three, one was mine and two were waiting.

Fixed in the header and in the README, which quoted the same `--status` figure and named the two packages in
that denominator — now three, with larastan's `(26)`. All seven README rows re-cross-checked against the
census.

**The countermeasure this argues for is the one already in this log**: after editing a document for any
reason, re-derive its figures from their sources, one command per figure. A dependency change is "any
reason", and it reached three numbers in two files that nothing would have flagged.

### The first-match fold was never the blocker, and building it would have moved zero rules

I named the fold the leading remaining route for several turns, on the strength of "six rules share it,
verified as one shape at source". The shape claim was true. The *blocking* claim was never checked, and it is
false. Resolving all six mechanically:

| rule | first obstacle |
|:--|:--|
| `NoTestMocksRule` | `Expr_New` (line 82) — and an unwireable `$allowedTypes` behind it |
| `ClassNameRespectsParentSuffixRule` | the helper builds the findings, not a verdict |
| `ForbiddenFuncCallRule` | `$forbiddenFunctions`, a constructor parameter no shipped neon wires |
| `ForbiddenNodeRule` | `PhpParser\Node` narrowed to several kinds by `instanceof` |
| `PreferredClassRule` | the same, over five kinds |
| `RectorCheaperGuardsFirstRule` | `foreach` with a key (line 166) |

Six rules, six different obstacles, and the fold is not one of them. Every rule reaches its fold only *after*
the blocker above, so the fold's marginal value is **zero rules** until each of those is cleared separately.

**This is `needs-at-least:` read as `needs:`** — the exact misreading the census header warns about, made by
whoever wrote the warning. The six do share the fold; I ranked a *later* need as though it were the gate. A
shared shape is evidence about what a capability would be used for, never evidence that building it moves
anything.

**Superseded, marked rather than deleted:** the phrase "verified as one shape at source, the first
non-syntactic cluster" holds for the first half only. Reading the source told me the six folds are alike, and
reading the source is what cannot answer the question I was using it for — the blocker is the transpiler's
first refusal, which only a run reports.

#### What the attempt did establish

A whole-helper recogniser for `resolveMockedObjectType()` — the pattern `lastNameSegmentHelper()` sets — plus
an `object-type` null-test arm cleared two of `NoTestMocksRule`'s guards and moved its refusal from
`Expr_New` to `$allowedTypes`. So the fourth attempt on that rule did work, and revealed that the rule is
blocked **correct-forever**: `private array $allowedTypes = []` is wired by no neon the package ships, and
reading the declared default is the approximation `takeDeclaredDefault()` was reverted for.

Both folds are **reverted**: an emit-all across 191 corpus rules is 145 php / 34 analyzer / 25 linter both
with and without them, so they are unexercised vocabulary and go out under the same condition as the nine
before them. The finding is worth more than the code was.

#### The lead this leaves

`ForbiddenNodeRule` and `PreferredClassRule` refuse on the *same* blocker — a `PhpParser\Node` hook narrowed
to several kinds by `instanceof`. That is a genuine two-rule cluster and the first one measured as blocking
rather than merely shared.

### Most of what is left to "make emit" is not a capability gap

Three candidates died on one cause in a row -- `NoTestMocksRule`, `ForbiddenFuncCallRule`,
`ParamNameToTypeConventionRule` -- so the cause is worth a number rather than another anecdote.

**Configuration**: an emit-all over the four corpus packages **plus `tests/Fixtures/Rules`** (191 rules, php
target), which is 45 refusals over 44 distinct rules. This is *not* the census population -- the census is 192
vendor rules and excludes the fixtures.

| partition | n | what it means |
|:--|--:|:--|
| my own refusal fixtures | 11 | designed to refuse; a pass |
| **no consumer wiring exists** | **8** | correct-forever |
| has config params, wiring exists | 7 | reachable |
| no config params | 11 | reachable |
| no constructor | 7 | reachable |

So of the 33 non-fixture refusals, **8 are correct-forever**: the rule takes a required config-shaped
constructor parameter with no default, and no neon any installed package ships names the rule at all. There is
nothing to wire it from, so no transpiler capability makes it emit.

The transpiler **independently agrees on 7 of the 8** -- it names the parameter and the missing wiring in its
own refusal. The eighth, `ParamNameToTypeConventionRule`, refuses earlier on a missing `Param` hook; its
configuration blocker sits behind that and was confirmed by hand, with a positive control (`PreferredClassRule`
resolves to two neons, the candidate to none).

#### An unclearable need blocks from any position; a clearable one only helps from the first

This is the inverse of the fold error recorded above, and the pair is the useful part. There I ranked a
*later* need as the gate and it moved zero rules, because clearing a capability only helps when it is the
**first** obstacle. Configuration is the opposite: a required parameter nothing can supply blocks the rule
**from wherever it sits in the order**, so finding it behind an earlier refusal is still decisive. Which way
the asymmetry runs depends entirely on whether the need can be cleared at all -- so that is the question to
ask about a need before ranking it, and it is not the question `needs-at-least:` answers.

#### "Reachable: 25" is my label and it does not survive contact

Naming the rest reachable overstates it. At least four of the 25 are blocked on something other than
transpiler capability, and I found them by reading the list I had just labelled:

- `NoTestMocksRule` -- `$allowedTypes = []` *has* a default, so the wiring test passes it. It is blocked on
  this repository's deliberate refusal to read a declared default (`takeDeclaredDefault()`, reverted). A
  policy decision, and not mine to reverse.
- `WriteNamedArgumentManifestRule` -- writes a file and returns no findings, so there is nothing for a lint
  rule to report. Correct-forever.
- `NoMissingVariableDimFetchRule` -- definedness, blocked upstream in mago.
- `UppercaseConstantRule` -- collides on output filename with a fixture of mine of the same name. An artefact
  of running the corpus and the fixtures together; it blocks no census rule.

That leaves roughly 21 genuinely capability-blocked, and "roughly" is honest: I did not audit the remaining 21
the way I audited these four, so the same reading would probably find more.

#### Answered in passing: the open question in two refusal messages

Both multi-kind refusals end "Whether this body does has not been checked here." Checked:

- `NoReferenceRule` -- **yes**. Seven of its eight kinds are handled by one `if ($node->byRef)`, literally the
  same child in every branch; `AssignRef` is the eighth and needs no field, since its presence is the
  violation.
- `PreferredClassRule` -- **no**. Four arms read a class-name child, but the `InClassNode` arm reads the
  parent class reflection instead.

Neither emits on that alone -- both also carry an injected resolver, and `PreferredClassRule` needs a config
map and a keyed `foreach`.

#### The instrument, again

The first run of this partition read **19 of 54** neons: the glob was `vendor/*/*/config/**/*.neon` and
`hihaho/phpstan-rules` ships its neons at package root. That misfiled two rules as unconfigurable. The count
beside the glob is what caught it -- the same check that caught the emit-all zero, which is now three times
this instrument class has been wrong in the same direction.

### Coming at it from mago's SDK instead of from the refusals, and what that did not find

Every previous ranking started from the refusal texts. Those groupings saturated: the ones that are large
describe syntax, the ones that block describe deep capability. So this pass started from the other end —
what the SDK exposes that this repository has never mentioned — and asked which refusals that retires.

The sweep is mechanical: for each class under `vendor/carthage-software/mago/composer/src/Sdk`, whether its
short name appears anywhere in `src/`. Two hits looked like they answered a live blocker:

- `Analyzer/ClassLikeAnalysisHook` with `Analyzer/ClassLikeTarget::descendantsOf($ancestor)`.
- `Analyzer/ReferenceRegistry` and `SymbolReference`, the reverse index this log has costed before.

**Neither retires a refusal, and the first one is the instructive miss.** READ FROM THE DOCBLOCK, not
measured: `ClassLikeAnalysisHook` "inspects class-like declarations descending from selected ancestors",
resolving ancestry natively so only matching descendants cross the extension boundary. That reads exactly
like the missing piece for a rule of the shape *a class extending X must be Y* — and `PreferredClassRule`'s
`InClassNode` arm looked like the customer.

It is not, for two separate reasons, and either alone is decisive:

- **The capability is already covered by another path.** `Vocabulary` has mapped `InClassNode` to
  `ClassDeclarationHook` / `on_enter_class` with `ClassLikeMetadata` all along. So "unused SDK class" did not
  mean "capability this tool lacks"; it meant a second route to something already reachable. An unused-symbol
  sweep cannot tell those apart, which is the flaw in the instrument rather than in the result.
- **Using it here would approximate.** `descendantsOf()` matches descendants *transitively*, while the rule
  compares `getParentClass()->getName()` — the **direct** parent only. Substituting one for the other
  over-reports on a grandchild, which is the plausible-but-wrong rule this repository refuses to emit. The
  target is also an analyzer-side hook, so it could not serve the shipped php target regardless.

So the SDK direction is answered for now, negatively, and that is worth recording because the search was
cheap and the *shape* of the miss recurs: **an unused symbol is evidence about this repository's vocabulary,
never about the consumer's need.** The sweep ranks by what we have not called, and what decides a refusal is
what a rule asks for.

Left standing for whoever picks this up: `ReferenceRegistry` was costed here on performance grounds and never
on capability grounds, and I did not read it this pass. That is an open thread, not a finding.
