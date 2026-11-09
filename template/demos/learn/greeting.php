<?php
/**
 * /api/learn/demo/greeting body.
 *
 * @var string $name
 * @var string $time H:i:s the page was rendered at
 */
use function ZealPHP\e;
?>
<h2>Hello, <?= e($name) ?>!</h2><p>This page was rendered by ZealPHP at <?= e($time) ?>.</p>
