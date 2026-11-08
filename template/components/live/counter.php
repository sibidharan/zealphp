<?php use function ZealPHP\e; ?>
<span class="lc-count" aria-live="polite"><?= e($count) ?></span>
<span class="lc-buttons">
  <button <?= $c->action('add', ['by' => -1]) ?> class="btn btn-ghost btn-sm lc-btn">−1</button>
  <button <?= $c->action('add') ?> class="btn btn-primary btn-sm">+1</button>
  <button <?= $c->action('add', ['by' => 10]) ?> class="btn btn-ghost btn-sm lc-btn">+10</button>
  <button <?= $c->action('reset') ?> class="btn btn-ghost btn-sm">Reset</button>
</span>
