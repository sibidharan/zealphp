<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <h1>ZealPHP <span>on OpenSwoole</span></h1>
    <p>An async PHP framework tuned for coroutine I/O, SSR streaming, WebSocket, and low-latency services.<br>
       Runs <strong>WordPress out of the box</strong> — zero modifications required.</p>
    <div class="cta">
      <a href="/getting-started" class="btn btn-primary">Get Started →</a>
      <a href="https://github.com/sibidharan/zealphp" class="btn btn-outline" target="_blank">GitHub ↗</a>
    </div>
    <div class="oss-badges" aria-label="Project badges">
      <a href="https://deepwiki.com/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://deepwiki.com/badge.svg" alt="Ask DeepWiki">
      </a>
      <a href="https://packagist.org/packages/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/packagist/v/sibidharan/zealphp?style=flat-square" alt="Packagist latest version">
      </a>
      <a href="https://packagist.org/packages/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/packagist/dt/sibidharan/zealphp?style=flat-square" alt="Packagist downloads">
      </a>
      <a href="https://packagist.org/packages/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/packagist/l/sibidharan/zealphp?style=flat-square" alt="MIT license">
      </a>
      <a href="https://github.com/sibidharan/zealphp/stargazers" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/github/stars/sibidharan/zealphp?style=flat-square&logo=github&logoColor=white" alt="GitHub stars">
      </a>
      <a href="https://www.php.net/" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3 or newer">
      </a>
      <a href="https://github.com/sibidharan/zealphp/actions/workflows/tests.yml" target="_blank" rel="noopener noreferrer">
        <img src="https://github.com/sibidharan/zealphp/actions/workflows/tests.yml/badge.svg" alt="GitHub Actions test status">
      </a>
      <a href="https://codecov.io/gh/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://codecov.io/gh/sibidharan/zealphp/branch/master/graph/badge.svg" alt="Coverage">
      </a>
    </div>
    <div class="bench-note">Local benchmark on 4 workers</div>
    <div class="bench">
      <div class="bench-stat"><div class="num">4</div><div class="label">workers</div></div>
      <div class="bench-stat"><div class="num">67k</div><div class="label">req/s</div></div>
      <div class="bench-stat"><div class="num">21ms</div><div class="label">p90 latency</div></div>
      <div class="bench-stat"><div class="num">0</div><div class="label">failures</div></div>
    </div>
  </div>
</section>

<!-- Quick start -->
<section class="section" style="background:var(--bg-dark);color:#e2e8f0">
  <div class="container">
    <h2 style="color:#fff;margin-bottom:.5rem">Quick Start</h2>
    <p style="color:#94a3b8;margin-bottom:1.5rem">Pick the starter-project path or run the framework repo directly.</p>
    <div class="quickstart">
      <div><span class="comment"># Starter project</span></div>
      <div><span class="cmd">composer create-project sibidharan/zealphp-project:^0.1.1 ~/zealphp-project</span></div>
      <div><span class="cmd">cd ~/zealphp-project</span></div>
      <div><span class="cmd">php app.php</span></div>
      <div><span class="comment"># → <?= htmlspecialchars($siteUrl) ?></span></div>
    </div>
    <div class="quickstart">
      <div><span class="comment"># Framework repo</span></div>
      <div><span class="cmd">git clone https://github.com/sibidharan/zealphp.git ~/zealphp</span></div>
      <div><span class="cmd">cd ~/zealphp</span></div>
      <div><span class="cmd">php app.php</span></div>
      <div><span class="comment"># → <?= htmlspecialchars($siteUrl) ?></span></div>
    </div>
  </div>
</section>

