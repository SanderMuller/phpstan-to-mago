# Measurement and honest numbers

## Name what you compared against

The same result is 17x cheaper and 1.4x more expensive depending on the baseline. Transpiled rules against
**cold** PHPStan: 17x wall, 42x CPU. Against **warm** PHPStan: 2.1x wall but 1.4x *more* CPU, because
`mago analyze` has no result cache and redoes the whole job every run.

Every earlier figure quoted for this work (62x, 128x, 7.9x) was cold-only and read as general. A number
without its baseline is overstating the case.

## Name the baseline you rejected, not only the one you used

One clause more than the rule above, and it is what makes a number auditable by *yourself*.

Measuring the cost of a mago reverse index: against a plain run with no extension host it is 144 ms on a
0.63 s run, 23% and an argument against building it. Against a host that does nothing it is 12% of wall and
4% of CPU, and starting the host costs more than the index does. Same measurement, opposite conclusion, and
the wrong baseline was the more obvious one to reach for.

That one was caught before publishing, and the reason it was caught is that two candidate baselines were in
hand, so the choice was visible. The errors that needed an outside reader were the ones where nobody had
written a second option down: a census parser that dropped a parenthetical, and a benevolent-union census
that counted conditions when the `!` operand was the position the rule works in. In both, one frame looked
like the only frame.

So write "measured against the no-op host, **not** the plain run". That sentence carries the question with
it. "Measured against the no-op host" carries only the answer, and an answer cannot be re-examined by the
person who gave it.

## State n per row

"Best of three" across a table where one row is n=1 is not best of three. A 45-second run does not get
repeated as often as a 2.5-second one; that is fine, but the table has to say so. Report the spread when it
is wide, and say whether the machine was contended.

## Give the marginal cost, not only the total

"mago plus the rules" answers a different question from "what do the rules cost". Measure the engine alone
as well: here the rules add 0.18s wall and 0.94s CPU on 7701 files.

## A count belongs to its configuration

`emitted: 4` and `emitted: 3` were both correct, for different targets. Print the configuration next to the
number, in the tool itself where possible, or a reader will conclude the tool is inconsistent.

## CPU and counts survive contention; wall clock does not

Prefer them when the machine is shared and coordination is not possible.
