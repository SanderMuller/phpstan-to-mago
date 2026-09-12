# Changelog

All notable changes to `sandermuller/phpstan-to-mago` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.1.0 - 2026-09-07

<!-- verified-sha: afc1c29cb474090fc855c4d05b1701fa77fc1f0b -->
First tagged release. Pre-1.0: the emitted plugin shape and the CLI surface may still change.

### Added

- `phpstan-to-mago` transpiles a PHPStan rule's decisions into a Mago plugin — guards over the syntax tree plus questions about the enclosing class. The rule object itself does not travel; it reaches into classes Mago does not expose to PHP.
- Three targets. `--target=php` emits a Mago SDK plugin as an ordinary Composer library and is the only one that installs. `--target=analyzer` and `--target=linter` emit Rust for Mago's own bundled-plugin registry, which has no registration path from outside Mago's tree.
- A construct outside the vocabulary is refused by name and line, and the backend refuses any operand it was handed and could not render. Nothing is approximated — a plausible but wrong rule is worse than no rule.
- `--survey` reports what each rule would need without writing anything; `--from-config=DIR` works on the rules a project registers rather than the ones its packages ship; `--status` reports what the installed packages transpile to and writes a page under `--out`.
- Each target writes its own subdirectory of `--out`, with a `generated/manifest.json` naming every rule's identifier, messages and defaults.
- A rule taking constructor values gets them on the generated plugin at the package's own defaults, overridable in the worker. A rule taking a PHPStan service is refused — no worker can supply one.
- 104 of the 209 rules registered by the seven rule packages this repository installs emit on the `php` target. `phpstan/phpstan-deprecation-rules` is the first package with both of its rules emitting, though it also ships five usage extensions that are not rules and have no port.
- Five of `tomasvotruba/type-coverage`'s metrics are carried as whole-project passes with the measurement reimplemented, since Mago has no collector. An aggregate is emitted only once its numbers agree with the real rule on a real project, and it carries its measured bound.
- Every emitted rule is checked by running the real `mago` against real PHPStan over an example pair and comparing line and message. A rule that emits and reports nothing fails.
- `tests/Fixtures/expected/census.md` records the outcome and the needs of every rule in the installed packages, generated and asserted against, with the corpus versions printed beside the counts.
- Each divergence found between the two engines is pinned as a minimal case, so it survives the corpus moving on and goes red in either direction.

### What's Changed

* Bump actions/upload-artifact from 4 to 7 by @dependabot[bot] in https://github.com/SanderMuller/phpstan-to-mago/pull/8

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/SanderMuller/phpstan-to-mago/pull/8

**Full Changelog**: https://github.com/SanderMuller/phpstan-to-mago/commits/v0.1.0

## [Unreleased]
