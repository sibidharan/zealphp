<?php
echo "before-";
$h = new \ZealPHP\HaltException("zealphp exit");
$h->status = "redirected";
throw $h;
