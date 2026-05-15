<?php
$id      = $id ?? uniqid('cc');
$question = $question ?? '';
$correct  = $correct ?? '';
$explain  = $explain ?? '';
$options  = $options ?? [];
?>
<div class="concept-check">
  <p class="concept-check-q"><?= $question ?></p>
  <form class="concept-check-form"
        hx-post="/api/learn/demo/check"
        hx-target="#check-<?= $id ?>"
        hx-swap="innerHTML">
    <input type="hidden" name="correct" value="<?= htmlspecialchars($correct) ?>">
    <input type="hidden" name="explain" value="<?= htmlspecialchars($explain) ?>">
    <?php foreach ($options as $key => $label): ?>
      <button type="submit" name="answer" value="<?= htmlspecialchars($key) ?>" class="concept-check-opt"><?= $label ?></button>
    <?php endforeach; ?>
  </form>
  <div id="check-<?= $id ?>"></div>
</div>
