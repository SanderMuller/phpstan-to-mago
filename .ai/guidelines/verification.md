# Verification

Evidence before claims. Every rule here is a claim that shipped wrong at least once in this codebase.

## "It emitted" is not a result

The generator refuses what it cannot translate, *and* the backend refuses any operand it was handed and
could not render. Without the second check the tool once reported ten rules emitted where six did not parse
and two parsed while still containing Rust. A count is worth stating only alongside: the files parse, no
Rust leaked into a `.php` file, every `Support::` helper it calls exists, and the rules actually ran.

Running matters on its own. A bare snake_case identifier is well-formed PHP — it reads as a constant — so
nothing before execution catches it.

## A green run over material you wrote is the weakest evidence available

Fixtures and snapshots are authored by the person who wants them to pass. After a change is green, run over
code nobody wrote for you and diff the findings against the same run before the change.

## Agreement on zero is not evidence

Two tools both reporting nothing is equally consistent with "the code is clean" and "the second tool never
looked". Dogfooding on an application's own source gave 0 from both tools — expected, since the application
enables those rules, and useless. The dependency tree gave 25 findings and 25 agreements.

## Prove the mechanism with a control, not with plausibility

When a result has two possible causes, build the case that separates them.

- Mago reported 6395 unresolvable classes and is known to skip such class bodies, so a zero might have meant
  "never looked". Two files — one plain, one extending an unresolvable parent, same violation — settled it.
- A survey reporting 4 emitted where a real run emitted 3 looked like leniency in the survey. It was the
  target: survey honours whatever target is set, and the default was the other one. The plausible story
  would have sent a fix into the wrong code.
- The whole rule package refusing with "assignment value outside the vocabulary" while every helper sat in
  a trait made cross-class resolution look like the blocker. A probe rule with the helper in the *same
  class* was refused identically. Cross-class resolution turned out necessary but not sufficient, and
  implementing it changed the count by zero.

In all three the code read correctly at every line. Reading would not have settled any of them.

## Mutation-check a filter you just wrote

A passing test proves the code runs, not that the logic is load-bearing. Break the condition deliberately
and watch the test fail, then restore it. Making `RulePaths::isRule()` return `true` unconditionally made
the directory-walk test fail with an abstract base and a trait in its output — that failure *is* the
evidence.

## Verify a claim at the granularity you are publishing it

"Every one of these 15 refusals is the same shape" was written after reading two of them. Resolving all 15
mechanically found that two were something else, and one of those was a correct-forever refusal rather than
part of the story. The number was the part being used to size the work.

## A probe answers the question you asked, not the one you are about to act on

Two APIs were checked before a rule was built on them, both by running code, and both answers were true.
Neither was the answer the rule needed.

- `PHPVersion::$id` exists and is a public readonly int — verified. "So read `$id`" is a *different* claim,
  about what the integer means, and it is false: mago packs a version as `(major << 16) | (minor << 8) |
  patch` and PHPStan encodes it as `major * 10000 + minor * 100 + patch`. 8.3.0 is 525056 against 80300. A
  rule comparing against twenty PHPStan-shaped ids would have reported every deprecated option on every
  project. It was caught by reading `fromParts()`, not by testing: 525056 does not look wrong on its own,
  only next to 80300, and a reachability probe never puts the two numbers side by side.
- `getConstant('PHP_EOL')` comes back with the deprecation flag right — verified, by bare name. The rule
  reads constants *inside namespaces*, where `getResolvedName()` answers `Dep\PHP_EOL` and the codebase
  holds only `PHP_EOL`. The rule would have emitted and reported nothing on every real file.

Both probes were correct instruments answering a narrower question than the one being decided. So state what
a probe establishes and what it does not, especially when handing it to someone else: a verified fact and an
unverified inference in the same sentence look identical to whoever builds on them, and only they can tell
which was which — too late.

