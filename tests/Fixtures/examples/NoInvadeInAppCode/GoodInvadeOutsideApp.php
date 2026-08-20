<?php

declare(strict_types=1);

namespace Examples\Outside;

final class GoodInvadeOutsideApp
{
    public function readPrivateState(object $subject): mixed
    {
        // `invade()` is allowed outside the App namespace, which is the whole of the second guard.
        return invade($subject)->hidden;
    }

    public function notAFunctionCall(object $subject): mixed
    {
        // A method of the same name is not the function the rule is about.
        return $this->invade($subject);
    }

    private function invade(object $subject): object
    {
        return $subject;
    }
}
