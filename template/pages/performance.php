<?php use ZealPHP\App; ?>

<section class="section section-dark">
<div class="container perf-container">

<h1 class="section-title">Benchmarks</h1>
<p class="section-desc">Real machine, full methodology, every CSV linked. Reproduce yourself before quoting.</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 1. Headline numbers — same as homepage hero, but with context -->
<!-- ────────────────────────────────────────────────────────────── -->

<div class="bench-method perf-method">
  <strong>Setup</strong> &nbsp;|&nbsp;
  AMD Ryzen 9 7900X (12 cores) · 24 GB RAM · Ubuntu 22.04 (Docker) ·
  PHP 8.3.31 · OpenSwoole 26.2.0 · Node.js 24.11.1 ·
  <code class="perf-inline-code">ab -n 50000 -c 200 -k -l</code>
  · 4 workers, each runtime tested alone
</div>

<div class="bench">
  <div class="bench-stat"><div class="num">117k</div><div class="label">req/s text</div><div class="sub">avg 1.7 ms</div></div>
  <div class="bench-stat"><div class="num">106k</div><div class="label">req/s JSON</div><div class="sub">avg 1.9 ms</div></div>
  <div class="bench-stat"><div class="num">50k</div><div class="label">req/s template</div><div class="sub">avg 4.0 ms</div></div>
  <div class="bench-stat"><div class="num">0</div><div class="label">failures</div><div class="sub">/ 150k reqs</div></div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 2. The three surprises                                        -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="perf-h2">Three findings worth highlighting</h2>

<div class="perf-findings-grid">
  <div class="qs-block perf-finding">
    <h3 class="perf-finding-title">1. OpenSwoole's raw HTTP outperforms Node's</h3>
    <p class="perf-finding-lead">
      Before any framework or middleware loads — bare HTTP server, single handler returning text/JSON:
    </p>
    <table class="ztable perf-finding-table">
      <tr><th>Runtime</th><th class="perf-right">/raw/bench (text)</th><th class="perf-right">/json</th></tr>
      <tr><td>OpenSwoole raw</td><td class="perf-cell-accent">142,170 req/s</td><td class="perf-cell-accent">137,535 req/s</td></tr>
      <tr><td>Node.js raw <code>http</code></td><td class="perf-right">129,091 req/s</td><td class="perf-right">131,513 req/s</td></tr>
      <tr><td><strong>Delta</strong></td><td class="perf-cell-accent-plain"><strong>+10.1%</strong></td><td class="perf-cell-accent-plain"><strong>+4.6%</strong></td></tr>
    </table>
    <p class="perf-finding-note">
      Counter-intuitive for the "PHP is slow" prior. Both Node and OpenSwoole are C extensions to their language runtimes; their HTTP servers are head-to-head and OpenSwoole is fractionally faster on this workload.
    </p>
  </div>

  <div class="qs-block perf-finding">
    <h3 class="perf-finding-title">2. Framework efficiency: ZealPHP retains 82%, Express retains 15%</h3>
    <p class="perf-finding-lead">
      The same workload through a full framework with CORS + ETag + sessions + routing + middleware:
    </p>
    <table class="ztable perf-finding-table">
      <tr><th>Stack</th><th class="perf-right">Raw runtime</th><th class="perf-right">Full framework</th><th class="perf-right">Retention</th></tr>
      <tr><td>ZealPHP / OpenSwoole</td><td class="perf-right">142,170</td><td class="perf-right">116,851</td><td class="perf-cell-accent">82%</td></tr>
      <tr><td>Express / Node.js</td><td class="perf-right">129,091</td><td class="perf-right">19,994</td><td class="perf-right">15%</td></tr>
    </table>
    <p class="perf-finding-note">
      This is the actual answer to "why does ZealPHP beat Express by 5×". It's not raw speed; it's that each layer added by the framework costs ZealPHP much less throughput than the equivalent layer costs Express.
    </p>
  </div>

  <div class="qs-block perf-finding">
    <h3 class="perf-finding-title">3. PHP with full middleware reaches 91% of bare Node http</h3>
    <p class="perf-finding-lead">
      Compose findings #1 and #2 — ZealPHP runs on a faster runtime AND keeps more of that runtime under middleware load. Net result, "PHP with everything turned on" vs "Node with nothing":
    </p>
    <table class="ztable perf-finding-table">
      <tr><th>Comparison</th><th class="perf-right">Text</th><th class="perf-right">JSON</th></tr>
      <tr><td>ZealPHP full PSR-15</td><td class="perf-cell-accent">116,851</td><td class="perf-cell-accent">105,681</td></tr>
      <tr><td>Node.js raw <code>http</code> (no framework)</td><td class="perf-right">129,091</td><td class="perf-right">131,513</td></tr>
      <tr><td><strong>ZealPHP retains</strong></td><td class="perf-cell-accent-plain"><strong>91%</strong></td><td class="perf-cell-accent-plain"><strong>80%</strong></td></tr>
    </table>
    <p class="perf-finding-note">
      Honest framing: ZealPHP doesn't beat hand-rolled Node http. But it gets within 10–20% of it while serving a full PSR-15 middleware stack with sessions, ETag, and reflection-based routing — features bare Node http doesn't have.
    </p>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3. Head-to-head table                                         -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="perf-h2">Sequential head-to-head — same workload, every stack</h2>

