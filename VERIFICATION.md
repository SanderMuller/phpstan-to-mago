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

