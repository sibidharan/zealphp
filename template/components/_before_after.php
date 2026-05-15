<?php
$id     = $id ?? uniqid('ba');
$before_label = $before_label ?? 'Before';
$after_label  = $after_label ?? 'After';
$before = $before ?? '';
$after  = $after ?? '';
?>
<div class="before-after" id="ba-<?= $id ?>">
  <div class="ba-tabs">
    <button class="ba-tab active" onclick="this.parentElement.parentElement.querySelector('.ba-panel-before').classList.remove('hidden');this.parentElement.parentElement.querySelector('.ba-panel-after').classList.add('hidden');this.classList.add('active');this.nextElementSibling.classList.remove('active')"><?= htmlspecialchars($before_label) ?></button>
    <button class="ba-tab" onclick="this.parentElement.parentElement.querySelector('.ba-panel-after').classList.remove('hidden');this.parentElement.parentElement.querySelector('.ba-panel-before').classList.add('hidden');this.classList.add('active');this.previousElementSibling.classList.remove('active')"><?= htmlspecialchars($after_label) ?></button>
  </div>
  <div class="ba-panel ba-panel-before"><?= $before ?></div>
  <div class="ba-panel ba-panel-after hidden"><?= $after ?></div>
</div>
