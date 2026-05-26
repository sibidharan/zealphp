--TEST--
zealphp_restore() and zealphp_restore_all()
--EXTENSIONS--
zealphp
--FILE--
<?php
// Override two functions
zealphp_override('header', function($h) { echo "INTERCEPTED: $h\n"; });
zealphp_override('http_response_code', function() { return 999; });

header("X-Test: 1");
echo http_response_code() . "\n";

// Restore one
zealphp_restore('header');
// header() is back to native — triggers warning since output started
@header("X-After-Restore: silent");
echo http_response_code() . "\n"; // still overridden

// Restore all
zealphp_restore_all();
echo "done\n";
?>
--EXPECT--
INTERCEPTED: X-Test: 1
999
999
done
