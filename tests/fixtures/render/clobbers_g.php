<?php
// #458 fixture — a page that assigns an ordinary variable named $g (a very
// common name, and ironically ZealPHP's own idiom for the request context).
// Pre-fix this clobbered executeFile()'s RequestContext local and fatalled at
// `$g->_ob_floor = …` ("Attempt to assign property _ob_floor on array" → 500).
// With the isolated runUserFile() scope it must render cleanly.
$g = ['x' => 1];
echo 'clobber-ok';