The version case is sharper than that, and it is why the rule is about *marking* rather than about care. One
message carried three things in one voice: `$id` exists (probed), so read `$id` (inferred), and
`availableVersions` may serve better, worth a look (a suggestion). The reader followed the third, opened
`fromParts()`, and found the second was wrong. Four claims of different standing, nothing distinguishing
them, and it was luck that the one followed was the one that led to the error. Neither sender nor receiver
was careless; there was no way to sort them.

So mark what each claim is when you send it, and ask when you receive one that is not marked. The receiver
is the last person who can catch it, which is exactly why the rule cannot be "the sender should have
checked".

Reachability is the usual gap. "The field is there", "the method answers", "the class resolves" say nothing
about what the value *means*, whether two values are comparable, or whether the question is the one the rule
asks. Where a value is going to be compared against something, probe the comparison.

## The instrument can be silent about the distinction you need

The rules above are about explanations. This one is about the observation itself, and it is the harder
failure: four times in one session a measurement was an artifact of the thing measuring it, and reading the
source caught none of them.

- **A rendering dropped the field the decision turned on.** `ScalarType::__toString()` returns
  `$this->kind->value`, so a `callable-string` renders as `string` and so does an un-narrowed one. Six rows
  of `(string) $type` produced a table reading "mago does not narrow on `is_callable()`". It does. The defect
  was in this repository, in a predicate that reached the refinement and read `literalValue` off it but never
  `callable` four lines below.
- **A reproducer varied the wrong axis.** Five rows moved a `use` capture and appeared to show a template
  lost across it. The rows held the element type constant only by accident of how each was written; once
  varied, the capture does nothing and the element type is the whole effect. Both engines agree.
- **An aggregate counted what it did not print.** The corpus differential prints each divergence and only
  counts agreements, so "the site never appears as only-port" cannot separate *both engines report* from
  *neither reports*. It was the first, which inverts which side of a recorded divergence had changed.

A fourth instance is the one to fear, because it would have *agreed*. Recording a needs pass's terminal
refusal unconditionally would have taken one capability from 0 needs to about 60 and made it the largest in
the tool — confirming a ranking a peer session had just proposed, with a number, from the instrument built to
stop that error. The artefact had a cause: the message a rule reports is built by a statement, so any rule
with a stepped-over statement reaches the end without one and terminates on the same refusal. It is a third
artefact of stepping over a statement, beside the two already filtered.

**An artefact that confirms the hypothesis is the dangerous one**, and whoever receives the number cannot
tell it from a real result — only the person who can run the instrument can. So when a measurement supports
what you expected, that is the moment to ask what else would produce it.

**Read the model, never a rendering, wherever a value will be compared or branched on.** A rendering is a
lossy projection chosen for a human, and `__toString()` on a type is the most tempting one here.

**And build a control pair, not a control.** One row that varies the axis under test, and one beside it that
must *not* move. Vary a single axis per pair, and prefer a control inside the same file over a second
fixture — `is_string` beside `is_callable`, `value()` beside `measure()`, a declared type beside an inferred
one. A passing control that passes for the wrong reason looks exactly like a passing control, and a pair is
what tells them apart.

Where an aggregate is the instrument, ask what it does not print before reading a zero off it. Then confirm
the one case at the smallest granularity that can answer: one rule, one file, both engines.

## A claim can be damaged by an edit that was not about it

Every rule above is about making a claim carefully. This one is about the claims you are not making: a figure
or a caveat already in a document, touched while editing that document for some other reason.

Two instances, from one file on one day:

- **A count carried along.** A README said "the nine defects they found", then "the eleven", the second
  written while rewriting the sentence around it for an unrelated figure. It was never counted, and it was not
  countable — "defect" there spanned three different populations with no criterion committed anywhere.
- **A caveat trimmed away.** A performance table named the version it was measured on, with a clause saying
  the figures still held on the newer version the package had come to require. The clause was cut while
  trimming the page under its word budget, leaving a table a reader on the supported version could not
  reproduce.

