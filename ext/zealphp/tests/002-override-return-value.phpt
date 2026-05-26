--TEST--
zealphp_override() return value forwarding
--EXTENSIONS--
zealphp
--FILE--
<?php
zealphp_override('http_response_code', function($code = null) {
    static $current = 200;
    if ($code !== null) $current = $code;
    return $current;
});

http_response_code(404);
echo http_response_code() . "\n";
http_response_code(201);
echo http_response_code() . "\n";

zealphp_restore_all();
?>
--EXPECT--
404
201
