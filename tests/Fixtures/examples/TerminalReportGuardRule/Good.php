<?php

declare(strict_types=1);

namespace Examples\TerminalReport;

final class GoodCaller
{
    /**
     * A method the rule allows.
     *
     * This is the case that caught the first attempt: the report escaped its `if` and the plugin fired on
     * every method call, which parses and loads and is a rule that answers a different question.
     */
    public function run(Service $service): void
    {
        $service->allowed();
    }
}
