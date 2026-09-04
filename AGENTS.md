# The baseline is debt, not a standard

`phpstan-baseline.neon` holds the errors this code arrived with when it moved out of research. It came down
from 559 by installing the Mago SDK so the runtime type-checks, replacing 92 calls to php-parser's deprecated
`getLine()`, typing the vocabulary tables and the descriptor shape everything flows through, extracting
`ExampleReader`, splitting the worst predicate method, and splitting the runtime into twelve classes.

**It now holds nothing but complexity.** Every type error is out, and the count is one command rather than a
figure here, because a figure here goes stale:

    grep -c 'identifier:' phpstan-baseline.neon
    grep -o 'count: [0-9]*' phpstan-baseline.neon | awk '{s+=$2} END {print s}'

Prefer emptying it over adding to it, and expect two kinds of entry when you do. It went from 58 errors to 14
in one pass, and most of that was a *description* problem rather than a code problem: a stale docblock winning
over a precise one, a guard behind a call the analyser cannot narrow through, a parameter typed weaker than
its only caller, an `isset()` on an offset that always exists. Nothing about those reads as a bug, which is
why they survived.

The rest were real and none had ever fired. `(string) $node->name` on sixteen comparisons is fatal for any
node whose name is computed; an `Expr` was used as an array key; a raw string was pushed into a `list<Stm>`
that `Backend::render()` would have rejected. Each needed a fixture written for it, because no rule in the
corpus reaches those lines — which is the argument for reading a baseline entry rather than trusting that a
green suite means the code is exercised.

## What remains is two classes, and only one of them can be split further

`Translator` scores 2337 against a limit of 80 and `Transpiler` 192 — read off the baseline's own
`complexity.classLike` entries, which are the two that exist. Those are the only two. Each grows with
every rule shape the vocabulary learns, so a rising number there is the cost of coverage rather than a
regression — what matters is that no *new* entry appears. Splitting methods inside a class does not move its
number, because the class total is roughly the sum of its methods.

### The runtime is out of the baseline, and how it got there transfers

`Runtime\Support` was 448. It is now a facade of one-line delegations over the classes beside it — `Tree`
held the navigation primitives first, then `Calls`, `Declares`, `Members`, `Names`, `Inheritance`,
`Attributes`, `Constants`, `Hints`, `Text`, `Types` and `Reflect`, and the split has kept going since: `ls
src/Runtime` is the count, and none of them is in the baseline, which is what "under the limit" means here.

Three things made that work, and they apply to the next split as much as they did to this one:

- **A facade keeps a shipped API still.** Every emitted plugin writes `use ...\Runtime\Support;` and calls
  `Support::x()`. Delegations cost nothing — a class of them scores 0 against a limit of 1, measured before
  the design depended on it — so the split changed no emitted byte.
- **Group by the call graph, not by what the docblocks are about.** Each group is the transitive closure of
  a seed, so it cannot call out of itself. Where two groups genuinely share something it moved to `Tree`
  rather than being duplicated.
- **A static bag splits and a stateful class does not.** `Support` had no shared state, so a moved method
  took its complexity with it. `Transpiler` did, which is why splitting it conserved the sum.

The four jobs `Transpiler` used to do are now three classes. `TranslationContext` holds the mutable state they
share (`$locals`, `$lines`, `$indent`, `$refinements`, `$nodeKind` and sixty-five more), `Emitter` turns
finished state into a file, and `Translator` does the translating. `Transpiler` orchestrates. Splitting a
class over the limit into two leaves two over it: 1961 became 169 plus 1792 at the time, and the sum is
conserved by construction. Both halves have grown with coverage since — see the figures above — which is
the point rather than a contradiction.

### The fourth boundary is a design change, not a move

Statement translation and expression translation cannot be separated by extraction. Inlining a helper
translates statements from inside expression resolution, and a loop body translates expressions from inside
statement translation, so the transitive closure seeded from either entry point is the same 203 methods.
Separating them means changing how helpers are inlined. Do not attempt it as a refactor.

### Verify a refactor byte-for-byte, across all three targets

Every reachable emission path now has a snapshot behind it, and this is what each one costs to break —
measured by mutating one token in each and reading which tests fail:

