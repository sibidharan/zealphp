<?php
/**
 * PHPStan-only stub file. Defines constants that may be missing from the
 * PHP build PHPStan runs against (e.g. pcntl ITIMER_* on systems where
 * pcntl is loaded but the timer-family constants are not exposed).
 *
 * This file is referenced from phpstan.neon under bootstrapFiles only —
 * it is never autoloaded at runtime.
 */

if (!defined('ITIMER_REAL'))    define('ITIMER_REAL', 0);
if (!defined('ITIMER_VIRTUAL')) define('ITIMER_VIRTUAL', 1);
if (!defined('ITIMER_PROF'))    define('ITIMER_PROF', 2);
