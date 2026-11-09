<?php
$title = $title ?? 'Challenge';
$body  = $body ?? '';
$hints = $hints ?? [];
?>
<div class="challenge">
  <h3><?= htmlspecialchars($title) ?></h3>
  <div class="challenge-body"><?= $body ?></div>
  <?php foreach ($hints as $i => $hint): ?>
    <details class="challenge-hint">
      <summary>Hint <?= count($hints) > 1 ? ($i + 1) : '' ?></summary>
      <p><?= $hint ?></p>
    </details>
  <?php endforeach; ?>
</div>