<p class="perf-lead">
  Each runtime gets the full 12-core machine in isolation; we don't run them concurrently because that measures the scheduler instead of the framework. <code class="perf-inline-code-06">ab -n 50000 -c 200 -k -l</code>, warmed up first.
</p>

<table class="ztable">
  <tr>
    <th class="perf-th-left">Framework</th>
    <th class="perf-right">Raw text (/raw/bench)</th>
    <th class="perf-right">JSON (/json)</th>
    <th class="perf-right">Template (/bench/template)</th>
  </tr>
  <tr class="perf-row-tint">
    <td colspan="4" class="perf-section-label">Runtime (no framework, no middleware)</td>
  </tr>
  <tr>
    <td>OpenSwoole raw</td>
    <td class="perf-right">141,670</td>
    <td class="perf-right">137,535</td>
    <td class="perf-cell-muted">—</td>
  </tr>
  <tr>
    <td>Node.js raw <code>http</code></td>
    <td class="perf-right">129,091</td>
    <td class="perf-right">131,513</td>
    <td class="perf-cell-muted">—</td>
  </tr>
  <tr class="perf-row-tint">
    <td colspan="4" class="perf-section-label">Full framework (CORS + ETag + sessions + routing + templates)</td>
  </tr>
  <tr>
    <td class="perf-zeal-name">ZealPHP <span class="perf-zeal-sub">built-in PSR-15 stack</span></td>
    <td class="perf-cell-accent">116,851</td>
    <td class="perf-cell-accent">105,681</td>
    <td class="perf-cell-accent">49,863</td>
  </tr>
  <tr>
    <td>Express.js <span class="perf-express-sub">+ cors + etag + express-session + session-file-store + ejs + body-parser</span></td>
    <td class="perf-right">19,994</td>
    <td class="perf-right">21,741</td>
    <td class="perf-right">12,470 <span class="perf-express-sub">(EJS)</span></td>
  </tr>
  <tr class="perf-row-highlight">
    <td><strong>ZealPHP vs Express</strong></td>
    <td class="perf-cell-accent">+484% (5.8×)</td>
    <td class="perf-cell-accent">+386% (4.9×)</td>
    <td class="perf-cell-accent">+299% (4.0×)</td>
  </tr>
  <tr class="perf-row-tint">
    <td colspan="4" class="perf-section-label">Other PHP frameworks (community benchmarks, similar workload class)</td>
  </tr>
  <tr><td>Slim 4</td><td colspan="3" class="perf-cell-dim">~4,000 req/s</td></tr>
  <tr><td>Symfony 7</td><td colspan="3" class="perf-cell-dim">~2,000 req/s</td></tr>
  <tr><td>Laravel 11</td><td colspan="3" class="perf-cell-dim">~500 req/s</td></tr>
</table>

<p class="perf-totals">
  vs Laravel 11: <strong class="perf-accent-strong">~210×</strong> ·
  vs Symfony 7: <strong class="perf-accent-strong">~55×</strong> ·
  vs Slim 4: <strong class="perf-accent-strong">~28×</strong>
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3b. Legacy-file serving: Apache vs ZealPHP lifecycle modes      -->
<!-- SYNC: this table mirrors /vs-fpm#measured-four-ways. Any change -->
<!-- to the numbers must update BOTH in lock-step.                   -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="perf-h2">Legacy-file serving — Apache vs ZealPHP lifecycle modes</h2>

