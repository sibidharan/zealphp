<?php $title ??= 'Deep dive'; ?>
<details class="deepdive">
  <summary><span class="deepdive-icon">🔎</span> <?= htmlspecialchars($title) ?></summary>
  <div class="deepdive-body"><?= $body ?? '' ?></div>
</details>
