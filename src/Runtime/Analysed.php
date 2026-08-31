<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;

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

        $names = [];
        foreach ($context->codebase->getClassLikeNames() as $name) {
            $metadata = $context->codebase->getClassLike($name);
            $file = $metadata?->location->file;
            if ($file !== null && isset($analysed[$file])) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
