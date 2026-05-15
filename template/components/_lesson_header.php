<?php
$number   ??= 0;
$title    ??= 'Lesson';
$subtitle ??= '';
$prev     ??= null;
$next     ??= null;
?>
<header class="lesson-header">
  <nav class="lesson-crumb"><a href="/learn" hx-get="/api/learn/page?slug=learn" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn">ZealPHP Learn</a> &nbsp;›&nbsp; Lesson <?= (int)$number ?></nav>
  <h1 class="lesson-title"><?= htmlspecialchars($title) ?></h1>
  <?php if ($subtitle !== ''): ?><p class="lesson-subtitle"><?= htmlspecialchars($subtitle) ?></p><?php endif; ?>
  <div class="lesson-chips">
    <?php if ($prev): ?><a class="lesson-chip lesson-chip-prev" href="/<?= htmlspecialchars($prev['slug']) ?>" hx-get="/api/learn/page?slug=<?= urlencode($prev['slug']) ?>" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/<?= htmlspecialchars($prev['slug']) ?>">← <?= htmlspecialchars($prev['title']) ?></a><?php endif; ?>
    <?php if ($next): ?><a class="lesson-chip lesson-chip-next" href="/<?= htmlspecialchars($next['slug']) ?>" hx-get="/api/learn/page?slug=<?= urlencode($next['slug']) ?>" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/<?= htmlspecialchars($next['slug']) ?>"><?= htmlspecialchars($next['title']) ?> →</a><?php endif; ?>
  </div>
</header>
