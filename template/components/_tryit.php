<?php $title ??= 'Try it now'; ?>
<section class="tryit">
  <header class="tryit-head"><span class="tryit-icon">▶</span> <?= htmlspecialchars($title) ?></header>
  <div class="tryit-body"><?= $body ?? '' ?></div>
</section>
