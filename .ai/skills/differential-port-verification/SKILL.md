---
name: differential-port-verification
description: "Verify that a rule ported, transpiled or reimplemented onto a second engine agrees with the original, before trusting or publishing any claim about it. Activates when: validating transpiled or ported rules, comparing two analysers on the same code, dogfooding generated plugins on a real project, checking a reimplementation matches upstream, measuring one tool against another, or when the user mentions: differential, dogfood, agreement, port verification, does it match, same findings, transpiled rules, cross-engine."
---

# Differential port verification

A rule that runs on a second engine is worth nothing until it is shown to make the *same decisions* as the
original. This procedure produces that evidence, or a named reason it could not.

The failure mode to design against is a plausible-but-wrong port, because you would trust it. "It emitted"
and "it ran" are not results.

## 1. Establish what is under test

Emit or build with the target you are actually going to use, and record the count **with its
configuration**. A count without its target or its flags invites the reader to think two correct numbers
are an inconsistency.

Never quote a dry-run, survey or `--dry-run` count as the result. Re-derive it from the real run.

## 2. Control run first: prove the port can fire at all

Before pointing anything at a real corpus, run it over a fixture that is known to violate every ported rule
and confirm each one reports. Zero findings on real code means nothing until you know the rules load and
fire.

This step has caught: plugins silently not loading, a filter keyed on the wrong identifier format (the real
code was namespaced `host/rule/identifier`, not the bare identifier), and helpers that were quietly wrong.

## 3. Rule out engine-level blindness

Ask what the second engine might be *skipping* rather than deciding. Check its own diagnostics for
unresolved symbols, skipped files or missing autoload. Then construct the minimal pair that separates
"decided no" from "never looked" — same violation, once in code the engine fully understands and once in the
shape you suspect it skips — and run it.

Mago skips the body of a class whose parent it cannot resolve, and reported 6395 unresolvable classes on a
corpus. Two files settled that it does not affect syntax-level hooks. Reading the source would not have:
it read correctly at every line.

## 4. Choose a corpus that can actually disagree

- **Do not use the consumer's own source** if it already enforces these rules. It is clean by construction,
  both tools report zero, and agreement on zero is not evidence.
- **Prefer code nobody wrote for you** — a vendored dependency tree, another repo. Thousands of files, and
  no incentive to satisfy your rules.
- Run **both tools over an identical, counted path list**. Two metrics gathered on different corpora is a
  published-mistake generator: one such pair silently included an extra vendor directory and mislabelled
  1746 files as 2421.
- Filter both sides to the ported rules only.

## 5. Compare site by site, not by count

Equal totals with different sites is a failure that looks like a success. Compare as
`(rule, file, line)` triples and print three numbers: agree, only-original, only-port.

Normalise the conventions, and state which you normalised — line bases differ (Mago's JSON `line` is
0-based), paths may be absolute on one side, and identifiers may be namespaced. If all sites match on the
first comparison, that is also evidence the convention is right.

Investigate every disagreement individually. A disagreement is either a real defect in the port or a real
defect in the original; it is never noise.

## 6. Determinism

Re-run at several worker or process counts and confirm the finding set is identical. A port that aggregates
per-file state can pass at one worker and diverge at eight.

## 7. Report performance with both baselines

For any tool with a result cache, measure it **cold and warm** and give both. The same change was 17x
cheaper than cold and 1.4x *more* expensive than warm. Give `n` per row, note the spread, and say whether
the machine was contended — CPU and counts survive contention, wall clock does not.

Also give the *marginal* cost of the rules over the engine alone, which is the number that answers "what do
these rules cost".

## 8. Write down what is not covered

Name the refusals and say which are correct-forever versus which are pending work. Partial coverage plus
named refusals is a complete, honest result. A silent cap, a top-N, or a skipped rule reported as coverage
is not.

## Gate

Nothing is verified until: the control fired, engine-blindness is ruled out by experiment, both tools ran on
an identical counted corpus, the comparison is site-by-site with zero unexplained disagreements, the finding
set is stable across worker counts, and the perf claim names its baseline. Persist the harness and the
numbers outside the session scratchpad, or the next run starts over.
