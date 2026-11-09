<?php
use function ZealPHP\e;

/**
 * /stream/ssr — one section, streamed when its fetch resolves. The script
 * removes the section's skeleton (it runs as the parser reaches it).
 *
 * @var string       $id      skeleton id to remove
 * @var string       $title
 * @var list<string> $items
 * @var float        $elapsed seconds since the request started
 * @var string       $delay   the simulated fetch time
 */
?>
<script src="/js/stream-ssr.js" data-remove="<?= e($id) ?>"></script>
<div class="card">
  <h2><?= e($title) ?></h2><ul><?php foreach ($items as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
  <p class="tag">resolved in <?= e($elapsed) ?>s (simulated <?= e($delay) ?> fetch)</p>
</div>
