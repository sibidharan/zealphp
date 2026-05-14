<?php $items ??= []; ?>
<section class="youwilllearn">
  <h3>You will learn</h3>
  <ul>
    <?php foreach ($items as $item): ?>
      <li><?= htmlspecialchars($item) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
