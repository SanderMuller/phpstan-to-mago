<?php

declare(strict_types=1);

namespace Examples\OneFile;

// Two named class-likes in one file, which is what the rule forbids. An interface beside a class rather
// than two classes, because the rule counts class-likes of any kind and a pair of classes would leave the
// interface, trait and enum kinds of the search unexercised.
interface BadContract
{
    public function handle(): void;
}

final class BadTwoClassLikes implements BadContract
{
    public function handle(): void {}
}
