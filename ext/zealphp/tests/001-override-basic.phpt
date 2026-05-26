--TEST--
zealphp_override() basic function interception
--EXTENSIONS--
zealphp
--FILE--
<?php
$captured = [];
zealphp_override('header', function($h) use (&$captured) {
    $captured[] = $h;
});

header("Content-Type: text/html");
header("X-Custom: test");

echo count($captured) . "\n";
echo $captured[0] . "\n";
echo $captured[1] . "\n";

zealphp_restore_all();
?>
--EXPECT--
2
Content-Type: text/html
X-Custom: test
