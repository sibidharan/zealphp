<?php use ZealPHP\App; ?>

<section class="section section-dark">
<div class="container" style="max-width:960px">

<h1 class="section-title">Migrate your PHP codebase to async</h1>
<p class="section-desc">
  Bring your existing code along. <code>session_start()</code>, <code>header()</code>,
  <code>$_GET</code>, <code>$_POST</code>, <code>echo</code> — all overridden via uopz to
  work inside the coroutine runtime, so the migration ladder starts with "drop your
  app in and run <code>php app.php</code>" rather than "rewrite for an event loop."
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 1. The before/after stack collapse                             -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:2.5rem">From several services to one process</h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1rem">
  <div class="qs-block" style="padding:1.25rem 1.5rem">
    <h3 style="margin:0 0 .85rem;font-size:1rem">Typical PHP stack today</h3>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>Nginx / Apache (front-end)</li>
      <li>PHP-FPM (cold start every request)</li>
      <li>Redis (sessions, cache, pub/sub)</li>
      <li>Socket.io / Ratchet (WebSocket)</li>
      <li>Supervisor / cron (background jobs)</li>
      <li>SSE proxy or browser polling</li>
    </ul>
    <p style="margin:.75rem 0 0;color:#a8a29e;font-size:.78rem">6 services, 6 failure points, 6 sets of config.</p>
  </div>
  <div class="qs-block" style="padding:1.25rem 1.5rem;border-color:var(--accent)">
    <h3 style="margin:0 0 .85rem;font-size:1rem;color:var(--accent)">Same app on ZealPHP</h3>
    <div style="text-align:center;margin:.5rem 0 1rem">
      <code style="font-size:1.05rem;color:var(--accent);background:rgba(245,158,11,.1);padding:.4rem .8rem;border-radius:6px">php app.php</code>
    </div>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>HTTP + WebSocket + SSE built in</li>
      <li>Coroutine-safe sessions (no Redis)</li>
      <li>Shared memory across workers (Store, Counter)</li>
      <li>Task workers (no cron / supervisor)</li>
      <li>Persistent connections, no cold starts</li>
      <li>WordPress via the CGI bridge — <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank" rel="noopener">showcase</a></li>
    </ul>
    <p style="margin:.75rem 0 0;color:#a8a29e;font-size:.78rem">Not every stack fits. Depends on app — see "When migration won't help" below.</p>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 2. The migration ladder                                        -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:3rem">The migration ladder — go at your own pace</h2>

<p style="margin-bottom:1.25rem">
  Each rung is functional on its own. Stop at the rung that gives you enough
  upside without forcing changes you're not ready for. Most real migrations
  stay between rungs 1 and 3 for months before reaching 4.
</p>

<div style="display:grid;gap:.75rem">

<?php
$rungs = [
  [
    'n'    => '0',
    'title' => 'Drop in your entire app, unchanged',
    'code'  => 'App::superglobals(true); $app->setFallback(fn() => App::includeFile(\'index.php\'));',
    'desc'  => 'Most existing PHP apps — WordPress, Drupal, custom legacy code — run unchanged on OpenSwoole through the CGI worker bridge. No code edits required to start serving requests faster.',
    'wins'  => 'Persistent process, no per-request boot. Sub-millisecond TTFB on cached routes.',
    'gives_up' => 'Coroutines, WebSocket, SSE — you\'re still bound by the global-state model.',
  ],
  [
    'n'    => '1',
    'title' => 'Write LAMP-style PHP in <code>public/</code>',
    'code'  => 'public/about.php → /about     ·     public/users/list.php → /users/list',
    'desc'  => 'File-based routing. <code>$_GET</code>, <code>session_start()</code>, <code>echo</code> — everything you know works. No new mental model.',
    'wins'  => 'Add new endpoints without leaving the LAMP idiom your team already uses.',
    'gives_up' => 'Nothing — this is purely additive.',
  ],
  [
    'n'    => '2',
    'title' => 'Add REST APIs in <code>api/</code>',
    'code'  => 'api/users/get.php → GET /api/users     ·     api/users/post.php → POST /api/users',
    'desc'  => 'Drop a PHP file, get a REST endpoint. ZealAPI auto-routes by filename and HTTP method. Zero config, zero framework boilerplate.',
    'wins'  => 'Replace your "PHP file behind nginx" API layer with structured endpoints in 5 lines each.',
    'gives_up' => 'Still synchronous — handlers run sequentially. Fine for I/O-light endpoints.',
  ],
  [
    'n'    => '3',
    'title' => 'Use framework routes for new features',
    'code'  => '$app->route(\'/ws/chat\', ...); $response->sse(...); yield $html;',
    'desc'  => 'WebSocket, SSE streaming, coroutines — available when you\'re ready, not forced upfront. Mix file-based pages with programmatic routes in the same app.',
    'wins'  => 'Real-time features without spinning up a separate Node/Go service. Stream AI responses, push live updates, run background coroutines.',
    'gives_up' => 'Still allows blocking calls inside individual handlers — coroutine isolation is opt-in at rung 4.',
  ],
  [
    'n'    => '4',
    'title' => 'Full coroutine mode',
    'code'  => 'App::superglobals(false);   // thousands of concurrent requests per worker',
    'desc'  => 'Replace <code>$_GET</code>/<code>$_SESSION</code> globals with <code>G::instance()</code>. Each coroutine gets its own context; one worker handles thousands of concurrent requests without blocking.',
    'wins'  => 'Peak throughput. <a href="/performance">117k req/s on 4 workers</a> — Express on the same box does 20k.',
    'gives_up' => 'You must avoid blocking I/O outside coroutine-hooked extensions, and any code that mutates global state needs a per-coroutine equivalent.',
    'highlight' => true,
  ],
];
foreach ($rungs as $r):
  $border = !empty($r['highlight']) ? 'border-color:var(--accent)' : '';
