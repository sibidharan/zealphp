<?php
/**
 * /demo/view/streaming/sse — opens an EventSource to /stream/events from the page
 */
?>
<p class="demo-shell-desc">SSE is one-way push over plain HTTP &mdash; the browser opens an <code class="demo-inline">EventSource</code>, the server keeps the response open and emits named events. <code class="demo-inline">$response->sse($fn)</code> sets the right headers and gives you an <code class="demo-inline">$emit($data, $event, $id)</code> callback. Below: <strong>Connect</strong> opens an <code class="demo-inline">EventSource</code> to <code class="demo-inline">/stream/events</code>; the event log fills with one tick per second.</p>
<h2 class="demo-shell-h">How it's wired (route side)</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <pre class="demo-payload">$app->route('/stream/events', function ($response) {
    $response->sse(function ($emit) {
        $emit(json_encode(['status' =&gt; 'connected']), 'open');
        for ($i = 1; $i &lt;= 10; $i++) {
            co::sleep(1);
            $emit(json_encode(['tick' =&gt; $i]), 'tick', (string)$i);
        }
        $emit(json_encode(['done' =&gt; true]), 'done');
    });
});</pre>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Connect</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <button class="demo-action-btn" type="button" data-viewer-action="sse-start" data-demo-url="/stream/events" data-target="demo-sse-out">▶ Connect EventSource</button> <button class="demo-action-btn ghost" type="button" data-viewer-action="sse-stop">■ Disconnect</button><div id="demo-sse-out" class="demo-live-output">Click <em>Connect</em>. One event per second, 10 ticks then auto-closes.</div>
    </div>
  </div>
</div>
