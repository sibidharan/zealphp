--TEST--
zealphp_override() rejects non-allowed functions
--EXTENSIONS--
zealphp
--FILE--
<?php
$result = @zealphp_override('strlen', function($s) { return 42; });
var_dump($result);

$result = @zealphp_override('array_map', function() { return []; });
var_dump($result);

// Allowed function works
$result = zealphp_override('header', function() {});
var_dump($result);

zealphp_restore_all();
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
