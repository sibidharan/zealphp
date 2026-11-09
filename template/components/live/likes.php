<?php use function ZealPHP\e; ?>
<span class="lc-count" aria-live="polite"><?= e($likes) ?></span>
<span class="lc-buttons">
  <button <?= $c->action('like') ?> class="btn btn-primary btn-sm">♥ Like</button>
</span>
<small class="lc-note">Open this page in a second tab and click Like there.</small>
