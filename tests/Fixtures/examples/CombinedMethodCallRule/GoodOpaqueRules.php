<?php

declare(strict_types=1);

namespace App\Intake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Inside `App`, and every key unvalidated — but the class is opaque, so nothing is reported.
 *
 * Two independent reasons, both of which the original treats as "cannot be proven": user code overrides
 * `all()`, and `rules()` returns from inside control flow rather than directly. A port that resolved either
 * of these anyway would report a field that may well be validated at runtime, which is the failure this
 * check is written to avoid.
 */
final class GoodOpaqueRules extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->input('mode') === 'strict') {
            return ['email' => 'required'];
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return ['anything' => true];
    }

    public function readUnvalidated(): void
    {
        $this->input('surname');
    }
}
