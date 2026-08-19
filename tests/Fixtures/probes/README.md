# Probes

A probe is a two-file corpus for one identifier that the example pairs cannot exercise, run through the
corpus differential rather than through the per-rule gate:

```bash
php tests/Support/run-corpus-differential.php <consumer-root> --paths=$PWD/tests/Fixtures/probes/<probe>
```

`PositionalFlagArgument` exists because `hihaho/phpstan-rules` configures that pair with first-party
namespaces — `App`, `Database\Factories`, `Tests` — and the example pairs live in `Examples`, so both tools
are correctly silent there and the identifier scores zero on every corpus. These two files sit in `App`, hold
one positional flag in a constructor call and one in a nullsafe method call, and a named argument that must
stay silent. Both engines report both sites and agree.

Nothing here is analysed by the test suite; a probe is evidence for a differential run, kept so the next run
does not have to invent it again.
