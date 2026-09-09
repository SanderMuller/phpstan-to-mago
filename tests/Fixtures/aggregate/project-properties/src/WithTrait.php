<?php declare(strict_types=1);

namespace Coverage;

/**
 * A trait beside the class that uses it, which is the cell the corpus measurement could not reach: both
 * consumers it was taken on are PSR-4, one class-like per file. Counted against `WithTrait` and reported at
 * the trait's own line until the span test replaced the file test.
 */
trait LooseTrait
{
    public $fromTrait;
}

class WithTrait
{
    use LooseTrait;
}
