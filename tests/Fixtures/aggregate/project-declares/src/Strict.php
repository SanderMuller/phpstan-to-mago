<?php

declare(strict_types=1);

namespace Cov;

/**
 * The one file that counts as covered. Without it the percentage is 0 and every filter below
 * could be broken without the comparison noticing.
 */

final class Strict {}