Neither edit was about the claim it damaged. That is what makes this failure quiet: **carrying a figure feels
like preserving it rather than asserting it**, and cutting a clause for length feels like editing rather than
deciding. Nothing about either edit looks like a claim, so nothing prompts a check — unlike a wrong cause or a
bad measurement, which at least announce themselves as claims when you make them.

Two cheap countermeasures, both used to find the pair above:

- **After editing a document for any reason, re-derive its figures from their sources.** Not re-read —
  re-derive, by parsing the file the number comes from. One command per figure.
- **A number that cannot be re-derived should not be in the document.** If "defect" or "case" or "rule" has no
  committed definition, the count has no configuration to print beside it, and the honest form is a pointer to
  the file rather than a tally.

Length is never a reason to drop a caveat. Where a page is genuinely over budget, cut a restatement, an
example, or a rationale — never the sentence that tells a reader when a number stops applying.

## Every number right, and the sentence still wrong

The rules above are about getting a measurement right. This one is about the sentence built on top of a
measurement that *was* right, which is a different failure and the one a control pair cannot catch.

Instances, each recorded in `VERIFICATION.md` under the heading named:

- **A generalisation to an unmeasured cell** — *"The narrowing gaps are two mechanisms, and the matrix settles
  it"*. "`is_callable` does not act on object atomics, resolvable or not" was written from four measured
  cells. The fifth had never been run, and when it was, it ran the opposite way. Every one of the four cells
  was correct; the phrase "or not" had no row under it.
- **A unification that fit every cell anyone had run** — *"The last untraced finding is a third mechanism"*.
  Two findings sharing a symptom, written up as one mechanism. The row where the two candidates predicted
  different answers refuted it.
- **A conclusion drawn from a runtime that never runs** — *"The fourth row"*. "Mago lets the invocation
  through, and `$i()` is a fatal at run time." The guard is `is_callable()` on a class with no `__invoke`, so
  it is false and the body is dead code. The narrowing figures behind the sentence were all correct.
- **A subtotal that agreed by luck and hid the discrepancy** — *"The audit, rerun"*. Two sessions counted the
  same table on different instruments and matched exactly on one subtotal, 23. The totals differed by three,
  because one extractor's row regex ran without `re.DOTALL` and could not see a multi-line row — and all three
  rows it dropped happened to fall in the *other* bucket. Comparing only the matching subtotal would have
  certified both instruments. **An exact agreement on a subtotal is not evidence about the totals** — the
  sharper form of *agreement on zero is not evidence*: a shared zero fails to reveal a difference, a matching
  subtotal can conceal one.
- **A cell stated as a property, from both sides at once** — *"The PHPStan half, reproduced here"*. One
  session wrote that `class.notFound` is non-ignorable; the other measured a subject where it is ignorable and
  corrected that. A file carrying both occurrences settles it: under one `ignoreErrors` entry, one row
  disappears and the other does not. Two true measurements, two over-general sentences, in opposite
  directions — and the fix was to state which occurrence each held for, not to pick a winner.

In each, no instrument was wrong and no figure was carried. **The defect was in the sentence, and a sentence
is not a thing you can run.** The last one is the shape to remember, because two careful people produced it
in the same exchange and in opposite directions: a measured cell restated without the condition that produced
it. A control pair separates two mechanisms; a discriminating row settles which of
two explanations holds. Neither looks at whether the sentence says more than its rows support.

This is not the "wrong why" rule below. That one is about asserting a cause you never traced. Here the cause
may be honestly labelled as untraced two paragraphs earlier and the sentence still overreaches — by
generalising a measured range, or by asserting a consequence the setup makes unreachable.

A relative of it is worth naming separately, because the clause test below does **not** catch it:
**attributing a row to whoever said the sentence rather than to whoever ran the command.** In the same
exchange, a measurement made in this repository was written up as the peer session's, and a mechanism the peer
sent with file-and-line citations turned out to name lines that were something else in the installed build.
Every clause had a row under it; the row just belonged to someone else, or to another version of the file.
A document with a misattributed row reads exactly as well-sourced as one without. The check is to re-derive a
citation before repeating it, especially one that arrives already looking specific.

