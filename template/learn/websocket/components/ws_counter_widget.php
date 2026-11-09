<?php
// WebSocket cross-tab counter widget. Rendered in two places:
//   1) inline at /learn/websocket as the "Try it" step
//   2) standalone at /demo/view/websocket/counter for cross-tab testing
// The `data-ws-counter*` attribute names are wired by public/js/learn-demo-viewers.js;
// the counter value is broadcast via the /ws/counter-demo endpoint declared in
// route/learn.php. No-auth — purely a teaching demo.
$as_demo ??= false;
?>
<section<?= $as_demo ? '' : ' id="step-tryit"' ?> class="ws-counter-card">
  <div class="ws-counter-value" data-ws-counter-value>0</div>
  <p class="ws-counter-label">connected clients see this value update in real time</p>
  <div class="ws-counter-actions">
    <button type="button" class="btn btn-primary" data-ws-counter="bump">+1</button>
    <button type="button" class="btn btn-ghost" data-ws-counter="reset">Reset</button>
  </div>
  <div data-ws-counter-status class="ws-counter-status">starting&hellip;</div>
</section>
