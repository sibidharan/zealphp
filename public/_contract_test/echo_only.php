<?php
// Universal return contract fixture: no explicit return, just echo.
$g = \ZealPHP\RequestContext::instance();
echo 'ECHOED:' . ($g->server['PHP_SELF'] ?? 'no-self');
