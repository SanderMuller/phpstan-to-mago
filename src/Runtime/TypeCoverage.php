<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata as ClassMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\PropertyMetadata;
use Mago\Sdk\Analyzer\Metadata\TypeMetadata;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\Node;

/**
 * Declaration counts for a whole project, for the rules that report a coverage percentage.
 *
 * A collector-and-consumer rule is not translated statement by statement. PHPStan collects a fact per file and
 * a second rule reduces the collection; Mago has no collector, but an `AfterAnalysisHook` sees every file at
 * once and can report. So the *question* is reimplemented against `Codebase` metadata rather than the
 * collector's body being ported.
 *
 * What is true here was established by comparing totals against the real rule and by probing the SDK, not by
 * reading either. Each of these was wrong in an earlier version:
 *
 * - **Only the *return* collector skips a magic method.** The parameter collector counts one, so a skip in the
 *   shared iterator under-counted: PHPStan's total includes `__get($name)`.
 * - **The percentage rounds down**, not to nearest, so the message cannot claim a threshold is met when it is
 *   not. 2 of 7 is 28.5, and rounding to nearest was the last thing to disagree once the counts matched.
 * - **A variadic parameter is skipped**, and counting it inflates the total.
 * - **Only class-likes declared in the analysed files count.** The codebase carries every stub it scanned —
 *   669 on an empty project — and a percentage over the standard library is not what the rule means.
 * - **A parameter carries its own `location`**, in the file that declares it, which is the trait's file for a
 *   trait method. That is what makes a finding land where PHPStan puts it.
 * - **Method names in metadata are lowercased**, so any comparison has to fold case.
 *
 * One filter of the original is **not** reproduced: `ParamTypeDeclarationCollector` skips a function-like whose
 * docblock declares a `callable` parameter, which needs a docblock read this does not do. A project using that
 * shape will disagree, and it is a known gap rather than a silent one.
 */
