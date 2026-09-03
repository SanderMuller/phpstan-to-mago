<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sandermuller\PhpstanToMago\Tests\Support\CorpusDifferential;

/**
 * The one message difference the corpus differential is told to ignore, and nothing wider.
 *
 * A trait-declared violation is reported once per using class by PHPStan, which carries which one in the
 * *file* field where a plugin cannot write. The port reports once and names them in the message. Without
 * this filter every trait finding would sit in `differingMessages` permanently and real message drift would
 * hide inside a list that is always full.
 *
 * A filter that silences too much is the failure here, so the cases below are mostly about what it keeps.
 */
#[CoversClass(CorpusDifferential::class)]
final class DropsTheTraitUserSuffixTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function messages(): iterable
    {
        yield 'the suffix the port appends' => [
            'Avoid trailing slash in route path "/x/" (via App\FirstController, App\SecondController)',
            'Avoid trailing slash in route path "/x/"',
        ];

        yield 'one using class' => [
            'Something (via App\Only)',
            'Something',
        ];

        yield 'a message with no suffix is untouched' => [
            'Avoid trailing slash in route path "/x/"',
            'Avoid trailing slash in route path "/x/"',
        ];

        // The cases that matter. A filter matching anywhere in the message, or matching a parenthesis that
        // is part of what the rule reports, would silence a real difference and read as agreement.
        yield 'not at the end' => [
            'Called (via App\Helper) from somewhere',
            'Called (via App\Helper) from somewhere',
        ];

        yield 'a nested parenthesis is not the suffix' => [
            'Something (via foo(bar))',
            'Something (via foo(bar))',
        ];

        yield 'an empty via is not the suffix' => [
            'Something (via )',
            'Something (via )',
        ];
    }

    #[DataProvider('messages')]
    public function test_only_the_appended_suffix_is_dropped(string $message, string $expected): void
    {
        $this->assertSame($expected, CorpusDifferential::withoutTraitUsers($message));
    }
}
