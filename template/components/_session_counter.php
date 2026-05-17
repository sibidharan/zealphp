<?php $n = (int)($n ?? 0); ?>
<button id="session-counter-btn"
        class="counter-btn"
        data-session-counter
        hx-post="/api/learn/demo/session-bump"
        hx-target="this"
        hx-swap="outerHTML">
  Clicked <strong><?= $n ?></strong> times
</button>