| emission path                 | what catches a changed byte           |
|:--|:--|
| php node hook                 | 21 `TranspilesToPhpTest` snapshots    |
| php whole-project pass        | 1 `TranspilesToPhpTest` snapshot      |
| php aggregate template        | 1 `AggregatesTypeCoverageTest`        |
| analyzer node hook            | 3 `TranspilesToRustTest` snapshots    |
| linter rule                   | 3 `TranspilesToLintTest` snapshots    |
| analyzer whole-run hook       | nothing — no rule in the corpus reaches it |

**Emit all three targets anyway.** The snapshots read 22 of the 58 rules in `tests/Fixtures/Rules` on the php
target and 3 on each Rust one, while the corpus emits 138 php, 42 analyzer and 33 linter files: a change that
moves only a corpus rule's shape moves none of them. So before touching anything, emit every rule in the four
corpus packages plus `tests/Fixtures/Rules` for all three targets, keep the tree, and `diff -r` after every
step. Zero diff is the pass condition, apart from the `--out` path the `mago.toml.snippet` embeds.

Build the baseline by copying the changed sources aside and restoring them from HEAD, not with `git
worktree`: a worktree's `vendor` symlink autoloads this repository's `src`, so both runs read the same code
and the diff is empty for the wrong reason.

A step that changes any emitted byte, snapshot or census line is wrong. Revert the step; never update the
snapshot to match it.

---

# Dependencies with known conflicts

## `rector/type-perfect` is deliberately absent

The repo-init baseline ships both `rector/type-perfect` and `tomasvotruba/type-coverage`. Since type-coverage
2.3 absorbed type-perfect's rules under the same namespace, both register
`Rector\TypePerfect\Reflection\MethodNodeAnalyser`, and PHPStan aborts before analysing with "Multiple
services of type ... found". `hihaho/phpstan-rules` v3.15.1 fixed this the same way. Do not re-add it.

## The rule packages are installed to be read, not run

All four — `symplify/phpstan-rules`, `hihaho/phpstan-rules`, `tomasvotruba/type-coverage`,
`tomasvotruba/cognitive-complexity` — are dev dependencies so that CI resolves the same corpus a contributor
does, which is what makes the census meaningful. `hihaho/phpstan-rules` is listed under
`extra."phpstan/extension-installer".ignore`, because registering a corpus's rules against this repository's
own source is not what a corpus is for. Add a new corpus package the same way.

---

# Git safety

Each of these failed *silently* — the build stayed green and the status looked plausible.

## Read `git status` before you commit

`git add -A` sweeps up stray artifacts and, through rename detection, can pair unrelated identical files.

`git add -A <pathspec>` is not the containment it looks like either. A `composer qa-check` run triggered a
`boost sync` post-update hook that deleted and regenerated `AGENTS.md` and `CLAUDE.md`; those deletions were
already staged, and the commit swallowed 429 lines of guidelines unrelated to the change. Recovery:
`git reset --soft HEAD~1`, `git restore --source=HEAD --staged --worktree <files>`, recommit.

The lesson is not "use narrower pathspecs" — it is to read the status output, especially after any command
that runs project hooks. `boost sync` runs on `post-install-cmd` and `post-update-cmd` here.

## A no-op `stash push` pops someone else's work

`git stash push -- src/ ; test ; git stash pop` only works while the fix is uncommitted. Once committed, the
push saves nothing and, with output silenced, looks like it worked — so the paired `pop` applies **and
deletes** whatever was already on the stack, and the BEFORE run it produces is identical to AFTER.

To verify an already-committed fix, revert by path instead: `git checkout HEAD~1 -- <files>`, confirm the
test fails, then `git checkout HEAD -- src/`.

## `git checkout -- <file>` discards uncommitted work, with no confirmation

Reverting a file to HEAD to undo a deliberate mutation also throws away every uncommitted change in it. A
mutation check had just proved a test caught its bug; `git checkout -- src/Transpiler.php` to restore the
code silently reverted the uncommitted feature with it, and the whole thing had to be reconstructed.

Before mutating a file on purpose, copy it aside and restore from the copy.

## Do not hand-edit generated files

