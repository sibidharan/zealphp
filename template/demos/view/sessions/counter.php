<?php
/**
 * /demo/view/sessions/counter — the /learn/sessions counter in its own shell (sync scoped to PHPSESSID)
 *
 * @var int $n the session's current count
 */
use ZealPHP\App;
?>
<p class="demo-shell-desc">Click <strong>+1</strong>. Open this URL in a second window — both update live via a WebSocket broadcast scoped to your <code class="demo-inline">PHPSESSID</code>. Foundations &rarr; <a href="/learn/sessions">Sessions</a> explains the mechanism.</p>
<h2 class="demo-shell-h">Live counter</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <div class="lsess-center">
        <?= App::component('/learn/sessions/components/session_counter', ['n' => $n]) ?>
        <div data-session-counter-status class="ws-counter-status lsess-counter-status">starting…</div>
      </div>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">How it works</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <pre class="demo-payload">// Client
const ws = new WebSocket(proto + '//' + host + '/ws/session-counter');
ws.onmessage = e =&gt; document.getElementById('session-counter-btn')
                       ?.outerHTML = e.data;

// Server (excerpt — route/learn.php)
use ZealPHP\Learn\Demo;   // broadcast helper lives in a src/ class

$app-&gt;route('/api/learn/demo/session-bump', ...
    $html = (string) App::component('session_counter', ['n' =&gt; $n]);
    Demo::ws_session_counter_broadcast($sid, $html);
    return $html;   // for the clicking tab&rsquo;s htmx swap
);</pre>
    </div>
  </div>
</div>
