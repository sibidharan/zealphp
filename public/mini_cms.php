<?php
session_start();
header('Content-Type: text/plain');
$user = $_GET['user'] ?? 'Guest';
echo "User: $user\n";
echo "Session: " . ($_SESSION['user'] ?? 'NONE') . "\n";
$_SESSION['user'] = $user;
session_write_close();
