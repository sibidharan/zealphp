<?php
echo "body";
$h = new \ZealPHP\HaltException("zealphp exit");
$h->status = 302;
throw $h;
