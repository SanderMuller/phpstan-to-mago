# The two invariants

Both hold for every change to the transpiler. Weakening either has already shipped broken plugins.

## The emitted output is the contract, not the source

Every target has a reviewed snapshot under `tests/Fixtures/expected` and `tests/Fixtures/expected-rust`, and
`tests/Fixtures/expected/census.md` records what happens to all 129 rules in the four rule packages. A
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
