<?php
set_error_handler(function($n, $s) { echo 'ERR:' . $s; });
trigger_error('TEST', E_USER_NOTICE);
