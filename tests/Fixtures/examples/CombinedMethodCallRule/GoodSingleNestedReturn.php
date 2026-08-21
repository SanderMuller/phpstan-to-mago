<?php

declare(strict_types=1);

namespace App\Intake;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Exactly one `return`, nested in control flow — so the key set is conditional and the class is opaque.
 *
 * This is the example that was missing, and it has to have *one* return to be it: `GoodOpaqueRules` has two,
 * so it proved the count and never the nesting. A port checking only the count accepted this array as
 * complete and reported every field not in it — findings against fields that *are* validated, on the
 * commonest `rules()` shape there is. Every FormRequest on the project this was measured against is
 * conditional like this.
 */
final class GoodSingleNestedReturn extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->boolean('strict')) {
            return ['email' => 'required'];
        }

        throw new RuntimeException('Only reachable in strict mode.');
    }

    public function readUnvalidated(): void
    {
        $this->input('surname');
    }
}
