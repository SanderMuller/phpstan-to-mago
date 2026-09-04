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
failure: three times in one session a measurement was an artifact of the thing measuring it, and reading the
source caught none of the three.

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