?>
  <div class="qs-block" style="padding:1rem 1.25rem;<?= $border ?>">
    <div style="display:grid;grid-template-columns:auto 1fr;gap:1rem;align-items:start">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:rgba(245,158,11,.18);color:var(--accent);font-size:.85rem;font-weight:700;flex-shrink:0"><?= $r['n'] ?></span>
      <div>
        <div style="font-weight:700;font-size:1rem;margin-bottom:.4rem"><?= $r['title'] ?></div>
        <code style="display:block;font-size:.78rem;color:#fde68a;background:rgba(245,158,11,.08);padding:.4rem .6rem;border-radius:4px;margin-bottom:.5rem"><?= $r['code'] ?></code>
        <p style="margin:0 0 .4rem;font-size:.88rem;line-height:1.6"><?= $r['desc'] ?></p>
        <p style="margin:.25rem 0 0;font-size:.82rem;line-height:1.5"><strong style="color:var(--accent)">Wins:</strong> <?= $r['wins'] ?></p>
        <p style="margin:.15rem 0 0;font-size:.82rem;line-height:1.5;color:#a8a29e"><strong>Trade-off:</strong> <?= $r['gives_up'] ?></p>
      </div>
    </div>
  </div>
<?php endforeach; ?>

</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3. How the compatibility bridge actually works                 -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:3rem">How the compatibility bridge works</h2>

<p>
  PHP-FPM gives you fresh superglobals (<code>$_GET</code>, <code>$_SESSION</code>),
  fresh <code>header()</code>, fresh <code>session_start()</code> on every request.
  OpenSwoole is one long-running process — those functions would normally collide
  across requests. ZealPHP fixes that via three mechanisms:
</p>

<ul style="line-height:1.8;margin-top:.5rem">
  <li>
    <strong>uopz function overrides.</strong> At server boot, <code>header()</code>,
    <code>setcookie()</code>, <code>http_response_code()</code>, and the
    <code>session_*()</code> family are replaced with implementations that read/write
    a per-request <code>G::instance()</code> object. Your <code>header('Location: /foo')</code>
    routes to the right OpenSwoole response without you knowing.
  </li>
  <li>
    <strong>Stream-wrapper redirection.</strong>
    <code>php://input</code> is rewired to return the current request body,
    not stdin. Legacy code that does <code>file_get_contents('php://input')</code>
    in a JSON API handler works unchanged.
  </li>
  <li>
    <strong>CGI worker bridge.</strong> When <code>App::superglobals(true)</code> +
    <code>setFallback()</code> are in use, requests that don't match a framework
    route are forwarded to a CGI-style child process via <code>proc_open</code> —
    full process isolation, just like mod_php. That's how WordPress runs.
  </li>
</ul>

<p style="margin-top:.75rem">
  Net effect — at rung 0 and 1, your code can't tell it's running on OpenSwoole.
  At rungs 3 and 4, you opt into the coroutine model where it pays off.
</p>