final readonly class TypeCoverage
{
    /**
     * @param int $total how many declarations could carry a type
     * @param int $typed how many of them do
     * @param list<SourceLocation> $missing where each untyped declaration is
     */
    private function __construct(
        public int $total,
        public int $typed,
        public array $missing,
    ) {}

    /**
     * The share of declarations that carry a type, as a percentage, or 100 when there are none to count.
     *
     * Rounded *down* to one decimal, not rounded to nearest: the original does that deliberately, so the
     * message cannot claim a threshold is met when it is not. 2 of 7 is 28.5, not 28.6, and rounding to
     * nearest was the one thing this reimplementation got wrong once the counts already agreed.
     */
    public function percentage(): float
    {
        if ($this->total === 0) {
            return 100.0;
        }

        return floor($this->typed / $this->total * 100 * 10) / 10;
    }

    /**
     * Parameter type coverage, read from the syntax rather than from metadata.
     *
     * The counting lives in {@see DeclaredParameters}, because reproducing `ParamTypeDeclarationCollector`
     * takes a trait-user index, an LSP guard and four skips — more than this class can hold and still be read.
     */
    public static function parameters(AfterAnalysisContext $context): self
    {
        $counted = DeclaredParameters::of($context);

        return new self($counted['total'], $counted['typed'], $counted['missing']);
    }

    /** Return type coverage across every function-like the analysis knows. */
    /**
     * The method names php-parser calls magic, copied from `ClassMethod::$magicNames`.
     *
     * Carried rather than asked of mago, because the two do not agree and the rule means this one. Copied
     * verbatim so an upstream addition is a diff here rather than a silent divergence in a percentage.
     *
     * @var array<string, true>
     */
    private const array MAGIC_NAMES = [
        '__construct' => true,
        '__destruct' => true,
        '__call' => true,
        '__callstatic' => true,
        '__get' => true,
        '__set' => true,
        '__isset' => true,
        '__unset' => true,
        '__sleep' => true,
        '__wakeup' => true,
        '__tostring' => true,
        '__set_state' => true,
        '__clone' => true,
        '__invoke' => true,
        '__debuginfo' => true,
        '__serialize' => true,
        '__unserialize' => true,
    ];

    public static function returns(AfterAnalysisContext $context): self
    {
        $total = 0;
        $typed = 0;
        $missing = [];

        foreach (self::methods($context) as $method) {
            // A constructor has no return type to declare, and it needs no check of its own: php-parser
            // counts `__construct` among its magic names, so the list below already excludes it. A separate
            // check stood here and a mutation could not make it fail, which is what said it was dead.
            //
            // Only the *return* collector skips a magic method — the parameter collector counts one. Putting
            // this in the shared iterator was wrong in a way no fixture caught until the totals were compared
            // against the real rule: PHPStan's count of 7 includes `__get($name)`.
            //
            // By name, not by `MetadataFlags::MAGIC_METHOD`. The original's filter is php-parser's
            // `ClassMethod::isMagic()`, which is membership in a fixed list of seventeen names, and mago's
            // flag is a different set: measured on a fixture holding `__get()`, PHPStan counted 6 methods and
            // this counted 7, because the flag was not set for it. The list is what the rule means, so the
            // list is what the port carries.
            if (self::MAGIC_NAMES[strtolower($method->name)] ?? false) {
                continue;
            }

            ++$total;
            if ($method->declaredReturnType !== null) {
                ++$typed;

                continue;
            }

            // `nameLocation` is where the original anchors a missing return type, and it is nullable — a
            // closure has no name to point at — so the declaration's own location stands in.
            $missing[] = $method->nameLocation instanceof SourceLocation ? $method->nameLocation : $method->location;
        }

        return new self($total, $typed, $missing);
    }

    /** Property type coverage across every class-like the analysis knows. */
    public static function properties(AfterAnalysisContext $context): self
    {
        $total = 0;
        $typed = 0;
        $missing = [];

        foreach (self::classNames($context) as $class) {
            $metadata = $context->codebase->getClassLike($class);
            if (! $metadata instanceof ClassMetadata) {
                continue;
            }

            foreach ($metadata->properties as $name) {
                $property = $context->codebase->getDeclaringProperty($class, $name);
                if (! $property instanceof PropertyMetadata) {
                    continue;
                }

                ++$total;
                if ($property->declaredType instanceof TypeMetadata) {
                    ++$typed;

                    continue;
                }

                // A property's location is nullable, and which properties have one is the opposite of what
                // it looks like. Probed on a fixture holding an ordinary declaration, an inherited one and a
                // constructor-promoted one: only the **promoted** property has a location, and every
                // ordinary declaration answers null. So this anchors findings on exactly the properties
                // `PropertyTypeDeclarationCollector` does not count — it collects `Property` nodes, and a
                // promoted parameter is a `Param`.
                //
                // That is one of three reasons this metric is not mapped in `Vocabulary::AGGREGATES`; see
                // VERIFICATION.md for the other two. Left as written rather than reworked, because a metric
                // that is not emitted is a measurement waiting for its differential, not shipped behaviour.
                if ($property->location instanceof SourceLocation) {
                    $missing[] = $property->location;
                }
            }
        }

        return new self($total, $typed, $missing);
    }

    /**
     * Whether every analysed file declares `strict_types=1`.
     *
     * The one coverage question that is not a declaration in the codebase but a statement in the file, so it
     * is read from the analysed syntax. `getSourceFile()` answers per file after analysis — probed over 217
     * files with no failures — and never touches the filesystem.
     */
    public static function declares(AfterAnalysisContext $context): self
    {
        $total = 0;
        $typed = 0;
        $missing = [];

        foreach ($context->analysis->files as $file) {
            ++$total;
            $source = $file->getSourceFile();
            $declared = false;
            foreach ($source->getNodes(NodeKinds::declare()) as $declare) {
                if (str_contains(str_replace(' ', '', $source->getText($declare)), 'strict_types=1')) {
                    $declared = true;

                    break;
                }
            }

            if ($declared) {
                ++$typed;

                continue;
            }

            $first = $source->getNodes()[0] ?? null;
            if ($first instanceof Node) {
                $missing[] = new SourceLocation($file->file, $first->span);
            }
        }

        return new self($total, $typed, $missing);
    }

    /**
     * Every method the analysis knows, resolved through its declaring class.
     *
     * Resolved rather than listed: a class using a trait does not list the trait's methods, so walking each
     * class's own list would silently drop them. `getDeclaringMethod()` finds a method wherever it is
     * declared, and hands back metadata already attributed to that file.
     *
     * @return iterable<FunctionLikeMetadata>
     */
    private static function methods(AfterAnalysisContext $context): iterable
    {
        $seen = [];
        foreach (self::classNames($context) as $class) {
            $metadata = $context->codebase->getClassLike($class);
            if (! $metadata instanceof ClassMetadata) {
                continue;
            }

            foreach ($metadata->methods as $name) {
                $method = $context->codebase->getDeclaringMethod($class, $name);
                if (! $method instanceof FunctionLikeMetadata) {
                    continue;
                }

                // One declaration counted once, however many classes reach it. Names arrive lowercased, so
                // the key folds case for free.
                $key = $method->location->file . ':' . $method->location->span->start;
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                yield $method;
            }
        }
    }

    /**
     * The class-likes declared in the analysed files, rather than every class Mago knows.
     *
     * The codebase includes every stub it scanned — 669 of them on an empty project — and a coverage
     * percentage over the standard library is not what the rule means.
     *
     * @return list<string>
     */
    private static function classNames(AfterAnalysisContext $context): array
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
