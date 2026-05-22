<?php
// Component: feature card
// Variables: $icon, $title, $body, $href, $badge (optional)
$icon  ??= '⚡';
$title ??= '';
$body  ??= '';
$href  ??= '#';
$badge ??= '';
?>
<a href="<?= $href ?>" class="card card-link-reset">
  <div class="card-icon"><?= $icon ?></div>
  <?php if ($badge): ?><span class="badge badge-feature card-badge"><?= htmlspecialchars($badge) ?></span><?php endif; ?>
  <h3><?= htmlspecialchars($title) ?></h3>
  <p><?= htmlspecialchars($body) ?></p>
  <span class="card-link">Explore →</span>
</a>
