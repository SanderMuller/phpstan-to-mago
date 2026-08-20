<?php

declare(strict_types=1);

namespace Examples\Outside;

/**
 * The same three calls where each check declines them, so a check that lost its guards is visible here
 * as a finding rather than as silence.
 */
final class GoodCombinedFuncCalls
{
    public function everyCheckDeclines(object $subject): array
    {
        // Outside `App` and `Tests`, which is the whole of the debug check's namespace guard.
        dump($subject);

        // `invade()` is allowed outside `App`. The `\Livewire\invade` form is not, in any namespace,
        // so it has no place in a good example.
        $direct = invade($subject)->hidden;

        // Outside the configured namespaces, so reading request data here is none of the rule's business.
        $name = request('name');

        return [$direct, $name];
    }

    public function noArguments(): mixed
    {
        // The request check wants an argument: `request()` with none is the container accessor.
        return request();
    }
}
