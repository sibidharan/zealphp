<?php
/**
 * /demo/view/store/incr — live cross-tab Store::incr() counter
 */
?>
<p class="demo-shell-desc"><code class="demo-inline">Store::incr('table', 'row', 'column', $by)</code> increments an integer column atomically across all workers. No locks, no read-modify-write race &mdash; one syscall. Click <strong>+1</strong>. Open this URL in another window: every tab tracks the same value.</p>
<h2 class="demo-shell-h">Live counter (atomic across workers)</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <div class="ws-counter-card">
        <div class="ws-counter-value" data-store-counter-value>0</div>
        <p class="ws-counter-label">stored as <code>ws_store_demo_data.shared_row.n</code> &middot; bumped via <code>Store::incr()</code></p>
        <div class="ws-counter-actions">
          <button type="button" class="btn btn-primary" data-store-counter="bump">+1</button>
          <button type="button" class="btn btn-ghost" data-store-counter="reset">Reset</button>
        </div>
        <div data-store-status class="ws-counter-status">connecting…</div>
      </div>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Open in 2+ tabs to see it</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <p class="demo-flush">Multiple tabs all subscribe to <code class="demo-inline">/ws/store-demo</code>. Each bump POSTs to <code class="demo-inline">/api/learn/demo/store-bump</code>, which calls <code class="demo-inline">Store::incr()</code> and broadcasts the new value over the WebSocket to every connected client. <strong>This is what <code class="demo-inline">/learn/websocket</code> uses for its real-time-sync section.</strong></p>
    </div>
  </div>
</div>
