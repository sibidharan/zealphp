<?php use ZealPHP\App; ?>

<section class="section" style="background:var(--bg-dark);color:var(--code-text)">
  <div class="container" style="max-width:860px">
    <h1 class="section-title" style="font-size:2rem;margin-bottom:.5rem;color:#fff">Why ZealPHP?</h1>
    <p class="section-desc" style="font-size:1.1rem;max-width:700px;color:var(--text-light)">
      PHP powers 77% of the web. Its execution model is what needs upgrading.<br>
      ZealPHP brings coroutine-native, real-time server architecture to PHP.
    </p>

    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:1rem;color:#fff">The problem</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        PHP's traditional request-per-process model (PHP-FPM, mod_php) is fundamentally
        incompatible with real-time, high-concurrency, and streaming use cases.
        Every request starts from scratch — no shared state, no persistent connections,
        no coroutines. Building a WebSocket server, streaming AI responses, or running
        background tasks requires leaving PHP entirely for Node.js, Go, or Python.
      </p>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem;margin-top:.75rem">
        Existing async PHP solutions are either too low-level (raw Swoole, ReactPHP, AMPHP),
        framework-locked (Laravel Octane), or not native PHP (FrankenPHP, RoadRunner).
        None provide a full-stack, coroutine-native framework with a migration path for existing PHP apps.
      </p>
    </div>

    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:1rem;color:#fff">ZealPHP's approach</h2>
      <div style="display:grid;gap:1rem">
        <div style="background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1.25rem">
          <h3 style="color:var(--accent);font-size:.95rem;margin-bottom:.5rem">Coroutine-native, not event-loop</h3>
          <p style="color:var(--text-light);font-size:.88rem;line-height:1.6;margin:0">
            Write synchronous-looking code. Under the hood, every I/O call (file, curl, PDO, sleep)
            yields the event loop via OpenSwoole's coroutine hooks. Thousands of concurrent requests
            per worker, zero callback hell.
          </p>
        </div>
        <div style="background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1.25rem">
          <h3 style="color:var(--accent);font-size:.95rem;margin-bottom:.5rem">Full-stack framework, not a library</h3>
          <p style="color:var(--text-light);font-size:.88rem;line-height:1.6;margin:0">
            Routing, PSR-15 middleware, templating with streaming, WebSocket, SSE, shared memory,
            task workers, sessions — all integrated. Write <code>$app->route()</code> and ship.
            Not 12 packages wired together.
          </p>
        </div>
        <div style="background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1.25rem">
          <h3 style="color:var(--accent);font-size:.95rem;margin-bottom:.5rem">Legacy PHP bridge — LAMP-style file routing</h3>
          <p style="color:var(--text-light);font-size:.88rem;line-height:1.6;margin:0">
            Drop <code>.php</code> files in <code>public/</code> and they route automatically — just like Apache.
            <code>session_start()</code>, <code>header()</code>, <code>$_GET</code>, <code>echo</code>
            all work unchanged via uopz. Drop files in <code>api/</code> and they become REST endpoints.
            WordPress runs unmodified through the CGI worker. Migrate at your own pace — file by file, feature by feature.
          </p>
        </div>
        <div style="background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1.25rem">
          <h3 style="color:var(--accent);font-size:.95rem;margin-bottom:.5rem">Single-process deployment</h3>
          <p style="color:var(--text-light);font-size:.88rem;line-height:1.6;margin:0">
            HTTP server, WebSocket server, task workers, timers, shared memory, sessions —
            all in one <code>php app.php</code>. No Nginx, no Redis, no Supervisor, no cron.
            Deploy a systemd service and you're done.
          </p>
        </div>
      </div>
    </div>

    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:1.25rem;color:#fff">Competitive landscape</h2>
      <p style="color:var(--text-light);font-size:.88rem;margin-bottom:1.25rem">
        Every project below serves a different need. This comparison is about where ZealPHP fits — not about which is "best."
      </p>
      <div style="overflow-x:auto">
        <table class="ztable" style="font-size:.82rem;min-width:700px">
          <tr>
            <th style="background:var(--code-bg);color:var(--code-text);border-bottom:2px solid rgba(255,255,255,.15)">Project</th>
            <th style="background:var(--code-bg);color:var(--code-text);border-bottom:2px solid rgba(255,255,255,.15)">Model</th>
            <th style="background:var(--code-bg);color:var(--code-text);border-bottom:2px solid rgba(255,255,255,.15)">Routing</th>
            <th style="background:var(--code-bg);color:var(--code-text);border-bottom:2px solid rgba(255,255,255,.15)">WebSocket</th>
            <th style="background:var(--code-bg);color:var(--code-text);border-bottom:2px solid rgba(255,255,255,.15)">Streaming</th>
            <th style="background:var(--code-bg);color:var(--code-text);border-bottom:2px solid rgba(255,255,255,.15)">Shared Memory</th>
            <th style="background:var(--code-bg);color:var(--code-text);border-bottom:2px solid rgba(255,255,255,.15)">Legacy PHP</th>
          </tr>
          <tr style="background:rgba(245,158,11,.08)">
            <td style="color:var(--accent);font-weight:700">ZealPHP</td>
            <td>Coroutine</td>
            <td>Built-in</td>
            <td>Built-in</td>
            <td>yield / SSE / stream()</td>
            <td>Store + Counter</td>
            <td>CGI worker</td>
          </tr>
          <tr>
            <td>ReactPHP</td>
            <td>Event loop</td>
            <td>Manual</td>
            <td>Via packages</td>
            <td>Manual</td>
            <td>No</td>
            <td>No</td>
          </tr>
          <tr>
            <td>AMPHP</td>
            <td>Fiber</td>
            <td>Manual</td>
            <td>Via packages</td>
            <td>Manual</td>
            <td>No</td>
            <td>No</td>
          </tr>
          <tr>
            <td>FrankenPHP</td>
            <td>Go worker</td>
            <td>Via framework</td>
            <td>Via framework</td>
            <td>Via framework</td>
            <td>No</td>
            <td>Partial</td>
          </tr>
          <tr>
            <td>RoadRunner</td>
            <td>Go worker</td>
            <td>Via framework</td>
            <td>Go plugin</td>
            <td>Via framework</td>
            <td>No</td>
            <td>No</td>
          </tr>
          <tr>
            <td>Laravel Octane</td>
            <td>Swoole/RR</td>
            <td>Laravel</td>
            <td>Via packages</td>
            <td>Limited</td>
            <td>Limited</td>
            <td>No</td>
          </tr>
          <tr>
            <td>Raw Swoole</td>
            <td>Coroutine</td>
            <td>Manual</td>
            <td>Manual</td>
            <td>Manual</td>
            <td>Table / Atomic</td>
            <td>No</td>
          </tr>
        </table>
      </div>
    </div>

    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:1rem;color:#fff">The migration ladder</h2>
      <p style="color:var(--text-light);font-size:.88rem;line-height:1.6;margin-bottom:1rem">
        You don't have to learn a framework to start. Drop files in a folder. Upgrade when you need to.
      </p>
      <div style="display:grid;gap:.5rem;margin-bottom:2rem">
        <div style="display:flex;gap:.75rem;align-items:baseline;font-size:.88rem">
          <span style="color:var(--accent);font-weight:700;min-width:1.5rem">0.</span>
          <span style="color:var(--text-light)"><code>setFallback()</code> — your entire existing app runs unchanged on OpenSwoole</span>
        </div>
        <div style="display:flex;gap:.75rem;align-items:baseline;font-size:.88rem">
          <span style="color:var(--accent);font-weight:700;min-width:1.5rem">1.</span>
          <span style="color:var(--text-light)"><code>public/*.php</code> — LAMP-style file routing. <code>$_GET</code>, <code>session_start()</code>, <code>echo</code> just work</span>
        </div>
        <div style="display:flex;gap:.75rem;align-items:baseline;font-size:.88rem">
          <span style="color:var(--accent);font-weight:700;min-width:1.5rem">2.</span>
          <span style="color:var(--text-light)"><code>api/*.php</code> — drop a file, get a REST endpoint. ZealAPI auto-routes by filename</span>
        </div>
        <div style="display:flex;gap:.75rem;align-items:baseline;font-size:.88rem">
          <span style="color:var(--accent);font-weight:700;min-width:1.5rem">3.</span>
          <span style="color:var(--text-light)"><code>$app->route()</code> — WebSocket, SSE, streaming when you're ready</span>
        </div>
        <div style="display:flex;gap:.75rem;align-items:baseline;font-size:.88rem">
          <span style="color:var(--accent);font-weight:700;min-width:1.5rem">4.</span>
          <span style="color:#fff;font-weight:600"><code>superglobals(false)</code> — full coroutine mode, thousands of concurrent requests</span>
        </div>
      </div>
    </div>

    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:1rem;color:#fff">When to use ZealPHP</h2>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
        <div>
          <h3 style="color:var(--success);font-size:.9rem;margin-bottom:.75rem">Good fit</h3>
          <ul style="list-style:none;padding:0;margin:0;font-size:.85rem;color:var(--text-light)">
            <li style="padding:.3rem 0">AI/LLM apps with streaming responses</li>
            <li style="padding:.3rem 0">Real-time dashboards and live updates</li>
            <li style="padding:.3rem 0">WebSocket apps (chat, collaboration)</li>
            <li style="padding:.3rem 0">High-concurrency APIs (10k+ req/s)</li>
            <li style="padding:.3rem 0">Migrating large PHP codebases to async</li>
            <li style="padding:.3rem 0">LAMP-style PHP devs who want async without learning a framework</li>
            <li style="padding:.3rem 0">Single-process deployments (no infra complexity)</li>
          </ul>
        </div>
        <div>
          <h3 style="color:var(--danger);font-size:.9rem;margin-bottom:.75rem">Not the right fit</h3>
          <ul style="list-style:none;padding:0;margin:0;font-size:.85rem;color:var(--text-light)">
            <li style="padding:.3rem 0">Already invested in Laravel ecosystem</li>
            <li style="padding:.3rem 0">Need shared hosting (requires CLI access)</li>
            <li style="padding:.3rem 0">Building a custom protocol server</li>
            <li style="padding:.3rem 0">Want Fiber-based async (no ext dependency)</li>
          </ul>
        </div>
      </div>
    </div>

    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:1rem;color:#fff">Benchmarks</h2>
      <p style="color:var(--text-light);font-size:.88rem;line-height:1.6">
        All benchmarks run with full middleware stack (CORS + ETag + sessions + PSR-7 routing),
        4 workers, <code>ab -n 50000 -c 200 -k</code>. Same machine, same conditions.
      </p>
      <div class="bench" style="margin-top:1rem">
        <div class="bench-stat"><div class="num">95k</div><div class="label">req/s text</div></div>
        <div class="bench-stat"><div class="num">90k</div><div class="label">req/s JSON</div></div>
        <div class="bench-stat"><div class="num">65k</div><div class="label">req/s template</div></div>
        <div class="bench-stat"><div class="num">0</div><div class="label">failures</div></div>
      </div>
      <p style="color:var(--text-muted);font-size:.82rem;margin-top:1rem">
        Don't trust our numbers — run it yourself:
        <code style="background:var(--code-bg);padding:.2rem .5rem;border-radius:4px;color:var(--text-light)">scripts/bench_vs_express.sh</code>
      </p>
    </div>

    <div style="margin-top:3rem;text-align:center;padding:2rem 0;border-top:1px solid var(--border-dark)">
      <h2 style="font-size:1.2rem;margin-bottom:.5rem;color:#fff">Ready to try it?</h2>
      <p style="color:var(--text-light);margin-bottom:1.25rem">From zero to running server in 60 seconds.</p>
      <a href="/getting-started" class="btn btn-primary" style="font-size:1rem;padding:.75rem 2rem">Get started →</a>
    </div>
  </div>
</section>
