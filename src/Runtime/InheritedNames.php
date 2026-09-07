<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;

/**
 * What a method's own spelling says about the one it inherits.
 *
 * One reporter, on its own class rather than in {@see Members}, because adding it there took that class to 83
 * against a limit of 80 — and a *new* baseline entry is the thing this repository watches for. Splitting a
 * static bag moves the complexity with the method, which is the property `Support` was split on.
 *
 * @internal to the runtime. An emitted plugin calls this directly, the way it calls {@see RectorAutoloadedTypes}.
 */
final class InheritedNames
{
    /**
     * Reports where a method's name differs in case from the ancestor it overrides.
     *
     * `WrongCaseOfInheritedMethodRule::findMethod()`, which builds the finding itself  ported as a reporter
     * rather than as a question, the shape `AnnotationHelper::processDocComment()` established.
     *
     * **Native, not mixin-aware.** The original asks `hasNativeMethod()` and `getNativeMethod()`, which skip
     * magic and `@mixin` methods; this reads `getDeclaringMethod()` rather than {@see Mixins::declaringMethod()}
     * for the same reason. A method a mixin supplies has no written declaration to disagree in case with, and
     * the mixin-aware path would report against a name the ancestor does not write.
     */
    public static function reportInheritedCaseMismatch(
        NodeAnalysisContext $context,
        ?string $declaringClass,
        ?string $ancestorClass,
        ?string $methodName,
        string $identifier,
    ): void {
        if ($declaringClass === null || $ancestorClass === null || $methodName === null) {
            return;
        }

        $declared = $context->codebase->getDeclaringMethod($ancestorClass, $methodName);
        if (! $declared instanceof FunctionLikeMetadata || $declared->originalName === $methodName) {
            return;
        }

        $ancestor = $context->codebase->getClassLike($ancestorClass);
        $kind = $context->codebase->getInterface($ancestorClass) instanceof ClassLikeMetadata ? 'interface' : 'parent';

        // `originalName`, not the name the lookup used. Metadata hands class names back lowercased  this
        // file records that elsewhere as fine for looking a class up again and wrong for printing, and the
        // message puts the ancestor in front of a reader. Measured: the pair reported
        // `examples\\inheritance\\namingbase` where PHPStan reports `Examples\\Inheritance\\NamingBase`, and
        // the gate compares message text.
        $ancestorName = $ancestor instanceof ClassLikeMetadata ? $ancestor->originalName : $ancestorClass;

        $context->report(
            Level::Error,
            $identifier,
            Issue::new(sprintf(
                'Method %s::%s() does not match %s method name: %s::%s().',
                $declaringClass,
                $methodName,
                $kind,
                $ancestorName,
                $declared->originalName,
            ), $context->node->span, 'here'),
        );
    }
}
