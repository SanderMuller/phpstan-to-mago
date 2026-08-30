<?php

declare(ticks=1);

namespace Cov;

/**
 * A `declare` that is not `strict_types`. Matching the statement rather than the directive would
 * count this as covered.
 */

final class OtherDeclare {}