<h2 style="margin-top:3rem">Apache+mod_php parity reference</h2>
<p>What ZealPHP emulates so legacy apps run unchanged. Most of this is invisible — these rows exist to answer "does X work?" without a code-dive.</p>

<h3 style="margin-top:1.5rem;font-size:1.05rem">Function overrides (via uopz)</h3>
<table class="ztable">
  <tr><th>Apache+mod_php function</th><th>ZealPHP behavior</th></tr>
  <tr><td><code>header()</code>, <code>header_remove()</code>, <code>headers_list()</code>, <code>headers_sent()</code></td><td>Per-request via <code>G-&gt;response_headers_list</code>. Supports <code>header("HTTP/1.1 404 Not Found")</code> status-line form and the optional <code>$http_response_code</code> param.</td></tr>
  <tr><td><code>setcookie()</code>, <code>setrawcookie()</code></td><td>Per-request via <code>G-&gt;response_cookies_list</code> / <code>response_rawcookies_list</code>. <code>setrawcookie</code> preserves the raw value (no urlencoding).</td></tr>
  <tr><td><code>http_response_code()</code></td><td>Per-request via <code>G-&gt;status</code>.</td></tr>
  <tr><td><code>flush()</code>, <code>ob_flush()</code>, <code>ob_end_flush()</code></td><td>Switch the response into streaming mode — buffer pushed to OpenSwoole's <code>$response-&gt;write()</code>, flips <code>G-&gt;_streaming = true</code>.</td></tr>
  <tr><td><code>apache_request_headers()</code>, <code>getallheaders()</code></td><td>Return canonical (hyphen-capitalized) request headers from the OpenSwoole request.</td></tr>
  <tr><td><code>apache_response_headers()</code></td><td>Returns currently-set outbound headers.</td></tr>
  <tr><td><code>apache_setenv()</code>, <code>apache_getenv()</code>, <code>apache_note()</code></td><td>Per-request scratch tables in <code>G-&gt;apache_env</code> / <code>apache_notes</code>.</td></tr>
  <tr><td><code>virtual()</code></td><td>Returns <code>false</code> — internal subrequests aren't supported in this model.</td></tr>
  <tr><td><code>set_time_limit()</code></td><td>No-op success. OpenSwoole owns the worker/coroutine timeout.</td></tr>
  <tr><td><code>ignore_user_abort()</code>, <code>connection_status()</code>, <code>connection_aborted()</code></td><td>Per-request; reads <code>$response-&gt;isWritable()</code> for connection state.</td></tr>
  <tr><td><code>is_uploaded_file()</code>, <code>move_uploaded_file()</code></td><td>Whitelist of <code>$_FILES['*']['tmp_name']</code> — same security guarantees as mod_php.</td></tr>
  <tr><td><code>session_*()</code> (18 functions)</td><td>Coroutine-safe session lifecycle via <code>CoSessionManager</code>; files in <code>/var/lib/php/sessions</code>.</td></tr>
  <tr><td><code>set_error_handler()</code>, <code>set_exception_handler()</code>, <code>register_shutdown_function()</code>, <code>error_reporting()</code></td><td>Per-coroutine via <code>G</code> stacks. A native dispatcher installed at boot delegates to the active coroutine's handler stack — isolated despite PHP's process-global semantics. See <a href="/responses">Responses</a>.</td></tr>
</table>

<h3 style="margin-top:1.5rem;font-size:1.05rem"><code>public/</code> routing (DocumentRoot behavior)</h3>
<table class="ztable">
  <tr><th>Apache directive</th><th>ZealPHP</th></tr>
  <tr><td><code>DirectoryIndex index.php index.html index.htm</code></td><td>Same fallback order via <code>App::$directory_index</code>. HTML/HTM served via <code>$response-&gt;sendFile()</code> with ETag + Range.</td></tr>
  <tr><td><code>DirectorySlash On</code></td><td><code>/foo</code> → 301 <code>/foo/</code> when <code>foo</code> is a directory.</td></tr>
  <tr><td><code>AcceptPathInfo On</code></td><td><code>/script.php/extra</code> exposes <code>PATH_INFO=/extra</code>; rewrites <code>REQUEST_URI</code>.</td></tr>
  <tr><td><code>&lt;FilesMatch "^\.&gt;"</code> deny</td><td>Dotfile URLs return 403 (<code>.well-known/</code> allow-listed per RFC 8615).</td></tr>
  <tr><td><code>RewriteRule . /index.php [L]</code></td><td><code>App::setFallback(fn() => App::includeFile(...))</code>. Body, status, headers, Generator return all preserved.</td></tr>
  <tr><td><code>ErrorDocument 404 /custom.php</code></td><td><code>App::setErrorHandler(404, $cb)</code>. Catch-all variant: <code>setErrorHandler($cb)</code>. Handlers fire for every 4xx/5xx site in the framework.</td></tr>
  <tr><td><code>FileETag</code> / conditional GET</td><td><code>$response-&gt;sendFile()</code> emits weak ETag + <code>Last-Modified</code>; honors <code>If-None-Match</code> and <code>If-Modified-Since</code> → 304.</td></tr>
