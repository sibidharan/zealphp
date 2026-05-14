<?php
$variant ??= 'info';
$title   ??= '';
$icon    = [ 'info' => 'ℹ', 'warn' => '⚠', 'success' => '✓', 'deep' => '🔎' ][$variant] ?? 'ℹ';
?>
<aside class="callout callout-<?= htmlspecialchars($variant) ?>">
  <div class="callout-head"><span class="callout-icon"><?= $icon ?></span><?php if ($title !== ''): ?><strong><?= htmlspecialchars($title) ?></strong><?php endif; ?></div>
  <div class="callout-body"><?= $body ?? '' ?></div>
</aside>
