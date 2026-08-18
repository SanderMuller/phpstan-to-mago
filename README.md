# phpstan-to-mago

Transpile PHPStan rules into [Mago](https://github.com/carthage-software/mago) analyzer plugins.

A PHPStan rule receives `(Node, Scope)` and reaches into thousands of classes: `Type`, `TypeCombinator`,
`ReflectionProvider`, the accessory types. Mago exposes none of that object graph to PHP, so a rule cannot
simply be handed to it at runtime. What a rule's *decisions* usually reduce to, though, is a chain of
guards over the syntax tree plus a handful of questions about the enclosing class, and those translate.

This reads a rule's PHP source and writes a Mago SDK plugin.

```bash
composer require --dev sandermuller/phpstan-to-mago
vendor/bin/phpstan-to-mago --target=php --out=build src/Rules/ForbiddenStaticConstFetchRule.php
vendor/bin/phpstan-to-mago --survey vendor/hihaho/phpstan-rules/src
```

```
--target=php       a Mago SDK plugin, an ordinary composer library, the default
--target=analyzer  a Rust analyzer plugin, which has to be compiled into Mago
--target=linter    a Rust lint rule, which has to be compiled into Mago
--out=DIR          where to write, defaulting to the current directory
--examples=DIR     PHP files the linter target reads its good and bad examples from
--survey           report what each rule would need, writing nothing
```

A path is either a rule file or a directory. A directory is walked for rules, and files that cannot be
rules (traits, abstract bases) are skipped rather than reported as refusals. A file named on the command
line is taken as a rule, and refused by name if it turns out not to be one.

```
  EMIT    ForbiddenStaticConstFetchRule

emitted: 1, refused: 0
```

The emitted plugin imports its runtime from this package, so a generated rule needs nothing else:

```php
final class ForbiddenStaticConstFetchRule implements Plugin, NodeAnalysisHook
{
    public function getTargets(): array
    {
        return [NodeKind::ClassConstantAccess];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $node = $context->node;

        if (! Support::isName(Support::classPart($context, $node))) {
            return;
        }
        // ...
    }
}
```

## It refuses rather than approximates

A rule using a construct outside the vocabulary is refused, naming the construct and its line:

```
  REFUSE  NoWithOnStubRule: the inferred type of var_method_call.object is not a position the SDK exposes
```

That is the design, not a limitation to work around. A plausible-but-wrong rule is worse than no rule,
because you would trust it. Two consequences worth knowing:

- **`emitted` on its own means nothing.** The generator refuses what it cannot translate, *and* the backend
  refuses any operand it was handed and could not render. Without the second check the tool once reported
  ten rules emitted where six did not parse and two parsed while still containing Rust.
- **Some refusals are the right answer.** A node hook receives inferred types only at positions it asks
  for through `FileAnalysisRequirement` (the target, the receiver, the arguments), while a PHPStan rule can
  ask about any sub-expression. Where the subject is not one of those positions, refusing is correct.

## What it can translate

Measured on 23 real rules from `symplify/phpstan-rules` and a few of our own: **20 emit, 3 are refused.**
Of the three, two ask for a type at a position the SDK does not expose, and one duplicates a check Mago
already performs natively, so it should not be ported at all.

On 1090 files of real vendor code the 20 emitted rules and PHPStan produce **the same 64 findings, with no
disagreements**, and the same findings at 1, 2, 4 and 8 worker processes.

The vocabulary covers guard chains, `foreach` with an inline report, `sprintf` messages, inlined private
helpers, string and integer comparisons, `instanceof` narrowing into a binding, class-hierarchy questions
about the enclosing class, and the receiver's inferred type. What is not covered is refused by name.

## Performance

The point of running rules on Mago is cost, so here it is honestly. Best of three, one machine, the same
1090 files, the same 20 rules and the same 64 findings:

| | wall | CPU |
|:--|--:|--:|
| mago, engine only | 0.10s | 0.59s |
| mago + the 20 transpiled rules | 0.25s | 1.14s |
| PHPStan, cold | 5.86s | 33.13s |
| PHPStan, warm result cache | 0.59s | 0.56s |

Against a **cold** run it is 23x faster on wall clock and 29x cheaper on CPU. Against a **warm** PHPStan it
is still 2.4x faster on wall clock but costs roughly twice the CPU, because `mago analyze` has no result
cache and does the whole job every time. So: much cheaper in CI, faster in an editor loop, not cheaper on
CPU once PHPStan's cache is warm. Any claim about this that does not say which of the two it means is
overstating the case.

## The Rust target

`--target=linter` and `--target=analyzer` emit Rust instead. Generated Rust only runs compiled
inside Mago's own crate, so it cannot ship as a package and is not what this tool is for. It is kept
because both targets share the whole body translation, which makes it a useful check that a change to that
has not altered behaviour.

## Contributing

`composer qa-check` runs the lot. Two invariants matter more than the rest, and both are in `CLAUDE.md`:
the emitted output is the contract and is snapshotted per target, and anything the vocabulary does not
cover is refused rather than approximated.

## Requirements

PHP 8.3 for the transpiler. Generated plugins target the Mago PHP SDK and run under Mago 1.47 or later.

## Credits

- [Sander Muller](https://github.com/SanderMuller)

## License

MIT. See [LICENSE](LICENSE).