<!-- Feature grid -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Everything you need</h2>
    <p class="section-desc">Every feature is a live running example — click any card to explore.</p>
    <div class="feature-grid">
      <?php
      $features = [
        ['⚡', 'Routing',    'Flask-style routes with URL params, namespaces, and regex patterns. Reflection-based parameter injection.', '/routing',    'route(), nsRoute()'],
        ['📦', 'Responses',  'Return int → status, array → JSON, string → HTML, Generator → stream. Plus redirect(), sse(), cookie().', '/responses',  'HTTP\Response'],
        ['🔀', 'Coroutines', 'go() + Channel for parallel async IO. Thousands of concurrent requests on a single worker.', '/coroutines', 'OpenSwoole'],
        ['📡', 'Streaming',  'SSR streaming via Generator yield, stream() callback, and Server-Sent Events.', '/streaming',  'SSR · SSE'],
        ['🔌', 'WebSocket',  'Real-time bi-directional with rooms, auth, binary frames, and heartbeat.', '/ws',          'App::ws()'],
        ['🛡️', 'Middleware', 'CORS, ETag/304, and custom PSR-15 middleware in any order.', '/middleware', 'PSR-15'],
        ['🗄️', 'Sessions',  'Coroutine-safe sessions replacing all native session_*() functions via uopz.', '/sessions',   'uopz hooks'],
        ['🗃️', 'Store',     'Cross-worker shared memory via OpenSwoole\\Table. Lock-free atomic counters.', '/store',      'Store · Counter'],
        ['⏱️', 'Timers',    'App::tick/after for recurring tasks. Per-worker via onWorkerStart.', '/timers',      'Timer'],
        ['🌐', 'HTTP',      'HEAD, OPTIONS, 301/307 redirects, CORS, ETag, gzip — full HTTP/1.1.', '/http',       'HTTP/1.1'],
        ['🔗', 'ZealAPI',   'File-based REST endpoints. Drop a PHP file in api/ and it becomes a route.', '/api',        'implicit routes'],
        ['🏗️', 'Legacy Apps', 'Run WordPress, Drupal, or any PHP app unmodified. CGI worker provides true global scope isolation.', '/legacy-apps', 'WordPress'],
      ];
      foreach ($features as [$icon, $title, $body, $href, $badge]) {
        App::render('/components/_card', compact('icon', 'title', 'body', 'href', 'badge'));
      }
      ?>
    </div>
  </div>
</section>

<!-- Return conventions -->
<section class="section" style="background:var(--bg-alt)">
  <div class="container">
    <h2 class="section-title">Return anything, get the right response</h2>
    <p class="section-desc">ZealPHP inspects your return type and does the right thing — no boilerplate.</p>
    <table class="ztable" style="margin-top:1.5rem">
      <tr><th style="width:30%">Return</th><th style="width:35%">Result</th><th>Example</th></tr>
      <tr>
        <td><code>int</code></td>
        <td>HTTP status code</td>
        <td><code>return 404;</code> <code>return 201;</code></td>
      </tr>
      <tr>
        <td><code>array</code> / <code>object</code></td>
        <td>Auto-serialized as JSON</td>
        <td><code>return ['users' => $list];</code></td>
      </tr>
      <tr>
        <td><code>string</code></td>
        <td>HTML body</td>
        <td><code>return '&lt;h1&gt;Hello&lt;/h1&gt;';</code></td>
      </tr>
      <tr>
        <td><code>Generator</code></td>
        <td>SSR streaming (each yield sent immediately)</td>
        <td><code>yield '&lt;head&gt;'; yield $body;</code></td>
      </tr>
      <tr>
        <td><code>void</code> + <code>echo</code></td>
        <td>Buffered output via <code>ob_get_clean()</code></td>
        <td><code>echo "Hello"; echo " World";</code></td>
      </tr>
      <tr>
        <td><code>ResponseInterface</code></td>
        <td>PSR-7 response used directly</td>
        <td><code>return new Response(...);</code></td>
      </tr>
    </table>
  </div>
</section>

<!-- Live converter -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Try it — convert your config to ZealPHP</h2>
    <p class="section-desc">Paste Apache <code>.htaccess</code> or nginx config. AI converts it to <code>app.php</code> in real-time.</p>
    <div class="converter-split" style="display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; margin-top:1.5rem;">
      <div style="border-right:1px solid var(--border); display:flex; flex-direction:column;">
        <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
          <span>Input</span>
          <select id="hp-preset" style="font-size:.75rem; padding:.2rem .4rem; border-radius:4px; border:1px solid var(--border); background:var(--bg);">
            <option value="wordpress">WordPress .htaccess</option>
            <option value="nginx-cms">nginx CMS</option>
            <option value="redirects">Redirect rules</option>
            <option value="">— paste your own —</option>
          </select>
        </div>
        <textarea id="hp-input" style="flex:1; min-height:220px; border:none; padding:.75rem; font-family:var(--font-mono); font-size:.8rem; background:var(--code-bg); color:var(--code-text); resize:none; outline:none;"></textarea>
        <div style="padding:.4rem .75rem; background:var(--bg-alt); display:flex; align-items:center; gap:.5rem;">
          <button id="hp-btn" onclick="hpConvert()" style="padding:.35rem 1rem; background:var(--accent); color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:.8rem; font-weight:600;">Convert →</button>
          <span id="hp-status" style="font-size:.73rem; color:var(--text-muted);"></span>
        </div>
      </div>
      <div style="display:flex; flex-direction:column;">
        <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted);">ZealPHP app.php</div>
        <pre id="hp-output" style="flex:1; min-height:220px; max-height:320px; overflow:auto; padding:.75rem; margin:0; font-family:var(--font-mono); font-size:.8rem; background:var(--code-bg); color:var(--code-text); white-space:pre-wrap;"><span style="color:var(--text-muted);">// Click Convert to generate...</span></pre>
        <div style="padding:.4rem .75rem; background:var(--bg-alt); font-size:.7rem; color:var(--text-muted);">Powered by gpt-4.1-mini · Cached for 1hr · <a href="/legacy-apps">Full docs →</a></div>
      </div>
    </div>
  </div>
