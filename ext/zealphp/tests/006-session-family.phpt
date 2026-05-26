--TEST--
zealphp_override() works for session function family
--EXTENSIONS--
zealphp
--FILE--
<?php
$log = [];

zealphp_override('session_start', function() use (&$log) {
    $log[] = 'start';
    return true;
});
zealphp_override('session_id', function($id = '') use (&$log) {
    $log[] = "id:$id";
    return 'zealphp-sess-abc';
});
zealphp_override('session_destroy', function() use (&$log) {
    $log[] = 'destroy';
    return true;
});

session_start();
echo session_id() . "\n";
session_destroy();

echo implode(',', $log) . "\n";
zealphp_restore_all();
?>
--EXPECT--
zealphp-sess-abc
start,id:,destroy
