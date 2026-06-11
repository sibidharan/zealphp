<?php
echo "a";
ob_start();
echo "b";
$h = new \ZealPHP\HaltException("zealphp exit");
$h->status = "-c";
throw $h;
