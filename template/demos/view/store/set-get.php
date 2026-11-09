<?php
/**
 * /demo/view/store/set-get — live cross-tab Store row (driven by /js/learn-demo-viewers.js)
 */
?>
<p class="demo-shell-desc">Write a row to a shared-memory <code class="demo-inline">Store</code> table. Every worker can read what any other worker wrote &mdash; no Redis, no network round-trip. Open this page in another window: click <strong>Write row</strong> here and watch the row contents update there live.</p>
<h2 class="demo-shell-h">Write a row</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <form data-store-form class="demo-store-form">
        <label>name<input name="name" required maxlength="60" placeholder="e.g. high-score"></label>
        <label>who<input name="who" maxlength="60" value="viewer"></label>
        <button type="submit" class="btn btn-primary">Write row</button>
      </form>
      <p class="demo-hint">Hits <code class="demo-inline">Store::set('ws_store_demo_data', 'shared_row', […])</code>. Broadcasts to every connected tab.</p>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Current row (live across tabs)</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <pre class="demo-payload" data-store-row>connecting…</pre>
      <div data-store-status class="ws-counter-status demo-status-gap">connecting…</div>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">How it works</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <pre class="demo-payload">use ZealPHP\Learn\Demo;   // broadcast helper lives in a src/ class

// route/learn.php — boot
Store::make('ws_store_demo_data', 32, [
    'n'    =&gt; [Store::TYPE_INT,    8],
    'name' =&gt; [Store::TYPE_STRING, 64],
    'who'  =&gt; [Store::TYPE_STRING, 64],
    'ts'   =&gt; [Store::TYPE_INT,    8],
]);

// POST /api/learn/demo/store-write
Store::set('ws_store_demo_data', 'shared_row', [
    'name' =&gt; $name,
    'who'  =&gt; $who,
    'ts'   =&gt; time(),
]);
Demo::ws_store_demo_broadcast();   // push current row to every /ws/store-demo client</pre>
    </div>
  </div>
</div>
