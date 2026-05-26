<?php
session_start();
$user = $_GET['user'] ?? 'Guest';
$_SESSION['last_user'] = $user;
header('Content-Type: text/plain');
echo "Hello $user!\n";
echo "Last user: " . ($_SESSION['last_user'] ?? 'None') . "\n";
echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
