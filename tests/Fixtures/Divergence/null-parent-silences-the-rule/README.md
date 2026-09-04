# A null parent silences the rule

**Pins a fact, not a divergence — and the one row that does diverge is not the rule.**

    port      Subject.php:30  symplify.noConstructorOverride    the control
    original  Subject.php:17  class.notFound                    the unresolvable parent
    original  Subject.php:30  symplify.noConstructorOverride    the control

**Read the `DIVERGE` carefully.** The two engines agree about the rule — both report the control at line 29.
The single divergent row is `class.notFound`, a core PHPStan diagnostic mago has no equivalent for. That is
not this rule disagreeing; it is one engine having a diagnostic the other lacks.

## The fact it pins

A rule reading a parent that nothing resolves is *handed a valid `ClassReflection` whose `getParentClass()`
answers null*, so `fast_has_parent_constructor()` is false and the rule returns nothing. The rule ran and
declined. Measured from `phpstan-src` by a peer session, and the control is what proves it: a rule that never
fires looks identical to one that fired and declined.

## Why that fact is worth a case

It is the fact that invalidated a synthesis both this session and a peer had specified. `SimpleStaticType.php:13`
is only-**original** — PHPStan reports, the port is silent — because `PHPStan\Type\StaticType` lives inside
`phpstan.phar`, which PHPStan reads and mago does not open. The obvious synthesis, "a parent nothing
resolves", reproduces *agreement by silence* instead, because an unresolvable parent silences PHPStan too.

So this case exists to stop someone re-deriving that wrong synthesis. It records the null-parent path
explicitly, with a control, rather than leaving it as a thing two sessions each had to discover.

## The ignore line is load-bearing

`case.neon` ignores `constructor.missingParentCall`, scoped to this case's own files. PHPStan's core rule
fires on the *same condition* as the symplify rule — a child constructor not calling its parent — so the
control necessarily triggers both, and the core one would arrive as a second PHPStan-only row with nothing to
do with the case.

The peer first tried removing it by having the control call `parent::__construct()`. That silenced the
symplify rule too, because the two are coupled on that exact condition, and the case would have recorded
silence on both sides and read as agreement — the failure this harness's silence guard already caught once on
a different control.
