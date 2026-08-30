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

Reachability is the usual gap. "The field is there", "the method answers", "the class resolves" say nothing
about what the value *means*, whether two values are comparable, or whether the question is the one the rule
asks. Where a value is going to be compared against something, probe the comparison.

## A wrong "why" is worse than none

Reproduction steps, tests and the fix all get built on the stated cause. When you have not traced it, say
so rather than asserting it.
