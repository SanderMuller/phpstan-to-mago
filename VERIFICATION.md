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