`AGENTS.md`, `CLAUDE.md` and `.config/boost.php` are managed by boost sync. Edits belong in the source the
sync reads from, or they are silently reverted on the next `composer install`.

## Exclude the files you own, not the directory they sit in

An exclude entry does not apply to tracked files, so excluding a whole directory can give no protection
where protection is needed while hiding legitimate new files. Name what you own.

---

# Measurement and honest numbers

## Name what you compared against

The same result is 17x cheaper and 1.4x more expensive depending on the baseline. Transpiled rules against
**cold** PHPStan: 17x wall, 42x CPU. Against **warm** PHPStan: 2.1x wall but 1.4x *more* CPU, because
`mago analyze` has no result cache and redoes the whole job every run.

Every earlier figure quoted for this work (62x, 128x, 7.9x) was cold-only and read as general. A number
without its baseline is overstating the case.

## Name the baseline you rejected, not only the one you used

One clause more than the rule above, and it is what makes a number auditable by *yourself*.

Measuring the cost of a mago reverse index: against a plain run with no extension host it is 144 ms on a
0.63 s run, 23% and an argument against building it. Against a host that does nothing it is 12% of wall and
4% of CPU, and starting the host costs more than the index does. Same measurement, opposite conclusion, and
the wrong baseline was the more obvious one to reach for.

That one was caught before publishing, and the reason it was caught is that two candidate baselines were in
hand, so the choice was visible. The errors that needed an outside reader were the ones where nobody had
written a second option down: a census parser that dropped a parenthetical, and a benevolent-union census
that counted conditions when the `!` operand was the position the rule works in. In both, one frame looked
like the only frame.

So write "measured against the no-op host, **not** the plain run". That sentence carries the question with
it. "Measured against the no-op host" carries only the answer, and an answer cannot be re-examined by the
person who gave it.

## State n per row

"Best of three" across a table where one row is n=1 is not best of three. A 45-second run does not get
repeated as often as a 2.5-second one; that is fine, but the table has to say so. Report the spread when it
is wide, and say whether the machine was contended.

## Give the marginal cost, not only the total

"mago plus the rules" answers a different question from "what do the rules cost". Measure the engine alone
as well: here the rules add 0.18s wall and 0.94s CPU on 7701 files.

## A count belongs to its configuration

`emitted: 4` and `emitted: 3` were both correct, for different targets. Print the configuration next to the
number, in the tool itself where possible, or a reader will conclude the tool is inconsistent.

## CPU and counts survive contention; wall clock does not

Prefer them when the machine is shared and coordination is not possible.

---

# The two invariants

Both hold for every change to the transpiler. Weakening either has already shipped broken plugins.

## The emitted output is the contract, not the source

Every target has a reviewed snapshot under `tests/Fixtures/expected`, `tests/Fixtures/expected-rust` and
`tests/Fixtures/expected-lint`, and `tests/Fixtures/expected/census.md` records what happens to every rule in
the packages this repository installs — 190 of them across seven packages as it stands, and the file prints
its own list of versions so the number can never be read without its corpus. A
refactor that changes what is emitted fails those, which is the point: pint and rector have each rewritten
`src/Transpiler.php` wholesale, and the snapshots proved the output was untouched.

If a snapshot or the census changes, decide whether the new output is right **before** updating it, and say
why in the commit.

## Refuse rather than approximate

The generator refuses a construct outside `Vocabulary`, naming it and its line, and `PhpBackend::checked()`
refuses any operand it was handed and could not render. Both checks are load-bearing. Weakening them
produced, at different times, files that did not parse and files that parsed *while still containing Rust* —
the second kind is worse, because it loads and misbehaves.

A plausible-but-wrong rule is the failure mode to design against, because you would trust it. And some
refusals are simply the right answer: a node hook receives inferred types only at the positions it asks for
through `FileAnalysisRequirement`, so where the subject is not one of those positions, refusing is correct.

---

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

## A wrong "why" is worse than none

Reproduction steps, tests and the fix all get built on the stated cause. When you have not traced it, say
so rather than asserting it.

---

## AskUserQuestion Phrasing

