<?php

declare(strict_types=1);

namespace App\Intake;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The three node-shaped checks of the merged rule, each in its own statement.
 *
 * Flattened into one guard chain, the first check's "not my case" once silenced the others, so a single
 * finding here would prove only that one of them runs.
 */
class BadCombinedMethodCalls extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'address.street' => 'string',
        ];
    }

    public function everyCheck(Collection $rows, Request $request): void
    {
        // The chained-debug check, which only fires on a debug method a Laravel class declares.
        $rows->dump();

        // The unsafe-request-data check, which only fires on an Illuminate request receiver.
        $request->query('token');

        // The positional-flag check: a bare bool as the last argument of a first-party call.
        $this->flagged('report', true);

        // The cross-file check: 'address' is a validated root, so this one declines.
        $this->input('address.street');

        // The cross-file check again, on a key rules() never declares. rules() is declared here, but the
        // check resolves it through the codebase rather than through this file, which is what makes the
        // same code work for a subclass that inherits it.
        $this->input('surname');
    }

    public function flagged(string $name, bool $enabled): void {}
}
