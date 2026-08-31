<?php declare(strict_types=1);

namespace Control;

/**
 * A class that documents a name two traits declare, and picks one with `insteadof`.
 *
 * The loser's declaration is never analysed in this class's context, so the original counts one. The port
 * counts two, and the two facts that would tell them apart cancel: the `@method` line makes the codebase
 * resolve the name to the docblock, so asking where the name lands says "not the trait" for the winner as
 * well as the loser — and the fallback that rescues a documented trait method then rescues both.
 *
 * Refusing the fallback wherever an adaptation block appears was tried and is worse: it takes the winner out
 * too and the control reads 0 against 1. Telling the two apart needs the `insteadof` winner read from the
 * `TraitUseAdaptation` node, which is a piece of work rather than a condition.
 *
 * @method m($a)
 */
final class Chooser
{
    use Left, Right { Left::m insteadof Right; }
}