<p class="perf-lead">
  The numbers above are ZealPHP's <em>native-route</em> fast path. This one is different and matters when you're <strong>migrating an existing app</strong>: serving a plain <code>public/*.php</code> file (implicit routing) through each lifecycle mode, compared against Apache + mod_php. Same trivial <code>probe.php</code> (<code>echo "ok"</code>), 4 workers each, <code>ab -n 3000 -c 20</code>.
</p>

<table class="ztable">
  <tr>
    <th class="perf-th-left">Stack</th>
    <th class="perf-th-left">How the file runs</th>
    <th class="perf-right">req/s</th>
    <th class="perf-right">ms/req</th>
  </tr>
  <tr>
    <td>Apache + mod_php</td>
    <td>warm in-process interpreter</td>
    <td class="perf-cell-amber">40,861</td>
    <td class="perf-right">0.49</td>
  </tr>
  <tr class="perf-row-tint">
    <td>ZealPHP coroutine — default <small>(<code>App::mode(App::MODE_COROUTINE)</code>)</small></td>
    <td>in-process include, coroutine-per-req</td>
    <td class="perf-cell-accent">34,159</td>
    <td class="perf-right">0.59</td>
  </tr>
  <tr>
    <td>ZealPHP Mixed-mode <small>(<code>App::mode(App::MODE_MIXED)</code> / <code>processIsolation(false)</code>)</small></td>
    <td>in-process include, sequential</td>
    <td class="perf-cell-accent">21,964</td>
    <td class="perf-right">0.91</td>
  </tr>
  <tr class="perf-row-tint">
    <td>ZealPHP CGI pool — default <small>(<code>App::mode(App::MODE_LEGACY_CGI)</code> / <code>cgiMode('pool')</code>)</small></td>
    <td>pre-spawned subprocess pool, warm dispatch (~1–3 ms)</td>
    <td class="perf-cell-accent">—</td>
    <td class="perf-right">~1–3 ms</td>
  </tr>
  <tr>
    <td>ZealPHP legacy CGI — proc fallback <small>(<code>cgiMode('proc')</code>)</small></td>
    <td><code>proc_open</code> fresh PHP per req</td>
    <td class="perf-cell-danger">160</td>
    <td class="perf-cell-danger-plain">124.4</td>
  </tr>
</table>

<p class="perf-para-note">
  Intel i9-14900K · PHP 8.3 · 4 workers each · <code>ab -n 3000 -c 20</code> — same run as <a href="/vs-fpm#measured-four-ways" class="perf-link-accent">/vs-fpm</a>. Three honest takeaways: (1) the default CGI bridge is now the pre-spawned <code>cgiMode('pool')</code> (~1–3 ms warm) — the 160 req/s row is <code>cgiMode('proc')</code>, the explicit slow-fallback that cold-starts a fresh PHP process per request; turning process isolation off entirely (Mixed-mode) recovers ~137× on the same file; (2) <code>App::mode(App::MODE_LEGACY_CGI)</code> resolves to the warm pool by default — no extra config needed to avoid the proc_open cost; (3) Apache mod_php edges out ZealPHP on trivial legacy-file serving (a mature in-process C SAPI is hard to beat for no-I/O echo). ZealPHP's win is the native-route numbers above, coroutine I/O concurrency, WebSocket/SSE, and not needing a separate web server. Full analysis + the FPM architecture breakdown: <a href="/vs-fpm#measured-four-ways" class="perf-link-accent">/vs-fpm</a>.
</p>

<p class="perf-para-note">
  <strong>Not shown:</strong> <code>cgiMode('fcgi')</code> — the third of three dispatch modes (<code>pool</code> / <code>proc</code> / <code>fcgi</code>) — forwards each <code>public/*.php</code> file to an upstream php-fpm pool over FastCGI (nginx <code>fastcgi_pass</code> / Apache <code>mod_proxy_fcgi</code> parity). Performance ≈ whatever that pool delivers; we don't run PHP at all in this mode. Walkthrough: <a href="/legacy-apps#cgi-mode-fcgi" class="perf-link-accent">/legacy-apps#cgi-mode-fcgi</a>.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4. Concurrency sweep                                          -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="perf-h2">Concurrency sweep — ZealPHP solo across c = 1…1000</h2>

<p class="perf-lead">
  Same 4 workers, varying simultaneous connections. Shows where each endpoint saturates, how tail latency degrades, and whether throughput holds at heavy load.
</p>

<h3 class="perf-h3-spaced"><code>/raw/bench</code> — lean runtime, no demo middleware</h3>
<table class="ztable perf-sweep-table">
  <tr><th>c</th><th class="perf-right">req/s</th><th class="perf-right">avg ms</th><th class="perf-right">p90 ms</th><th class="perf-right">p99 ms</th><th class="perf-right">failures</th></tr>
  <tr><td>1</td><td class="perf-right">3,883</td><td class="perf-right">0.26</td><td class="perf-right">0</td><td class="perf-right">0</td><td class="perf-right">0</td></tr>
  <tr><td>10</td><td class="perf-right">30,501</td><td class="perf-right">0.33</td><td class="perf-right">0</td><td class="perf-right">1</td><td class="perf-right">0</td></tr>
  <tr><td>50</td><td class="perf-right">94,888</td><td class="perf-right">0.53</td><td class="perf-right">1</td><td class="perf-right">3</td><td class="perf-right">0</td></tr>
  <tr class="perf-row-sweep-peak"><td><strong>100</strong></td><td class="perf-cell-accent"><strong>110,964</strong></td><td class="perf-right">0.90</td><td class="perf-right">1</td><td class="perf-right">6</td><td class="perf-right">0</td></tr>
  <tr><td>200</td><td class="perf-right">102,156</td><td class="perf-right">1.96</td><td class="perf-right">3</td><td class="perf-right">9</td><td class="perf-right">0</td></tr>
  <tr><td>500</td><td class="perf-right">100,363</td><td class="perf-right">4.98</td><td class="perf-right">8</td><td class="perf-right">20</td><td class="perf-right">0</td></tr>
  <tr><td>1000</td><td class="perf-right">85,001</td><td class="perf-right">11.77</td><td class="perf-right">19</td><td class="perf-right">33</td><td class="perf-right">0</td></tr>
</table>

<h3 class="perf-h3-spaced"><code>/json</code> — full PSR-15 stack (CORS · ETag · Range · sessions · reflection-injected handler)</h3>
<table class="ztable perf-sweep-table">
  <tr><th>c</th><th class="perf-right">req/s</th><th class="perf-right">avg ms</th><th class="perf-right">p90 ms</th><th class="perf-right">p99 ms</th><th class="perf-right">failures</th></tr>
  <tr><td>1</td><td class="perf-right">4,173</td><td class="perf-right">0.24</td><td class="perf-right">0</td><td class="perf-right">0</td><td class="perf-right">0</td></tr>
  <tr><td>10</td><td class="perf-right">30,840</td><td class="perf-right">0.32</td><td class="perf-right">0</td><td class="perf-right">1</td><td class="perf-right">0</td></tr>
  <tr><td>50</td><td class="perf-right">105,868</td><td class="perf-right">0.47</td><td class="perf-right">1</td><td class="perf-right">4</td><td class="perf-right">0</td></tr>
  <tr class="perf-row-sweep-peak"><td><strong>100</strong></td><td class="perf-cell-accent"><strong>108,086</strong></td><td class="perf-right">0.93</td><td class="perf-right">1</td><td class="perf-right">6</td><td class="perf-right">0</td></tr>
  <tr><td>200</td><td class="perf-right">93,733</td><td class="perf-right">2.13</td><td class="perf-right">3</td><td class="perf-right">9</td><td class="perf-right">0</td></tr>
  <tr><td>500</td><td class="perf-right">95,526</td><td class="perf-right">5.23</td><td class="perf-right">8</td><td class="perf-right">19</td><td class="perf-right">0</td></tr>
  <tr><td>1000</td><td class="perf-right">77,761</td><td class="perf-right">12.86</td><td class="perf-right">19</td><td class="perf-right">81</td><td class="perf-right">0</td></tr>
</table>

<p class="perf-sweep-summary">
  Peak at c = 100, sustained well past it. Throughput holds within ~20% of peak at c = 1000 with zero failures — the framework degrades gracefully rather than falling over.<br>
  Low-concurrency throughput (c = 1, c = 10) is bounded by Docker localhost-network round-trip latency, not framework cost. Run on bare metal to see higher c = 1 numbers; the c ≥ 50 figures are unaffected.
</p>

<p class="perf-csv-links">
  Raw CSVs: <a href="https://github.com/sibidharan/zealphp/blob/master/bench/results/ryzen-sweep/raw-bench-ryzen-c1-1000.csv" target="_blank" rel="noopener">/raw/bench</a> ·
  <a href="https://github.com/sibidharan/zealphp/blob/master/bench/results/ryzen-sweep/json-ryzen-c1-1000.csv" target="_blank" rel="noopener">/json</a>
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 5. Reproduce yourself                                          -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="perf-h2">Reproduce on your own machine</h2>

<p class="perf-lead">
  Numbers are hardware- and OS-bound. Published figures are a starting point, not a contract. Three harnesses ship with the repo; pick the one that matches the claim you want to verify.
</p>

<h3 class="perf-h3-spaced">One-line install (Ubuntu/Debian)</h3>

<p class="perf-install-lead">
  Goes from a fresh box to a benched-ready clone — installs PHP 8.3, OpenSwoole, ext-zealphp, composer, wrk, ab, then clones <code>sibidharan/zealphp</code> to <code>~/zealphp</code> and runs <code>composer install</code>:
</p>

<?php App::render('/components/_code', [
    'label' => 'bench-install.sh — root-required, single command',
    'code'  => <<<'BASH'
curl -fsSL https://php.zeal.ninja/bench-install.sh | sudo bash
# Prints the next-step bench command when it finishes.
BASH
]); ?>

<p class="perf-install-note">
  Inspect before piping to <code>sudo</code>:
  <code>curl -fsSL https://php.zeal.ninja/bench-install.sh | less</code>
</p>

<h3 class="perf-h3-spaced-2">Manual install (macOS / inspect-friendly)</h3>

<?php App::render('/components/_code', [
    'label' => 'macOS (Homebrew)',
    'code'  => <<<'BASH'
brew install wrk php composer node
pecl install openswoole
cd ext/zealphp && phpize && ./configure && make && sudo make install
git clone https://github.com/sibidharan/zealphp && cd zealphp && composer install
BASH
]); ?>

<?php App::render('/components/_code', [
    'label' => 'Linux apt (one-liner equivalent, manually)',
    'code'  => <<<'BASH'
curl -fsSL https://php.zeal.ninja/install.sh | sudo bash   # PHP + openswoole + ext-zealphp + composer
sudo apt install -y wrk apache2-utils git
git clone https://github.com/sibidharan/zealphp && cd zealphp && composer install
BASH
]); ?>

<p class="perf-install-note">
  Verify extensions loaded: <code>php -m | grep -E 'openswoole|zealphp'</code>
</p>

<h3 class="perf-h3-spaced-2">Recipe 1 — single-stack concurrency sweep (matches the tables above)</h3>

<?php App::render('/components/_code', [
    'label' => 'scripts/bench.sh',
    'code'  => <<<'BASH'
scripts/bench.sh --tool ab --requests 50000 \
                 --workers 4 --threads 4 --task-workers 0 \
                 --paths /raw/bench,/json --p1000
# Output: bench/results/zealphp-<timestamp>.csv + per-c raw logs
BASH
]); ?>

<h3 class="perf-h3-spaced">Recipe 2 — ZealPHP vs raw Node (matches the head-to-head table)</h3>

<?php App::render('/components/_code', [
    'label' => 'scripts/bench_compare.sh',
    'code'  => <<<'BASH'
scripts/bench_compare.sh --workers 4 --threads 4 --p1000 --duration 30s
# Or via Docker so versions don't matter:
mkdir -p bench/results && docker compose run --rm --build compare
BASH
]); ?>

<h3 class="perf-h3-spaced">Recipe 3 — 3-way with sample-to-sample variance (autocannon)</h3>

<p class="perf-recipe-note">
  A single 30s run can hide 10–15% per-sample swings on noisy hardware. This harness runs 10 short samples per stack spread over time and reports mean ± stddev so you can see how stable each stack is.
</p>

<?php App::render('/components/_code', [
    'label' => 'bench/compare-3way/run.sh',
    'code'  => <<<'BASH'
cd /tmp && npm install autocannon express   # one-off
./bench/compare-3way/run.sh                 # ~10 min
BASH
]); ?>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 6. Methodology and caveats                                     -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="perf-h2">Methodology</h2>

<table class="ztable">
  <tr><th>Field</th><th>Value</th></tr>
  <tr><td>Machine</td><td>AMD Ryzen 9 7900X · 12 cores · 24 GB RAM</td></tr>
  <tr><td>OS</td><td>Ubuntu 22.04.4 LTS</td></tr>
  <tr><td>Runtime</td><td>Docker container · native Linux · near-zero virtualization overhead</td></tr>
  <tr><td>PHP</td><td>8.3.31 (cli, NTS)</td></tr>
  <tr><td>OpenSwoole</td><td>26.2.0</td></tr>
  <tr><td>Node.js</td><td>24.11.1</td></tr>
  <tr><td>Benchmark tool</td><td>ApacheBench 2.3 (<code>ab -n 50000 -c &lt;c&gt; -k -l</code>)</td></tr>
  <tr><td>HTTP workers</td><td>4 (deliberate — keeps the result comparable to typical mid-tier app server sizing)</td></tr>
  <tr><td>Task workers</td><td>0</td></tr>
  <tr><td>Warmup</td><td>5s per path/runtime before measurement</td></tr>
  <tr><td>Sample size</td><td>50,000 requests per concurrency level</td></tr>
  <tr><td>Sweep</td><td>c = 1, 10, 50, 100, 200, 500, 1000</td></tr>
  <tr><td>Method</td><td>Each runtime tested <strong>alone</strong> with full machine resources — never simultaneously</td></tr>
</table>

<h3 class="perf-h3-spaced-2">Endpoints under test</h3>

<table class="ztable">
  <tr><th>Path</th><th>Returns</th><th>What it exercises</th></tr>
  <tr><td><code>/raw/bench</code></td><td>plain text (~20 bytes)</td><td>Bare framework dispatch path with no demo middleware. Routing only.</td></tr>
  <tr><td><code>/json</code></td><td>JSON of <code>G::instance()-&gt;session</code></td><td>Full PSR-15 stack — CORS · ETag · Range · Compression · coroutine-safe sessions · reflection-injected handler · auto-JSON.</td></tr>
  <tr><td><code>/bench/template</code></td><td>~6 KB HTML</td><td>Same as <code>/json</code> + template rendering with <code>App::render()</code>.</td></tr>
</table>

<h2 class="perf-h2">Caveats — read before quoting</h2>

<ul class="perf-caveats">
  <li><strong>Single-machine numbers.</strong> Your hardware, OS limits, payload size, and middleware set will move these. Quote your own measurements.</li>
  <li><strong>Docker localhost RTT.</strong> c = 1 and c = 10 throughput is bounded by Docker's localhost networking overhead, not framework cost. Bare-metal runs typically post c = 1 closer to 15k-20k req/s.</li>
  <li><strong>4 workers ≈ 4 cores.</strong> Deliberate baseline. The framework is multi-process; doubling workers on a wider machine scales further until you saturate I/O or coroutine context-switching.</li>
  <li><strong>Express comparison is fair.</strong> Express runs with cors + etag + express-session + session-file-store + ejs + body-parser — middleware roughly equivalent to ZealPHP's built-in PSR-15 stack. We're not comparing bare Express to full-stack ZealPHP.</li>
  <li><strong>"Other PHP frameworks" numbers are community benchmarks</strong>, not measured on this box. They're rough orders of magnitude; we don't claim 1.0% precision.</li>
</ul>

<p class="perf-source">
  Source: <a href="https://github.com/sibidharan/zealphp/blob/master/PERF.md" target="_blank" rel="noopener">PERF.md</a> ·
  Raw CSVs: <a href="https://github.com/sibidharan/zealphp/tree/master/bench/results/ryzen-sweep" target="_blank" rel="noopener">bench/results/ryzen-sweep/</a> ·
  Scripts: <a href="https://github.com/sibidharan/zealphp/tree/master/scripts" target="_blank" rel="noopener">scripts/</a> · <a href="https://github.com/sibidharan/zealphp/tree/master/bench/compare-3way" target="_blank" rel="noopener">bench/compare-3way/</a>
</p>

</div>
</section>
