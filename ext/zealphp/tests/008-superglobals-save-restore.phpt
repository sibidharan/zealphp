--TEST--
zealphp_superglobals_save() and zealphp_superglobals_restore() — coroutine simulation
--EXTENSIONS--
zealphp
--FILE--
<?php
// Simulate coroutine A
zealphp_superglobals_set(['user' => 'CoroutineA'], [], [], [], [], []);
$snapA = zealphp_superglobals_save();

// Simulate coroutine B overwrites
zealphp_superglobals_set(['user' => 'CoroutineB'], [], [], [], [], []);
echo "Current: " . $_GET['user'] . "\n";

// Restore A
zealphp_superglobals_restore($snapA);
echo "Restored: " . $_GET['user'] . "\n";

// Clear
zealphp_superglobals_clear();
echo "Cleared: " . (empty($_GET) ? "yes" : "no") . "\n";
?>
--EXPECT--
Current: CoroutineB
Restored: CoroutineA
Cleared: yes
