<?php
use ZealPHP\App;
$v = defined('ZEALPHP_ASSET_VERSION') ? ZEALPHP_ASSET_VERSION : (string)time();
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTML Page with Header, Side Panel, Body, and Footer</title>
    <link rel="stylesheet" href="/css/home-layout-demo.css?v=<?= $v ?>">
</head>