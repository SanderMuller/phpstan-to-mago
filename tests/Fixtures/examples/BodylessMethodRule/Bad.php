<?php

declare(strict_types=1);

namespace Examples\BodylessMethod;

interface Bodyless
{
    /** An interface method, which declares no body at all. */
    public function declared(): void;
}

abstract class Bad implements Bodyless
{
    /** An abstract method, the other spelling of the same absence. */
    abstract public function pending(): void;
}