**And say what you re-derived it from**, because the same exchange produced the sharper version of this: two
citations of the same file at the same release, both correct, contradicting each other. `phpstan.phar`'s copy
of a file holds each fluent chain on one line where the repository's spreads it over several, so
`ExistingClassInClassExtendsRule.php` is 150 lines in git and 87 in the phar. The `class.notFound` site is git `:65` and phar `:56` — and phar `:65`
holds a *different* identifier, so checking the phar returns a confident, self-consistent answer that makes
the git citation look wrong. **Re-derivation surfaced the discrepancy and could not resolve it**, because both
derivations were sound. Only naming the artefact does: "src/Rules/..." meant the repository on one side and
the phar on the other, spelled identically. A line number is meaningless without the artefact it indexes, and
a distributed build is a different artefact from the repository it was built from.

Two things catch it:

- **A second reader who does not know what the sentence is meant to say.** The runtime-fatal claim was caught
  twice within minutes, by a peer session and by an independent review tool, neither of which held the model
  the sentence came from. The author cannot be that reader: the sentence reads as true to whoever built the
  model behind it.
- **Asking, per clause, which measured row licenses it.** "Resolvable or not" had a row for *resolvable* and
  none for *or not*, which is visible on inspection once the question is asked clause by clause rather than
  claim by claim. The `class.notFound` pair is the same test at its smallest: "non-ignorable **on this
  occurrence**" has a row under it and "`class.notFound` is non-ignorable" does not. One prepositional phrase
  is the whole difference, and dropping it is what made the sentence false.

Mark a superseded claim where it stands rather than editing it away. The marker carries the reason the phrase
did not hold, which a clean edit deletes along with the error.

**A control that shares a confound is not a control.** The follow-up to the artefact split above wanted a file
the *downgrade* step could not have touched, so that a reprint in it would belong to the compactor. The file
offered was picked for carrying no PHP 8 syntax to lower — a reasonable proxy, and wrong: it had a promoted
constructor parameter, and the built artefact showed it lowered. The proxy was "nothing to change" when the
property needed was "not processed at all" — two different things, and only the second excludes the step.
**Choose a control by the mechanism you are excluding, not by a property that usually accompanies it**, and
read the exclusion off the step's own configuration rather than off the file's contents. The replacement
control, a file outside the step's configured paths, was better and still not clean: a configuration lists
scope, not traversal, and nothing in it rules out another stage reaching the same file. Configured scope is
evidence about intent; only tracing the run is evidence about what happened.

The same species lives in this repository's own tables: a `'phpOnly' => true` row asserts that Mago's analyzer
cannot carry a hook, and it is a line we wrote rather than a thing we measured. **A declaration in your own
config reads exactly like a measurement of the system it describes**, and it is the easier of the two to
write. The first attempt to size that here was itself withdrawn — see *"A `phpOnly` audit I published from the
wrong table"* — which is the next rule.

**After locating a structure by name, assert that what you found is that structure.** The audit above parsed
its table by taking the first occurrence of the constant's name in the source. That occurrence was a *comment
mentioning* the constant, inside a different table, so every figure described one structure and was measured
on another. Nothing downstream can tell those apart: the numbers were internally consistent, mechanically
derived, reproducible by re-running the script, and agreed with a second source. **An instrument pointed at
the wrong object produces all the same signals as one pointed at the right object**, so the assertion has to
happen at the point of location, not in the results. Two are worth carrying: match an *anchored declaration*
rather than any mention of the name, and assert the block you sliced does not contain the start of the next
one. The first catches a locator landing on a comment and truncating; the second catches a closing delimiter
belonging to something later, which inflates instead — same class, opposite direction, both silent.

Two companions, from the same audit. **Read the file you are appending to.** A later section of this
repository's own log was drafted saying a question was open that an earlier section had already measured and
closed; nothing in the tooling compares a new entry against the document it joins, and a weaker newer claim
sitting below a stronger older one is how a record loses what it knew. And **a limit found by exhausting the
artefacts you happen to have is a fact about your position, not about the question.** The registry that could not be checked from
this repository was a `git clone` away, and "no route found is not the same as no route" was written here one
commit before someone took the route.

