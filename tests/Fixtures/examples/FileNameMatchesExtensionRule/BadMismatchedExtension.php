<?php

declare(strict_types=1);

namespace Examples\Config;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Two closures the rule reports, and the second is the one that pins the walk.
 *
 * `basename(__FILE__, '.php')` is `BadMismatchedExtension`, so neither name below matches it.
 *
 * The second closure is the sharp row. Its first `extension()` call carries no string, and the callback ends
 * with `return NodeVisitor::STOP_TRAVERSAL;` — which reads as "stop here" and does nothing at all:
 * `NodeFinder::find()` takes a predicate, and `FindingVisitor::enterNode()` uses the return only for its
 * truthiness. So the walk continues, reads `mismatch` from the second call, and the rule reports. A port that
 * honoured the stop is silent here, which is how the first version of this port was caught.
 */
final class MismatchedConfigs
{
    /** @return list<callable> */
    public function closures(): array
    {
        return [
            static function (ContainerConfigurator $containerConfigurator): void {
                $containerConfigurator->extension('framework');
            },
            static function (ContainerConfigurator $containerConfigurator): void {
                $containerConfigurator->extension();
                $containerConfigurator->extension('mismatch');
            },
        ];
    }
}
