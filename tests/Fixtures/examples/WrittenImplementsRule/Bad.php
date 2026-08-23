<?php

declare(strict_types=1);

namespace Examples\Implementing;

interface Contract {}

class Implementing implements Contract {}

/** Writes no `implements` clause, so the rule reports. */
final class Bare {}

/**
 * The discriminating case. Its own declaration writes no `implements`, so PHPStan reports it — while the
 * transitive interface list is not empty, because its parent implements `Contract`. Reading that list instead
 * of the written one goes silent here.
 */
final class ThroughParent extends Implementing {}
