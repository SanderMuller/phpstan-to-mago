# .ai: how we work on this package

Read this before substantive work. `CLAUDE.md` and `AGENTS.md` carry the general engineering guidelines
(they are managed by boost sync — do not hand-edit them); this directory carries what is specific to *this*
package and what we have learned the hard way.

## Where a new learning goes

| Kind of learning | Home |
|---|---|
| A standing rule that applies across tasks | `.ai/guidelines/` |
| A "how X works here" explanation | `.ai/docs/` |
| A repeatable procedure that activates on a task | `.ai/skills/<name>/SKILL.md` |

Guidelines are rules, docs are explanations, skills are procedures. Skills are symlinked into
`.claude/skills/` so Claude Code discovers them; edit the file under `.ai/skills/`.

## Docs

- `docs/architecture.md` — why a transpiler rather than a runtime shim, the pieces, **the two invariants**,
  the gate, and the traps that have cost time.
- `docs/rule-shapes.md` — what translates today, and the one shape that blocks a real rule package. Read
  this before trying to raise the emit count.
- `docs/dogfooding.md` — what the differential runs against real projects showed, the performance numbers
  with both baselines, and how configuration reaches a generated plugin. The corpus differential lives in
  `tests/Support/CorpusDifferential.php`; upstream drift is watched nightly by
  `.github/workflows/upstream-parity.yml` against the census in `tests/Fixtures/expected/census.md`.
The spec these three were written against — `specs/trustworthy-mvp.md`, which made `emitted` mean `works` —
is done and removed. What it established lives in the three docs above; its git history has the rest.

## Guidelines

- `guidelines/verification.md` — evidence before claims.
- `guidelines/measurement.md` — honest numbers.
- `guidelines/git-safety.md` — the silent, destructive git failures.

## Skills

- `differential-port-verification` — verifying that transpiled rules agree with PHPStan before trusting or
  publishing any claim about them.

## The two things that matter most

1. **The emitted output is the contract**, snapshotted per target. A refactor that changes it fails the
   snapshots, which is the point.
2. **Refuse rather than approximate.** A plausible-but-wrong rule is worse than no rule, because you would
   trust it. Never weaken a refusal to raise the emit count.

Both are expanded in `docs/architecture.md`.
