<?php
/**
 * /demo/view/counter/increment — mirrors the /learn/websocket counter over /ws/counter-demo
 */
?>
<p class="demo-shell-desc"><code class="demo-inline">$counter-&gt;increment()</code> wraps <code class="demo-inline">OpenSwoole\Atomic</code> &mdash; lock-free, cross-worker, no syscall per bump. Click <strong>+1</strong>. Open this URL in another window/tab and click +1 there: every tab updates live via a WebSocket broadcast. Reset zeros the counter for everyone.</p>
<h2 class="demo-shell-h">Live counter (shared across tabs)</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <div class="ws-counter-card">
        <div class="ws-counter-value" data-ws-counter-value>0</div>
        <p class="ws-counter-label">value lives in shared memory; every worker + every tab sees the same number</p>
        <div class="ws-counter-actions">
          <button type="button" class="btn btn-primary" data-ws-counter="bump">+1</button>
          <button type="button" class="btn btn-ghost" data-ws-counter="reset">Reset</button>
        </div>
        <div data-ws-counter-status class="ws-counter-status">connecting…</div>
      </div>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">How it works</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <pre class="demo-payload">use ZealPHP\Learn\Demo;   // broadcast helper lives in a src/ class

// route/learn.php — boot
$wsCounterDemo = new Counter(0);
Store::make('ws_counter_demo_clients', 4096, [...]);

// WebSocket endpoint — track open fds
$app-&gt;ws('/ws/counter-demo',
    onOpen: function ($server, $request) use ($wsCounterDemo) {
        Store::set('ws_counter_demo_clients', (string)$request-&gt;fd, [...]);
        $server-&gt;push($request-&gt;fd, json_encode(['value' =&gt; $wsCounterDemo-&gt;get()]));
    },
    /* ... onClose, onMessage ... */
);

// Bump endpoint — increment + broadcast to every connected fd
$app-&gt;route('/api/learn/demo/counter-bump', ['methods' =&gt; ['POST']], function () use ($wsCounterDemo) {
    $new = $wsCounterDemo-&gt;increment();
    Demo::ws_counter_demo_broadcast((int)$new);
    return ['value' =&gt; (int)$new];
});</pre>
    </div>
  </div>
</div>