When writing an `AskUserQuestion` question, option labels, or option descriptions, **avoid first- and second-person pronouns** — `I`, `me`, `my`, `we`, `our`, `you`, `your`. In that tool the user is reading a question *from* the assistant and answering it, so the roles are inverted and these pronouns are ambiguous: the reader cannot tell whether `I`/`my` means the assistant or themselves, nor whether `you`/`your` means them or the assistant.

Name the actor explicitly instead — "the assistant" (these guidelines are shared across agents, so avoid hard-coding a product name like Claude or Copilot) and "the user" (or a concrete role) for the person answering — or rephrase to drop the pronoun entirely.

```text
❌ "Which approach do you want me to take?"
❌ "Should I keep the existing tests you wrote?"

✅ "Which approach should the assistant take?"
✅ "Keep the existing tests, or replace them?"   (pronoun dropped)
✅ "Should the assistant keep the tests already in the repo?"
```

This applies to every part of the question payload: the `question` text, each option `label`, and each option `description`.

---

## Fixing PHPStan Errors

When fixing a PHPStan error, first decide whether it represents a runtime bug a test could catch — and if so, write that test before the fix.

### Process

1. **Assess testability** — does the error represent a runtime bug a test could reproduce (a wrong argument type, a missing method, an incorrect return type used downstream)?
2. **Write the test first** — if a test can catch it, write a failing test that reproduces the error before applying the fix.
3. **Fix the code** — apply the fix so both the PHPStan error and the new test pass.
4. **Verify both** — confirm PHPStan reports no error and the test passes.

### When to Write a Test

Write a test when the PHPStan error indicates a fault that would surface at runtime:

- A method call on a value of the wrong type
- Missing or incorrect arguments to a function or method
- A return-type mismatch that would break callers
- Accessing a property or method that does not exist
- Any type error that would manifest as a runtime exception

### When to Skip the Test

Skip the test when the error is purely static and cannot cause a runtime failure:

- Missing return-type declarations
- PHPDoc mismatches with no runtime impact
- Unused variables or imports
- Generic-type parameter issues

---

## Signed Commits

Applies **only when the repository has commit signing enabled** (e.g. `git config commit.gpgsign` is `true`, or a `user.signingkey` / `gpg.format` is set). If signing is not enabled, this guideline does not apply — commit normally.

### Never fall back to an unsigned commit

When signing is enabled, every commit must be signed. If the signing backend or agent (1Password, `gpg-agent`, `ssh-agent`, a hardware key, etc.) is unavailable, locked, or not responding:

- **Stop and surface the failure** to the user with the exact error.
- **Do not** retry with `--no-gpg-sign`, unset `commit.gpgsign`, or otherwise produce an unsigned commit to "get past" the problem.

A missing signature is a blocker to resolve (unlock the agent, re-authenticate 1Password, plug in the key), not a step to skip. Let the user fix the signing setup, then commit signed.

---

## Verification Before Completion

Before claiming any work is complete or successful, run the verification command fresh and confirm the output. Evidence before claims, always.

### Claims About How the Code Behaves — Trace, Don't Assume

