<?php
ob_start();
echo 'BUF';
$c = ob_get_clean();
echo 'OUT:' . $c;