</section>

<script>
(function(){
  const HP = {
    wordpress: `# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress`,
    'nginx-cms': `server {\n    listen 80;\n    server_name example.com;\n    root /var/www/html;\n\n    location / {\n        try_files $uri $uri/ /index.php?$args;\n    }\n    location ~ \\.php$ {\n        fastcgi_pass unix:/run/php/php-fpm.sock;\n    }\n    location ~* \\.(css|js|png)$ {\n        expires 30d;\n    }\n}`,
    redirects: `RewriteEngine On\nRewriteRule ^old-page$ /new-page [R=301,L]\nRewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]\nRewriteRule ^docs$ https://docs.example.com [R=301,L]`
  };

  const presetEl = document.getElementById('hp-preset');
  const inputEl = document.getElementById('hp-input');
  presetEl.addEventListener('change', function() {
    if (this.value && HP[this.value]) inputEl.value = HP[this.value];
    else inputEl.value = '';
  });
  if (presetEl.value && HP[presetEl.value]) inputEl.value = HP[presetEl.value];

  window.hpConvert = function() {
    const input = inputEl.value.trim();
    const output = document.getElementById('hp-output');
    const status = document.getElementById('hp-status');
    const btn = document.getElementById('hp-btn');
    if (!input) { status.textContent = 'Paste a config first'; return; }
    btn.disabled = true; btn.textContent = 'Converting...';
    status.textContent = 'Streaming...'; output.textContent = '';
    fetch('/api/convert', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({config: input})
    }).then(r => {
      const reader = r.body.getReader(), dec = new TextDecoder();
      let buf = '';
      function read() {
        reader.read().then(({done, value}) => {
          if (done) { btn.disabled = false; btn.textContent = 'Convert →'; status.textContent = 'Done'; return; }
          buf += dec.decode(value, {stream: true});
          const lines = buf.split('\n'); buf = lines.pop();
          for (const l of lines) {
            if (l.startsWith('data: ') && !l.includes('[DONE]')) output.textContent += l.slice(6) + '\n';
          }
          output.scrollTop = output.scrollHeight;
          read();
        });
      }
      read();
    }).catch(e => {
      output.textContent = '// Error: ' + e.message;
      btn.disabled = false; btn.textContent = 'Convert →'; status.textContent = 'Failed';
    });
  };
})();
</script>

<!-- Why ZealPHP -->
<section class="section" style="background:var(--bg-alt)">
  <div class="container">
    <h2 class="section-title">Why ZealPHP?</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.5rem;margin-top:1.5rem">
      <?php
      $why = [
        ['🚀', 'No blocking',        'Every I/O call — file, DB, HTTP — yields to the event loop. OpenSwoole HOOK_ALL makes existing PHP libraries async automatically.'],
        ['🧵', 'True coroutines',    'Not fake async with callbacks. Real coroutines with go() + Channel. Write synchronous code that runs concurrently.'],
        ['🔧', 'PHP you already know','Superglobals, sessions, headers — all work via uopz overrides. Migrate existing apps without rewriting everything.'],
        ['📐', 'PSR standards',      'PSR-7 request/response, PSR-15 middleware. Drop in any PSR-15 middleware package.'],
        ['📊', 'Benchmark',          'Local quad-core /raw/bench sweep at c=1000 in bench mode: ZealPHP sustained 67k req/s, 21ms p90, and 0 failures on 4 workers.'],
        ['🔓', 'Open source',        'MIT licensed. Maintained by the community. Built on OpenSwoole, one of PHP\'s most battle-tested async runtimes.'],
      ];
      foreach ($why as [$icon, $title, $body]):
      ?>
      <div class="card">
        <div class="card-icon"><?= $icon ?></div>
        <h3><?= $title ?></h3>
        <p><?= $body ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