</table>

<p style="margin-top:1rem">Deeper detail (boot-order tricks, recursion guards, per-coroutine isolation mechanism, source-line references): <a href="https://github.com/sibidharan/zealphp/blob/master/docs/apache-parity.md"><code>docs/apache-parity.md</code></a> and <a href="https://github.com/sibidharan/zealphp/blob/master/docs/error-handling.md"><code>docs/error-handling.md</code></a>.</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4. When migration is a good fit (and when it isn't)            -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:3rem">When migration is a good fit</h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1rem">
  <div class="qs-block" style="padding:1.25rem 1.5rem;border-color:var(--accent)">
    <h3 style="margin:0 0 .75rem;font-size:1rem;color:var(--accent)">Good fit</h3>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>✓ You're already on PHP and the team knows it</li>
      <li>✓ You want WebSocket / SSE / streaming without a separate Node service</li>
      <li>✓ You have I/O-bound endpoints (DB, HTTP fetches) — coroutines fan them out</li>
      <li>✓ You hit PHP-FPM bottlenecks (request rate, cold start latency, FPM pool tuning)</li>
      <li>✓ You want long-lived sessions or pub/sub without Redis</li>
      <li>✓ You want to keep <code>session_start()</code> + <code>header()</code> + <code>echo</code> — not rewrite for an event loop</li>
    </ul>
  </div>
  <div class="qs-block" style="padding:1.25rem 1.5rem">
    <h3 style="margin:0 0 .75rem;font-size:1rem">Probably wrong fit</h3>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>✗ Workload is purely CPU-bound — coroutines don't help, just buy more cores</li>
      <li>✗ App relies on extensions OpenSwoole's runtime hooks don't cover (rare, but exists)</li>
      <li>✗ You'd accept a full rewrite anyway — Go/Rust/Elixir give bigger ceilings if you can pay the cost</li>
      <li>✗ Hard requirement for shared-nothing per-request memory (PHP-FPM's strongest guarantee)</li>
      <li>✗ Production team can't accept alpha (v0.2.x) stability — wait for v1.0</li>
    </ul>
  </div>
</div>

</div>
</section>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4.5. Live config converter                                     -->
<!-- ────────────────────────────────────────────────────────────── -->

<section class="section">
<div class="container" style="max-width:960px">
<h2 class="section-title">Convert your existing config</h2>
<p class="section-desc">Paste your Apache <code>.htaccess</code> or nginx config — AI converts it to a working <code>app.php</code> in real-time. The same engine that bridges the migration ladder above.</p>

<div class="converter-split" style="display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; margin:1.5rem 0;">
  <div style="border-right:1px solid var(--border);">
    <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
      <span>Apache / nginx config</span>
      <select id="convert-preset" style="font-size:.75rem; padding:.2rem .4rem; border-radius:4px; border:1px solid var(--border); background:var(--bg);">
        <option value="">— paste your own —</option>
        <option value="wordpress">WordPress .htaccess</option>
        <option value="nginx-cms">nginx CMS</option>
        <option value="redirects">Redirect rules</option>
      </select>
    </div>
    <textarea id="convert-input" style="width:100%; min-height:280px; border:none; padding:.75rem; font-family:var(--font-mono); font-size:.82rem; background:var(--code-bg); color:var(--code-text); resize:vertical; outline:none;" placeholder="Paste your .htaccess or nginx server { } config here..."></textarea>
    <div style="padding:.5rem .75rem; background:var(--bg-alt); display:flex; align-items:center; gap:.5rem;">
      <button id="convert-btn" onclick="runConvert()" style="padding:.4rem 1.2rem; background:var(--accent); color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:.82rem; font-weight:600;">Convert →</button>
      <span id="convert-status" style="font-size:.75rem; color:var(--text-muted);"></span>
    </div>
  </div>
  <div>
    <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
      <span>ZealPHP app.php</span>
      <button onclick="copyOutput()" style="font-size:.72rem; padding:.15rem .5rem; border:1px solid var(--border); border-radius:4px; background:var(--bg); cursor:pointer; color:var(--text-muted);">Copy</button>
    </div>
    <pre id="convert-output" style="min-height:280px; padding:.75rem; margin:0; font-family:var(--font-mono); font-size:.82rem; background:var(--code-bg); color:var(--code-text); overflow:auto; white-space:pre-wrap;"><span style="color:var(--text-muted);">// Output will appear here...</span></pre>
    <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.72rem; color:var(--text-muted);">
      Rate limit: 5 conversions per 10 minutes · Powered by gpt-5.4-mini · <a href="https://github.com/sibidharan/zealphp/blob/master/examples/agents/config_converter.py" target="_blank">Source</a> · <a href="/legacy-apps">More on legacy apps →</a>
    </div>
  </div>
</div>

<style>
@media (max-width:768px) { .converter-split { grid-template-columns:1fr !important; } }
</style>

<script>
(function() {
  const PRESETS = {
    wordpress: `# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress`,
    'nginx-cms': `server {
    listen 80;
    server_name example.com;
    root /var/www/html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \\.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
    }
    location ~* \\.(css|js|png|jpg|gif|ico)$ {
        expires 30d;
    }
}`,
    redirects: `RewriteEngine On
RewriteRule ^old-page$ /new-page [R=301,L]
RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]
RewriteRule ^docs$ https://docs.example.com [R=301,L]`
  };

  document.getElementById('convert-preset').addEventListener('change', function() {
    if (this.value && PRESETS[this.value]) {
      document.getElementById('convert-input').value = PRESETS[this.value];
    }
  });

  window.runConvert = function() {
    const input = document.getElementById('convert-input').value.trim();
    const output = document.getElementById('convert-output');
    const status = document.getElementById('convert-status');
    const btn = document.getElementById('convert-btn');

    if (!input) { status.textContent = 'Paste a config first'; return; }

    btn.disabled = true;
    btn.textContent = 'Converting...';
    status.textContent = 'Streaming from gpt-5.4-mini...';
    output.textContent = '';

    fetch('/api/convert', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({config: input})
    }).then(response => {
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      function read() {
        reader.read().then(({done, value}) => {
          if (done) {
            btn.disabled = false;
            btn.textContent = 'Convert →';
            status.textContent = 'Done';
            return;
          }
          buffer += decoder.decode(value, {stream: true});
          const lines = buffer.split('\n');
          buffer = lines.pop();
          for (const line of lines) {
            if (line.startsWith('data: ')) {
              const text = line.slice(6);
              if (text === '[DONE]') continue;
              output.textContent += text + '\n';
            }
          }
          output.scrollTop = output.scrollHeight;
          read();
        });
      }
      read();
    }).catch(err => {
      output.textContent = '// Error: ' + err.message;
      btn.disabled = false;
      btn.textContent = 'Convert →';
      status.textContent = 'Failed';
    });
  };

  window.copyOutput = function() {
    const text = document.getElementById('convert-output').textContent;
    navigator.clipboard.writeText(text).then(() => {
      const btn = event.target;
      btn.textContent = 'Copied!';
      setTimeout(() => btn.textContent = 'Copy', 1500);
    });
  };
})();
</script>

</div>
</section>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 5. Closing CTAs                                                -->
<!-- ────────────────────────────────────────────────────────────── -->

<section class="section section-dark">
<div class="container" style="max-width:960px">

<div style="text-align:center">
  <a href="/getting-started" class="btn btn-primary">Start the migration →</a>
  <a href="/legacy-apps" class="btn btn-outline" style="margin-left:.5rem">Legacy apps (WordPress) →</a>
  <a href="/why-zealphp" class="btn btn-outline" style="margin-left:.5rem">Why ZealPHP →</a>
</div>

<p style="text-align:center;margin-top:1.5rem;color:#a8a29e;font-size:.85rem">
  Performance: <a href="/performance">117K req/s text · 106K JSON · 50K templated</a> at rung 4 (full coroutine mode).<br>
  WordPress + custom CMS migrations: see the <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank" rel="noopener">showcase repo</a>.
</p>

</div>
</section>
