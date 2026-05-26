--TEST--
zealphp_override() blocks double-override without restore
--EXTENSIONS--
zealphp
--FILE--
<?php
$r1 = zealphp_override('header', function() { echo "first\n"; });
var_dump($r1);

$r2 = @zealphp_override('header', function() { echo "second\n"; });
var_dump($r2);

// First override still active
header("test");

zealphp_restore_all();
?>
--EXPECT--
bool(true)
bool(false)
first
