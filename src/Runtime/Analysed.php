<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;

/**
 * Which class-likes an after-analysis pass is allowed to count.
 *
 * Its own class because two of the coverage metrics need the same answer, and the group they share it from is
 * the one place it can be stated once — the same reason the runtime's navigation primitives sit in `Tree`
 * rather than in whichever class needed them first.
 */
final class Analysed
{
    /**
     * The class-likes declared in the analysed files, rather than every class Mago knows.
     *
     * The codebase includes every stub it scanned — 669 of them on an empty project — and a coverage
     * percentage over the standard library is not what the rule means.
     *
     * @return list<string>
     */
    public static function classNames(AfterAnalysisContext $context): array
    {
        $analysed = [];
        foreach ($context->analysis->files as $file) {
            $analysed[$file->file] = true;
        }

        // Batched, and that is the whole of the cost. `getClassLike()` is `getMultipleClassLikes([$name])`
        // -- one host round-trip per name -- and this asks for every class-like Mago scanned in order to read
        // the file each was declared in. Measured on the 270-file benchmark corpus: **13,982 codebase
        // class-likes scanned to keep 269**, which is where the coverage metrics' +1.42s wall came from.
        // Chunked at 500 the way {@see Declares::traitUsers()} chunks its own sweep.
        //
        // Deriving the list from the analysed files' *syntax* instead was tried and is wrong: Mago names an
        // anonymous class `{anonymous-class:src/Maker.php:13:16}`, so a name-resolving walk skips it, and
        // `CountsReturnsLikeTheCollectorTest` counted 2 declarations where the real rule counts 3. The
        // comment that version carried -- that `getClassLikeNames()` has no name for an anonymous class
        // either -- was an assumption, and the fixture that already existed for this refuted it.
        $names = [];
        foreach (array_chunk($context->codebase->getClassLikeNames(), 500) as $chunk) {
            foreach ($context->codebase->getMultipleClassLikes($chunk) as $metadata) {
                if (! $metadata instanceof ClassLikeMetadata) {
                    continue;
                }

                $file = $metadata->location->file;
                if ($file !== null && isset($analysed[$file])) {
                    $names[] = $metadata->name;
                }
            }
        }

        return $names;
    }
}
