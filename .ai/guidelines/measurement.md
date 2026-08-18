# Measurement and honest numbers

## Name what you compared against

The same result is 17x cheaper and 1.4x more expensive depending on the baseline. Transpiled rules against
**cold** PHPStan: 17x wall, 42x CPU. Against **warm** PHPStan: 2.1x wall but 1.4x *more* CPU, because
`mago analyze` has no result cache and redoes the whole job every run.

Every earlier figure quoted for this work (62x, 128x, 7.9x) was cold-only and read as general. A number
without its baseline is overstating the case.

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
