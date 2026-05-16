<?php

// Backward-compatibility shim. The class was renamed to `RequestContext`
// in v0.2.6; `\ZealPHP\G` is registered as a class_alias inside
// RequestContext.php so both names resolve to the same class. This file
// exists so PSR-4 autoloading still finds the old name and triggers the
// canonical class to load.

//G for $GLOBALS (coroutine safe) - just a backwards compatibility alias for RequestContext. 

require_once __DIR__ . '/RequestContext.php';
