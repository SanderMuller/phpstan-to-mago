<?php

declare(strict_types=1);

namespace Examples\Outside;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The same calls where every check declines them, so a check that lost its guards shows up here as a
 * finding rather than as silence.
 */
final class GoodCombinedMethodCalls extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => 'required'];
    }

    public function everyCheckDeclines(Collection $rows, Request $request): void
    {
        // Outside `App`, which is the namespace guard all three of the configured checks share.
        $rows->dump();
        $request->query('token');
        $this->input('surname');

        // A named argument is not a positional flag, and a non-literal one is not a flag at all.
        $this->flagged(name: 'report', enabled: true);
    }

    public function flagged(string $name, bool $enabled): void {}
}
