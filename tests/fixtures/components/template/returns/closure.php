<?php use function ZealPHP\e; return function (string $title, int $n = 2) { yield '<b>' . e($title) . '</b>'; yield str_repeat('!', $n); };
