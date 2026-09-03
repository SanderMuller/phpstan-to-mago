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
