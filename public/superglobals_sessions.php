<?php
session_start();
if (!isset($_SESSION['count'])) $_SESSION['count'] = 0;
$_SESSION['count']++;
echo 'Count: ' . $_SESSION['count'];
session_write_close();
