<?php

declare(strict_types=1);

namespace Examples\Wiring;

use Symfony\Contracts\Service\Attribute\Required;

/**
 * One method per way the rule says no.
 *
 * The last pair is the trap worth naming. Mago hands docblocks back as file-level trivia with no owner, so
 * associating one with a declaration is arithmetic this package owns: a method with no docblock must not inherit
 * the previous method's. `documentedButPrivate` carries an `@required` docblock and is rejected for being
 * private; `inheritsNothing` follows it with no docblock of its own and is public, so a mis-association would
 * report it.
 */
trait GoodWiring
{
    #[Required]
    private function privateByAttribute(object $thing): void {}

    /**
     * @required
     */
    protected function protectedByAnnotation(object $thing): void {}

    #[Required]
    public function __construct() {}

    /**
     * Not the annotation the rule looks for.
     */
    public function setSomethingElse(object $thing): void {}

    #[SomeOtherAttribute]
    public function setByAnotherAttribute(object $thing): void {}

    /**
     * @required
     */
    private function documentedButPrivate(object $thing): void {}

    public function inheritsNothing(object $thing): void {}
}
