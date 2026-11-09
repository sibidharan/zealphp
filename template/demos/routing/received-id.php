<?php
use function ZealPHP\e;

/**
 * /metric/get/{id} and /data/{id} — echo the path parameter.
 *
 * @var string $kind
 * @var string $id
 */
?>
<h1>This is example <?= e($kind) ?> to receive ID: <?= e($id) ?></h1>
