<?php $n = (int)($n ?? 0); ?>
<button class="counter-btn"
        hx-post="/api/learn/demo/incr"
        hx-target="this"
        hx-swap="outerHTML">
  Clicked <strong><?= $n ?></strong> times
</button>
