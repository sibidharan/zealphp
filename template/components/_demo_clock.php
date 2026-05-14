<?php
$label ??= 'row';
$now   ??= microtime(true);
?>
<div class="render-demo-row" data-label="<?= htmlspecialchars($label) ?>">
  <strong><?= htmlspecialchars($label) ?></strong>
  <time><?= number_format($now - (int)$now, 4) ?>s</time>
</div>
