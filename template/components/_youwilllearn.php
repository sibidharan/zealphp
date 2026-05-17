<?php $items ??= []; ?>
<section class="youwilllearn">
  <h3>You will learn</h3>
  <ul>
    <?php /* Items render as raw HTML so lesson authors can include <code>,
             <em>, <a>, and HTML entities. All callers are hardcoded lesson
             templates — no user input flows here. Same contract as
             _keytakeaways. */ ?>
    <?php foreach ($items as $item): ?>
      <li><?= $item ?></li>
    <?php endforeach; ?>
  </ul>
</section>