A claim about **how the code currently behaves** — a root cause, an existing mechanism, or present behavior — in a spec, PR, commit message, code-review finding, issue, comment, or answer must be traced to the actual code (or observed at runtime) **before** you write it, never asserted from plausibility. (This governs statements of *fact about the present code*; the *intended* future behavior a spec or PR proposes is fine when it's clearly framed as a requirement, proposal, or decision — not disguised as a fact about what already exists.) Every illustrative example must be one you actually observed, never invented to fit a guess. A wrong "why" is worse than none: reproduction steps, tests, QA testables, and the fix itself all get built on the stated cause, so one unverified guess corrupts everything derived from it. When you have not traced it, say so — mark it `NEEDS-CONFIRMATION` or ask — rather than asserting. (A ticket once claimed a list was "sorted by display name" and backed it with an example that could not occur; the sort actually keyed on an internal identifier — one grep away. The trace is cheap; the false premise is not.)

### Required Before Any Completion Claim

1. **Run** the relevant command (in the current message, not from memory)
2. **Read** the full output
3. **Confirm** it supports the claim
4. **Then** state the result with evidence

| Claim            | Required verification                                            |
|------------------|------------------------------------------------------------------|
| Tests pass       | The project's test command, output showing 0 failures            |
| Code style clean | The project's formatter/style checker, output showing no changes |
| Linting clean    | The project's linter, output showing 0 errors                    |
| Types check      | The project's type checker, output showing 0 errors              |
| Bug fixed        | The previously failing test now passes                           |
| Feature complete | All related tests pass                                           |

Use the project's own commands — check its `composer.json` / `package.json` scripts, CI config, or sibling docs to find them. Do not assume a specific tool.

### Delegating the checks

Where the project has dedicated quality-check skills synced, delegate to them — `backend-quality` for backend files, `frontend-quality` for frontend files, both when a change spans both. Otherwise, run the project's own equivalent commands directly.

### Never Use Without Evidence

- "should work now"
- "that should fix it"
- "looks correct"
- "I'm confident this works"

These phrases indicate missing verification. Run the command first, then report what actually happened.

---

## Voice — Which Rule, Which Surface

This table decides which rule applies to a piece of text. Never apply both to the same words, and never guess.

| Surface | Rule |
|---|---|
| Chat replies to the user | Simplified Technical English |
| PR titles, descriptions, checklists | Simplified Technical English |
| PR review comments and replies to reviewers | Simplified Technical English |
| Issue and ticket descriptions, comments, QA testables | Simplified Technical English |
| Spec files | Simplified Technical English |
| `AskUserQuestion` questions, options, descriptions | Simplified Technical English — plus the pronoun rules in the `AskUserQuestion Phrasing` guideline, when the project has it |
| Commit messages | Simplified Technical English — an issue key the project's commit format requires stays as it is |
| Text an end user reads — in-app copy, translations, release notes, help text, seed content | The project's own tone-of-voice rules, not this guideline |
| Suggested translation strings inside an issue or ticket | The project's own tone-of-voice rules — the prose around them stays Simplified Technical English |
| Code and code comments | Neither — the language guidelines own those |
| Prose the user asks for in a named style, or an artifact whose own skill defines its voice — `humanizer`, `readme`, `release-notes` | That instruction or skill wins. This guideline does not override it |

A surface the table does not list gets Simplified Technical English, unless an end user reads it. Then it gets the project's tone-of-voice rules. A project without documented tone-of-voice rules gets Simplified Technical English everywhere.

This guideline governs **how a sentence is built**. It never overrides what a document is allowed to say: an issue-format doc still owns issue content, and a PR template still owns its sections.

### Simplified Technical English

**Write in ASD-STE100 Simplified Technical English.** Say the same thing in fewer, simpler words.

- One idea per sentence. Keep procedural sentences to 20 words or less, descriptive sentences to 25 or less.
- Use the active voice. Name the actor. Use the passive only when the actor is unknown.
- Use simple tenses only — simple present, simple past, simple future, infinitive, imperative. No complex constructions built from auxiliary verbs.
- Use one word for one meaning. Use the same word for the same thing every time — do not vary it for style.
- Keep articles (`the`, `a`, `an`) and other small words that make a sentence clear. Simplified is not clipped.
- One topic per paragraph, six sentences at most. Use a list when there is more than one item.
- Cut filler, hedging, and repetition. Do not restate the question or summarise what you are about to say.
- Give the answer first. Add detail after it, and only if the reader needs it.
- Use everyday words. Write "use", not "utilise"; "help", not "facilitate". Keep technical terms exact — a class name, a flag, or an error message is quoted as it is.
- Write Latin abbreviations out: "for example", not "eg"; "that is", not "ie"; "and so on", not "etc".
- Do not shout. No exclamation marks, no capitals for emphasis, and no bold used only to raise the volume. Structural bold that a template defines — `**Before:**`, `**Expected:**`, a table header, a labelled line — is not emphasis and stays.
- No metaphors, no clichés, no jokes that carry meaning the plain sentence does not.

The sentence limits, the tense list, the article rule, and the paragraph limit come from the ASD-STE100 writing rules. The everyday-words, Latin-abbreviation, no-shouting, and no-metaphor rules come from the GOV.UK content style guide.

---

# Package Boost Guidelines

These guidelines replace Laravel Boost's default foundation for
repositories that ship as Composer packages — Laravel-targeted or
framework-agnostic. The framing, tooling, and trade-offs differ from
application development; follow this version when working inside a
package codebase.

## Foundational Context

This codebase is a **Composer package**, not an application. The rules
below hold regardless of which framework (if any) the package targets.

- There is no `app/`, `bootstrap/`, `routes/`, `.env`, or database by
  default. Tooling that assumes an application context (e.g. running
  `php artisan` against the package itself) does not apply.
- The primary artefact is the package's public API — entry-point
  classes, service providers, exposed contracts. Everything else is
  scaffolding.
- Downstream consumers depend on this package via Composer. Every
  public change is a user-facing API change governed by semver.
- `composer.json` is the source of truth for supported PHP versions
  and any framework constraints. Check `require.php` (and any
  `require.<framework>/*` entries) before using version-specific
  features.

## Source Layout

- `src/` — package source, PSR-4 autoloaded per `composer.json`
- `tests/` — Pest or PHPUnit suite
- `config/` — publishable defaults shipped with the package, when
  applicable
- `resources/` — views, translations, Boost skills / guidelines, when
  applicable
- `database/migrations`, `database/factories` — only if the package
  ships them
- `workbench/` — developer-only Testbench scaffolding when Testbench
  is in use; never shipped

Check sibling files before inventing structure. Do not introduce new
top-level directories without a clear reason.

## Tests Are the Specification

The package has no running application to click through. Tests are how
behaviour is pinned down.

- Write tests alongside any behavioural change.
- Do not create "verification scripts" when a test can prove the same
  thing.
- Run the project's configured test runner (`vendor/bin/pest` or
  `vendor/bin/phpunit`) before claiming a change is done.