## A guideline can have a scope boundary nobody drew

Every rule in this file was written for a measurement or an artefact. Several are enforced: a snapshot test
compares emitted bytes, the census alarm compares a generated file, the fires gate runs a plugin. None of
them runs over the prose that quotes those artefacts outward — a README, an upstream issue draft, a section
of the verification log.

The boundary showed itself in one line. The census header ends with *"Where a count decides work, read the
rules it is made of."* That rule is enforced on the census, by test. An unfiled issue draft then claimed two
rules were *blocked* on a capability; the census recorded both refusing earlier for unrelated reasons; and
nothing caught it, because nothing runs over a draft. **The guideline held for the artefact and not for the
writing about the artefact, and the boundary fell exactly where the claim leaves the repository.**

The error record has the same shape. Generated output in this repository has been wrong close to never —
snapshots, census lines, emitted plugins. The documents *about* it have been wrong repeatedly, and so has
every cross-session message, none of which any test touches. **The failure rate tracks whether a thing is
executed, not whether it was written carefully.**

**A rule you have to remember while writing is the instrument that already failed.** The countermeasure for
generalising over shapes was written down, and then the same failure happened again in the next draft — in
drafting, which is the activity the rule is about, so it was in scope and still did not fire. That is not
missing coverage. It is that remembering a rule at the moment of writing uses the same faculty that produced
the error. What caught it both times was someone else running the table on a shape the author had not chosen,
which is not a rule and cannot be written as one. So where a claim matters, budget for a second party rather
than for a more careful self-review.

Two things follow:

- **A claim leaving the repository deserves the check the artefact gets.** Before a number goes into a
  README, an issue, or a message to someone else, re-derive it from the file that produces it — the same
  command the test would run. A count that decides someone else's work is the case, not the exception.
- **Ask which of the rules you rely on are enforced.** The ones that are will hold without you. The ones that
  are not are the ones you have to run yourself, and they are exactly the ones that feel safest because they
  have never failed loudly.

## Verifying a claim is not testing whether the claim matters

Two sessions verified every figure in two issue drafts. Both machines, three instruments, every table re-run
on inputs the author had not chosen, every line number fetched from the tag. One draft asked for a capability
whose absence was demonstrated beyond argument.

**Not one of those passes asked whether the gap had a workaround.** It did. `SourceFile::getTrivia()` returns
`DocBlockComment` trivia with spans, under a requirement the probes already carried, so the rule whose
exemption "could not be honoured" could honour it today by reading the annotation and associating it by
position. The sentence that justified half the request was the only false sentence in either document, and it
survived every check because **checking a claim and asking whether the claim matters are different questions,
and only the first one looks like verification**.

The countermeasure recorded above — *have a second party run the table on a shape the author did not choose*
— cannot reach this. No amount of re-running the author's inputs asks whether a different route exists. The
reader who catches it is not being more careful; they are being adversarial about the framing rather than
about the figures.

So before a claim of absence leaves the repository, ask the question the author did not:

- **Is there another route to the same answer?** Not another way to measure the gap — another way for a
  consumer to get what the gap withholds. Search the API surface for the *outcome*, not for the concept:
  the trivia store was not found by looking for docblocks, it was found by asking how else a rule could see
  one.
- **Volunteer what weakens the ask.** The filed issue names the workaround itself, in a section headed
  *Checked before filing*, and explicitly does not request that half. A maintainer who finds it in a minute
  and closes the whole report costs more than the paragraph does.

The positional rule this file already carries — *a value can be right and still be the answer to a question
nobody asked* — is right and was aimed one notch too low. **The whole document can be built on such a value**,
with every figure in it correct.

## A wrong "why" is worse than none

Reproduction steps, tests and the fix all get built on the stated cause. When you have not traced it, say
so rather than asserting it.
