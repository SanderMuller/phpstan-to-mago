# `!instanceof` keeps an unresolvable arm

**Live divergence, mago's side.** Stands for `monolog` `Handler/MandrillHandler.php:41`, found by the corpus
sweep's second run.

    !instanceof Known            callable                         both engines eliminate the arm
    !instanceof Absent\Klass     callable|Klass (ReferenceType)   mago keeps it, PHPStan does not

`!$x instanceof T` removes `T` by pure logic: if the value is not an instance of `T`, the `T` arm is gone
whether or not `T` can be resolved. Mago conditions the elimination on resolving the class, which it does not
need to do.

**This case carries two controls, and the second is unusual.** `plain()` proves the rule fires. `resolvable()`
proves the *narrowing* works when the class resolves — so the case goes red if mago's `!instanceof` breaks
generally, not only when this divergence closes. Without it, a regression that stopped all `instanceof`
narrowing would look like the divergence widening rather than like something else entirely.

`class.notFound` is ignored for this case, scoped to its own files: PHPStan reports the missing class at the
signature and at the `instanceof`, and those rows say nothing about the rule under test.
