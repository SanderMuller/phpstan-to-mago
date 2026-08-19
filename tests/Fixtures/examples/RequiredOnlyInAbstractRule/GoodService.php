<?php

declare(strict_types=1);

namespace Examples\Required;

use Doctrine\ODM\MongoDB\Repository\DocumentRepository;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Abstract, which is where the rule says a `#[Required]` setter belongs.
 *
 * Rejected by the class-level skip, before the loop. The rule *also* asks `$class->isAbstract()` inside the loop,
 * and that second check is redundant: mutation-checked, removing it changes nothing here, because nothing
 * abstract reaches the loop at all. Translated anyway, because it is in the original's control flow.
 */
abstract class GoodAbstractService
{
    #[Required]
    public function setThing(object $thing): void {}
}

final class GoodCircularService
{
    /**
     * @required
     *
     * Needed to break a circular dependency. The word `circular` is what the rule looks for, and removing that
     * guard reports this method.
     */
    public function setThing(object $thing): void {}
}

final class GoodPrivateService
{
    /**
     * @required
     */
    private function setThing(object $thing): void {}
}

/**
 * A skipped parent type, where the framework's own pattern needs the setter.
 *
 * The class-level skip is what rejects this one — removing it reports here.
 */
final class GoodRepository extends DocumentRepository
{
    #[Required]
    public function setThing(object $thing): void {}
}

/**
 * An interface and a trait, each declaring what the rule reports on a class.
 *
 * The rule bails through `! $classLike instanceof Class_`, and the transpiler drops that guard, claiming the class
 * declaration hook never fires for another class-like. These two are the proof: if the claim is wrong, the port
 * reports here and this pair fails. PHPStan visits both through `InClassNode` and bails on the guard itself.
 */
interface GoodContract
{
    #[Required]
    public function setThing(object $thing): void;
}

trait GoodWiringTrait
{
    #[Required]
    public function setThing(object $thing): void {}
}
