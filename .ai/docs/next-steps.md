# Next steps

State as of 2026-08-19, at commit `68a3907`. Every number here came from running the tool, not from memory.
Re-run the survey before trusting any of it: `php bin/phpstan-to-mago --survey --target=php <rules-dir>`.

## Where it stands

| | |
|:--|:--|
| target rules | 23 from `symplify/phpstan-rules` and our own: **20 emit, 3 refused** |
| agreement with PHPStan | 64 findings / 64 agreements on 1090 files; 25/25 on 7701 files |
| a real rule package | `hihaho/phpstan-rules`: **0 of 20** |
| tests | 27, all passing |
| PHPStan baseline | 48 entries, all of them cognitive complexity |
| remote | `SanderMuller/phpstan-to-mago`, `main` in sync |
| released | no tags, no Packagist entry, `CHANGELOG.md` has an empty `[Unreleased]` |

The 0 of 20 is the thing worth working on. It is the only evidence that says whether this tool is useful on
rules nobody wrote with it in mind.

## The frontier

All 20 refusals, grouped by verified cause. "Verified" means the callee or expression at the refusal line
was resolved, not inferred from the message.

| n | refusal | cause | difficulty |
|--:|:--|:--|:--|
| 5 | `unknown local $this` | a constructor-injected property passed to the helper, e.g. `$this->unsafeMethodsLookup` | needs the configuration design |
| 6 | `X() is assigned but does not build a rule error` | the helper delegates instead of building: `return $this->flagErrorFromSite($this->flagSiteForMethodCall(...))` | deep, see below |
| 3 | `condition outside the vocabulary: Expr_Isset` | `isset($lookup[strtolower($name)])` in a guard | small |
| 2 | `method call outside the vocabulary ->isRelative()` | php-parser `Name::isRelative()` | small |
| 1 | `Stmt_Foreach` in an inlined helper | loops are translated in a rule body but not in a helper body | medium |
| 1 | `more than one distinct identifier in one rule` | one plugin, several report codes | medium, design |
| 2 | collector / `CollectedDataNode` | cross-file aggregation | **correct forever, do not chase** |

### Recommended order

**1. `isset()` in a condition, and `Name::isRelative()`.** Two vocabulary entries. Cheapest real progress,
and `isset($lookup[strtolower($name)])` is a shape that will keep recurring. Note that it needs the *array*
to be resolvable, so for the validation rules it collides with the configuration item below; the debug rules
should fall out first.

**2. Configuration.** Five rules pass `$this->someInjectedProperty` into their helper and there is no
mechanism for it. `docs/dogfooding.md` records the checked options: `PluginDefinition` has no settings, but
`ExtensionHostConfiguration` exposes `command` argv and `environment`, and the worker process is ours. The
open question is not *whether* config can reach a plugin but *where the values come from* — reading the
consumer's PHPStan neon at transpile time and baking them in, or emitting a plugin that reads env or argv.
The second keeps the generated file project-independent and is the better default; it needs a documented
convention and worker plumbing. **Decide this before writing code**, because it changes the CLI surface:
transpiling against a project rather than against a file.

**3. Helper-to-helper delegation.** `buildsRuleError()` only sees builders in the helper it is given, and
these helpers delegate. Following `$this->` one more level is easy; the rest is not. The real chain is
`positionalFlagErrorForMethodCall` → `flagErrorFromSite($site)` → `flagError($site['paramName'])`, where
`$site` is a `?array{method, argIndex, paramName, value}` built by `flagSiteForMethodCall` →
`instanceCallFlagSite`, and the decision gates on a method's **declaring class**. So it needs an
intermediate array shape passed between helpers, and reflection the SDK may not expose. Expect this to end
in a refusal for a good reason. Do not start here.

**4. Loops in helper bodies, and multi-identifier rules.** Both real, neither blocking as much as the above.

## Do not

- **Do not weaken a refusal to raise the emit count.** Partial coverage plus named refusals is the finished
  product, per `../guidelines/verification.md`. A plausible-but-wrong rule is worse than no rule.
- **Do not quote a survey count without its target**, and never quote a survey count as a result. Emitting
  is one thing, rendering another; the backend refuses at render time.
- **Do not chase the collector pair.** Mago's node hooks see one file at a time. That refusal is correct.

## The gate for any change here

1. `composer qa-check` (rector, pint, PHPStan, gitattributes, tests).
2. All three targets emitted against the research tree, byte-compared: **php 20/20, analyzer 23/23,
   linter 10/10**. Both Rust targets share the whole body translation, which is why they are kept — they are
   the check that a change to translation altered nothing.
3. **Run the emitted plugin under mago.** This is not optional and not covered by the above. A version of
   statement-position inlining passed every static check, loaded, ran, and silently found nothing, because
   an unconditional exit sat in front of the report. Only running caught it.
4. Prefer emptying the baseline over adding to it. A genuinely new capability may cost class complexity;
   say so in the commit rather than quietly regenerating.

## Non-code, open

- **`README.md` has an uncommitted change** documenting the per-target output directories and the new
  `(target: php)` line. It reads correctly; it is not mine to commit.
- **No release.** No tag, nothing on Packagist, `[Unreleased]` is empty. The README already tells people to
  `composer require --dev sandermuller/phpstan-to-mago`, which will not resolve until that is done.
- **Mago issue #2219** (duplicate `undefined-variable`) is filed and awaiting a response.
- **The repo-init scaffold ships a broken PHPStan setup** — `rector/type-perfect` and
  `tomasvotruba/type-coverage` both register `MethodNodeAnalyser`, so analysis aborts in every scaffolded
  repo. Removed here and recorded in `CLAUDE.md`. Still unreported upstream, and it affects every new repo.
