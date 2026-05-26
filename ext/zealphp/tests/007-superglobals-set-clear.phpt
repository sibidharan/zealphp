--TEST--
zealphp_superglobals_set() and zealphp_superglobals_clear()
--EXTENSIONS--
zealphp
--FILE--
<?php
zealphp_superglobals_set(
    ['name' => 'Alice'],
    ['title' => 'Note'],
    ['SID' => 'abc'],
    ['METHOD' => 'POST'],
    [],
    ['name' => 'Alice']
);

echo $_GET['name'] . "\n";
echo $_POST['title'] . "\n";
echo $_COOKIE['SID'] . "\n";
echo $_SERVER['METHOD'] . "\n";

zealphp_superglobals_clear();
echo empty($_GET) ? "cleared" : "NOT cleared";
echo "\n";
?>
--EXPECT--
Alice
Note
abc
POST
cleared
