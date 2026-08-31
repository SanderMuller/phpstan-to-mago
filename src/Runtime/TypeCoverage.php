<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago\Runtime;

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
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
        $traitUsers = TraitUsers::of($context);

        foreach (self::classNames($context) as $class) {
            $metadata = $context->codebase->getClassLike($class);
            if (! $metadata instanceof ClassMetadata) {
                continue;
            }

            $users = $metadata->kind === ClassLikeKind::Trait
                ? $traitUsers[strtolower($metadata->originalName)] ?? []
                : null;
            if ($users === []) {
                continue;
            }

            // `@method` on a class docblock declares what `__call()` answers, and the codebase lists those
            // beside the written ones. The collector visits `ClassMethod` *nodes*, so it never sees one:
            // Laravel's factories carry two each, which was 32 declarations on one consumer's factory
            // directory alone. The parameter metric is untouched by this because it walks the syntax, where
            // a docblock has no function-like node at all.
            $documented = array_flip([...$metadata->pseudoMethods, ...$metadata->staticPseudoMethods]);

            // And the three methods the language gives an enum. `cases()` on any of them, `from()` and
            // `tryFrom()` on a backed one — nobody writes them, so there is no `ClassMethod` node for the
            // collector to visit, and the codebase lists them like any other method. PHP forbids declaring
            // a method under one of these names on an enum, so skipping them by name cannot skip a written
            // one. This was +430 of a +444 corpus delta, all of it in one directory of 157 enums.
            if ($metadata->kind === ClassLikeKind::Enum) {
                $documented += array_flip(['cases', 'from', 'tryfrom']);
            }

            foreach ($metadata->methods as $name) {
                if (isset($documented[$name])) {
                    continue;
                }

                $method = $context->codebase->getDeclaringMethod($class, $name);
                if (! $method instanceof FunctionLikeMetadata) {
                    continue;
                }

                $times = self::timesAnalysed($context, $method, $users);
                if ($times === 0) {
                    continue;
                }

                // Only the *return* collector skips a magic method — the parameter collector counts one.
                // Putting this in a shared iterator was wrong in a way no fixture caught until the totals
                // were compared against the real rule: PHPStan's count of 7 includes `__get($name)`.
                //
                // By name, not by `MetadataFlags::MAGIC_METHOD`. The original's filter is php-parser's
                // `ClassMethod::isMagic()`, which is membership in a fixed list of seventeen names, and
                // mago's flag is a different set: measured on a fixture holding `__get()`, PHPStan counted 6
                // methods and this counted 7, because the flag was not set for it. The list is what the rule
                // means, so the list is what the port carries. `__construct` is one of the seventeen, which
                // is why no separate constructor check stands here — one did, and no mutation could make it
                // fail.
                if (self::MAGIC_NAMES[strtolower($method->name)] ?? false) {
                    continue;
                }

                $total += $times;
                if ($method->declaredReturnType !== null) {
                    $typed += $times;

                    continue;
                }

                // Once, however many times it is counted: a declaration has one site, and PHPStan reports
                // one error per (file, line, message) whatever the collector handed it. `DeclaredParameters`
                // anchors the same way for the same reason.
                //
                // `nameLocation` is where the original anchors a missing return type, and it is nullable — a
                // closure has no name to point at — so the declaration's own location stands in.
                $missing[] = $method->nameLocation instanceof SourceLocation ? $method->nameLocation : $method->location;
            }
        }

        return new self($total, $typed, $missing);
    }

    /**
     * How many times PHPStan analyses one method declaration, which is not always once.
     *
     * The collectors here run per analysed *scope*, and a trait's body is analysed once for every class that
     * uses it — twice for a trait two classes use, and **not at all** for a trait nobody uses. A method
     * declared in a class is analysed once.
     *
     * Counting the trait's users is not enough, which a control says rather than an argument. A class that
     * uses a trait and declares the same method itself never has the trait's version analysed in its
     * context: its own wins. `overridden-trait-method` counted 1 to the real rule and 2 here while this
     * asked how many classes *use* the trait rather than how many *reach* the declaration.
     *
     * Measured before that, and it is the difference between two wrong numbers cancelling and two right
     * ones: on a fixture with a trait used by two classes and a trait used by none, counting each
     * declaration once gave the same total as the real rule while counting the wrong things. Deleting the
     * unused trait separated them — PHPStan stayed at 3 and this dropped to 2.
     *
     * @param list<array{class: string|null, aliases: list<string>}>|null $users null for a class-like that
     *        is not a trait, whose body is analysed once
     */
    private static function timesAnalysed(AfterAnalysisContext $context, FunctionLikeMetadata $method, ?array $users): int
    {
        if ($users === null) {
            return 1;
        }

        $site = $method->location->file . ':' . $method->location->span->start;

        $times = 0;
        foreach ($users as $user) {
            $class = $user['class'];

            // An anonymous class has no name to ask the codebase about, so the question cannot be put to it
            // and the declaration counts once for it.
            if ($class === null) {
                ++$times;

                continue;
            }

            if (TraitUsers::reachedAs($context, $class, $site, [$method->name, ...$user['aliases']]) !== null) {
                ++$times;
            }
        }

        return $times;
    }

    /**
     * Property type coverage across every class-like the analysis knows.
     *
     * Two things this cannot read from `$metadata->properties` alone, and one field answers both.
     *
     * The list holds a trait's properties on **every class that uses it**, which `methods` does not — the two
     * are not symmetric, and a shared iterator over "members" would be right for one and wrong for the other.
     * On one consumer's models that is 83 of 217: `forceDeleting` from Laravel's `SoftDeletes` and an
     * auditing package's `auditEvent` and siblings, listed once per using class with the declaration in a
     * vendor file `PropertyTypeDeclarationCollector` never visits.
     *
     * And a finding needs a span. `PropertyMetadata::$location` is null for every ordinary declaration and
     * set only for a constructor-promoted one — the opposite of what it reads as, and the reason this used to
     * compute a failing percentage and report nothing at all over 142 real classes.
     *
     * `nameLocation` answers both. It is populated for all 217 on that population, its file is the class's
     * own for the 134 written there and the trait's for the other 83, and it points at the property's name,
     * which is where the original anchors a missing type. Found by the `phpstan-src-e7` session on a
     * four-property fixture and confirmed here at corpus scale.
     */
    public static function properties(AfterAnalysisContext $context): self
    {
        $total = 0;
        $typed = 0;
        $missing = [];
        // Read once per file rather than searched per property: the docblock test needs the source text, and
        // looking it up by walking the file list each time is one pass over every analysed file for every
        // untyped property.
        $contents = [];
        foreach ($context->analysis->files as $analysed) {
            $contents[$analysed->file] = $analysed->getSourceFile()->contents;
        }

        foreach (self::classNames($context) as $class) {
            $metadata = $context->codebase->getClassLike($class);
            if (! $metadata instanceof ClassMetadata) {
                continue;
            }

            // Without a file there is nothing to compare a declaration's own location against, so every
            // property would read as arriving from somewhere else.
            $file = $metadata->location->file;
            if (! is_string($file)) {
                continue;
            }

            // A trait's properties are counted **zero** times, which is the opposite of its methods.
            // `ReturnTypeDeclarationCollector` visits `ClassMethod` nodes, so a trait's method is visited in
            // every using class's context; `PropertyTypeDeclarationCollector` visits `InClassNode` and takes
            // `count($classLike->getProperties())` off the class node, and a class node's property list never
            // holds the trait's. Two collectors in one package, one shape apart, and a control says so: a
            // trait with one property and two users counts 3 to the real rule and counted 5 here.
            if ($metadata->kind === ClassLikeKind::Trait) {
                continue;
            }

            foreach ($metadata->properties as $name) {
                $property = $context->codebase->getDeclaringProperty($class, $name);
                if (! $property instanceof PropertyMetadata) {
                    continue;
                }

                // Written here, or arriving from a trait or a parent. Only the first is this class-like's
                // declaration; the rest are counted where they are written, if that file is analysed at all.
                $at = $property->nameLocation;
                if (! $at instanceof SourceLocation || $at->file !== $file) {
                    continue;
                }

                // A constructor-promoted property is a `Param` to php-parser and the collector visits
                // `Property` nodes, so the original never counts one. Asked of the flag rather than of
                // `location`: a non-null `location` looked like the promoted marker on every fixture, and it
                // is set for an interface's own property declarations too — PHP 8.4 lets an interface declare
                // one, and four of them in a single file were the whole of a -4 corpus delta. The flag says
                // promoted and nothing else does.
                if ($property->flags->contains(MetadataFlags::PROMOTED_PROPERTY)) {
                    continue;
                }

                ++$total;

                // A written type, or a docblock the original treats as one. `isPropertyDocTyped()` is not
                // what its name says: it does not ask whether a `@var` is present, it asks whether the
                // docblock text contains `callable` or `resource` — the two the original skips as "unable to
                // type". So `type`, which mago sets for any `@var`, is too generous: it read 94.9 % where the
                // real rule read 93.3 % on one consumer with the counts already exact.
                if ($property->declaredType instanceof TypeMetadata
                    || self::guardedByParent($context, $metadata, $name)
                    || self::docblockDefersTyping($contents[$file] ?? '', $property)
                ) {
                    ++$typed;

                    continue;
                }

                // Once, however many times it is counted: a declaration has one site, and PHPStan reports one
                // error per (file, line, message) whatever the collector handed it.
                $missing[] = $at;
            }
        }

        return new self($total, $typed, $missing);
    }

    /**
     * Whether a parent class already declares this property, which takes it out of the missing list.
     *
     * The original's third exclusion, beside a written type and the callable-or-resource docblock, and the
     * one that is easiest to miss because it is a *guard* rather than a type test: a property the parent also
     * declares stays in the total and never counts as missing. Leaving it out read 63 % where the real rule
     * read 100 % with the counts already exact.
     */
    private static function guardedByParent(AfterAnalysisContext $context, ClassMetadata $metadata, string $name): bool
    {
        foreach ($metadata->parentClasses as $parent) {
            if ($context->codebase->getDeclaringProperty($parent, $name) instanceof PropertyMetadata) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the docblock above a property names a type the original gives up on.
     *
     * `PropertyTypeDeclarationCollector::isPropertyDocTyped()` reads the docblock's *text* and answers true
     * when it contains `callable` or `resource`, which it treats as covered because neither can be written as
     * a native type. A substring test on the whole comment, so a description mentioning either word counts —
     * faithful means reproducing that, not improving on it.
     *
     * Read from the source rather than from metadata, because metadata answers a different question: mago
     * sets `type` for any `@var`, and the original does not count a `@var int` as typed at all.
     *
     * @param string $contents the declaring file's source, read once by the caller
     */
    private static function docblockDefersTyping(string $contents, PropertyMetadata $property): bool
    {
        $at = $property->nameLocation;
        if (! $at instanceof SourceLocation) {
            return false;
        }

        $before = substr($contents, 0, $at->span->start);
        $closes = strrpos($before, '*/');
        if ($closes === false) {
            return false;
        }

        // Everything between the comment and the property must be modifiers, attributes and whitespace. A
        // `;` or a brace means the comment belongs to whatever came before, not to this declaration.
        if (preg_match('/[;{}]/', substr($before, $closes + 2)) === 1) {
            return false;
        }

        $opens = strrpos(substr($before, 0, $closes), '/*');
        if ($opens === false) {
            return false;
        }

        $docblock = substr($before, $opens, $closes - $opens);

        return str_contains($docblock, 'callable') || str_contains($docblock, 'resource');
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
