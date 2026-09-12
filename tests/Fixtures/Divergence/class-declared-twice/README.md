# Class declared twice, behind version guards

**Records agreement. It was written to reproduce a divergence and does not.** Both facts are the point.

## What it pins

One class name declared twice behind `PHP_VERSION_ID` guards, each declaration carrying the protected member
`NoProtectedClassStmtRule` forbids. Both engines report **both** declarations, plus the unguarded control:

    both      Subject.php:11   the newer branch
    both      Subject.php:17   the older branch
    both      Subject.php:27   the control

That is a real recorded fact — neither engine is confused by a name declared twice behind guards, and the
record goes red if either changes.

## What it does NOT stand for

It was written to reduce `nesbot/carbon` `MessageFormatterMapper.php:42`, `noProtectedClassStmt`, recorded as
only-original — PHPStan reports, the port is silent — with the cause *"a parent declared twice, per PHP
version"*.

**Two predictions were made before running, and both were wrong.** A peer session measured PHPStan's side
from phpstan-src and predicted two rows, one per branch, which held. I predicted the port would report once or
not at all, so the case would record a count divergence. It reports both. Nothing diverges.

So the Carbon finding is not caused by the double declaration on its own. Whatever produces it is something
this reduction drops — the *parent* being the doubly-declared class rather than the subject, the surrounding
resolution chain, or the Carbon version the run used.

## Why it is kept

A failed minimisation is worth recording: the next person to reduce that finding should start after this
shape rather than at it. The original stays in `VERIFICATION.md` as unreproduced, and no case claims to cover
it.

`Plain` is the control both engines must report, so a case that stopped exercising the rule would fail rather
than read as agreement.
