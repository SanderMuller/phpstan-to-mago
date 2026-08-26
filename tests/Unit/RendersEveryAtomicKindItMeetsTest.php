<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Tests\Unit;

use Mago\Sdk\Analyzer\Type\AtomicType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sandermuller\PhpstanToMago\Runtime\Describe;

/**
 * The gate on the renderer's coverage: every atomic kind a real corpus reaches has a branch of its own.
 *
 * A rule interpolating a type cannot refuse half way through an analysis, so {@see Describe} is total by
 * construction — an unmapped atomic falls back to the SDK's own rendering rather than to nothing. That makes
 * a gap silent, which is what this test exists to stop being.
 *
 * Two lists, and the difference between them is the whole point. `REACHED` is what
 * `tests/Support/run-render-census.php` observed over 243822 types on a real Symfony application, and every
 * one of those must have a branch. The SDK declares twenty atomic classes; the six that a corpus has never
 * produced are named as the fallback's tail rather than mapped on speculation.
 */
final class RendersEveryAtomicKindItMeetsTest extends TestCase
{
    /**
     * The atomic classes a real corpus produced, at the positions the renderer's customers read from.
     *
     * @var list<string>
     */
    private const array REACHED = [
        'AnyObjectType',
        'CallableType',
        'EnumType',
        'GenericParameterType',
        'IterableType',
        'KeyedArrayType',
        'ListType',
        'MixedType',
        'NamedObjectType',
        'ObjectWithMethodType',
        'ReferenceType',
        'ResourceType',
        'ScalarType',
        'SimpleAtomicType',
    ];

    /**
     * The rest of what the SDK declares, which no corpus here has produced.
     *
     * Named rather than mapped: a branch written for a shape nobody has seen is a guess about how PHPStan
     * renders it, and the fallback is honest where a guess would not be. Moving one of these into `REACHED`
     * is what a census run finding it looks like.
     *
     * @var list<string>
     */
    private const array UNREACHED = [
        'AliasType',
        'ConditionalType',
        'DerivedType',
        'ObjectShapeType',
        'ObjectWithPropertyType',
        'VariableType',
    ];

    public function test_every_atomic_kind_a_corpus_reaches_has_its_own_branch(): void
    {
        $branches = $this->branches();

        $this->assertSame(
            [],
            array_values(array_diff(self::REACHED, $branches)),
            "Describe has no branch for an atomic kind a real corpus produces, so it falls back to the SDK's "
            . 'rendering there — which is the divergence the renderer exists to remove.',
        );
    }

    /** And the two lists together are the SDK's, so a new atomic class fails here rather than passing quietly. */
    public function test_the_two_lists_account_for_every_atomic_the_sdk_declares(): void
    {
        $declared = [];
        $directory = dirname(__DIR__, 2) . '/vendor/carthage-software/mago/composer/src/Sdk/Analyzer/Type';
        $files = glob($directory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            $class = 'Mago\\Sdk\\Analyzer\\Type\\' . basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }

            if ((new ReflectionClass($class))->implementsInterface(AtomicType::class)) {
                $declared[] = basename($file, '.php');
            }
        }

        sort($declared);
        $accounted = [...self::REACHED, ...self::UNREACHED];
        sort($accounted);

        $this->assertSame(
            $declared,
            $accounted,
            'The SDK declares an atomic class neither list names. Run tests/Support/run-render-census.php to '
            . 'find out whether a corpus reaches it, then map it or add it to UNREACHED with that answer.',
        );
    }

    /** An unmapped kind renders as something rather than as nothing, which is what makes the renderer total. */
    public function test_an_unmapped_kind_falls_back_rather_than_vanishing(): void
    {
        $branches = $this->branches();

        $this->assertNotSame([], array_values(array_diff(self::UNREACHED, $branches)));
        $this->assertStringContainsString(
            'default => (string) $atomic,',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Runtime/Describe.php'),
            'The fallback is gone, so an atomic kind with no branch would render as an empty string inside a '
            . 'message a rule has already committed to reporting.',
        );
    }

    /**
     * The atomic classes `Describe` branches on, read from its source.
     *
     * @return list<string>
     */
    private function branches(): array
    {
        preg_match_all(
            '/\$atomic instanceof (\w+)/',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Runtime/Describe.php'),
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }
}