## Public API Discipline

- Every `public`, `protected`, or exported symbol is part of the
  package's surface. Breaking changes require a major version bump.
- Prefer `final` classes and `private`/`@internal` markers for
  anything not intended for extension.
- Keep config keys, published asset paths, and service container
  bindings stable across patch and minor versions.

## Conventions

- Match existing code style, naming, and structural patterns — check
  sibling files before writing new ones.
- Use descriptive names (`resolvePublishDestination`, not `resolve()`).
- Reuse existing helpers before adding new ones.
- Do not add dependencies without approval; every new `require` is a
  constraint downstream consumers inherit.

## Extending boost-core

If your package authors a custom `FileEmitter` (to write a file like
`.mcp.json` into the host during `boost sync`), declare the
`boost-extension` tag in your `boost.php` `withTags([...])`. That pulls
the `writing-file-emitter` skill — gated off by default so consumers
who do not extend the engine don't carry it, which is why an
emitter-authoring package has to opt in explicitly. The same tag pulls
`skill-authoring` for writing boost-family skills.

## Documentation Files

Only create or edit documentation (README, CHANGELOG, docs/) when
explicitly requested or when a behaviour change requires it.

## Replies

Be concise. Focus on what changed and why. Skip restating what the
diff already shows.

---

# Release Automation

Conventions the package-boost family shares for release flow. The
procedural detail lives in the `pre-release` and `release-notes`
skills — loaded on-demand, not pinned here.

## CHANGELOG is CI-managed

`.github/workflows/update-changelog.yml` prepends the release body to
`CHANGELOG.md` on `release: released` and commits to the release's
target branch (typically `main`). Don't hand-edit `CHANGELOG.md` as
part of a release. Post-release typo fixes are committed directly.

## Release notes live in `internal/release-notes-<version>.md`

`internal/` is gitignored — drafts stay local. The notes file becomes
the release body. The first line pins the green commit so the pre-tag
gate can fail closed on drift:

```
<!-- verified-sha: <full sha> -->
```

## Tag and title

- Tag: bare version (`0.7.0`) — Composer and Packagist read the tag.
- Release title: `v`-prefixed (`v0.7.0`) — cosmetic.
- Notes file: bare (`internal/release-notes-0.7.0.md`).

## Agent handoff

Agents stop at the ready-to-tag handoff. The user runs the pre-tag
gate and publishes the release (GitHub UI, `gh`, or otherwise). See
the `pre-release` skill for the full procedure and the no-release-create
rule.
