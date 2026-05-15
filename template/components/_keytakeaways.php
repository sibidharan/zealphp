<?php $items = $items ?? []; ?>
<div class="key-takeaways">
  <h3>Key Takeaways</h3>
  <ul>
    <?php foreach ($items as $item): ?>
      <li><?= $item ?></li>
    <?php endforeach; ?>
  </ul>
</div>
