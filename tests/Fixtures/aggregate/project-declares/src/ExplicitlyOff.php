<?php

declare(strict_types=0);

namespace Cov;

/**
 * `strict_types=0`, which the collector reads as *not* covered. This is the file that makes the
 * `=1` load-bearing: matching `strict_types` alone takes the fixture from 1 typed of 4 to 2, and the
 * comparison against the real rule fails on exactly that.
 */

final class ExplicitlyOff {}
