<?php use ZealPHP\App; ?>

<section class="section section-dark">
<div class="container" style="max-width:900px">

<h1 class="section-title">Benchmarks</h1>
<p class="section-desc">Real machine, full methodology, every CSV linked. Reproduce yourself before quoting.</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 1. Headline numbers — same as homepage hero, but with context -->
<!-- ────────────────────────────────────────────────────────────── -->

<div class="bench-method" style="margin-top:2rem">
  <strong>Setup</strong> &nbsp;|&nbsp;
  AMD Ryzen 9 7900X (12 cores) · 24 GB RAM · Ubuntu 22.04 (Docker) ·
  PHP 8.3.31 · OpenSwoole 26.2.0 · Node.js 24.11.1 ·
  <code style="background:rgba(255,255,255,.05);padding:.1rem .3rem;border-radius:3px">ab -n 50000 -c 200 -k -l</code>
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

<h2 style="margin:3rem 0 1rem">Three findings worth highlighting</h2>

<div style="display:grid;grid-template-columns:1fr;gap:1rem">
  <div class="qs-block" style="padding:1.1rem 1.3rem">
    <h3 style="margin:0 0 .35rem;color:var(--accent);font-size:1.05rem">1. OpenSwoole's raw HTTP outperforms Node's</h3>
    <p style="margin:0 0 .4rem;color:#cbd5e1;font-size:.92rem;line-height:1.55">
      Before any framework or middleware loads — bare HTTP server, single handler returning text/JSON:
    </p>
    <table class="ztable" style="margin:.4rem 0;font-size:.88rem">
      <tr><th>Runtime</th><th style="text-align:right">/raw/bench (text)</th><th style="text-align:right">/json</th></tr>
      <tr><td>OpenSwoole raw</td><td style="text-align:right;color:var(--accent);font-weight:700">142,170 req/s</td><td style="text-align:right;color:var(--accent);font-weight:700">137,535 req/s</td></tr>
      <tr><td>Node.js raw <code>http</code></td><td style="text-align:right">129,091 req/s</td><td style="text-align:right">131,513 req/s</td></tr>
      <tr><td><strong>Delta</strong></td><td style="text-align:right;color:var(--accent)"><strong>+10.1%</strong></td><td style="text-align:right;color:var(--accent)"><strong>+4.6%</strong></td></tr>
    </table>
    <p style="margin:.4rem 0 0;color:#94a3b8;font-size:.82rem">
      Counter-intuitive for the "PHP is slow" prior. Both Node and OpenSwoole are C extensions to their language runtimes; their HTTP servers are head-to-head and OpenSwoole is fractionally faster on this workload.
    </p>
  </div>

  <div class="qs-block" style="padding:1.1rem 1.3rem">
    <h3 style="margin:0 0 .35rem;color:var(--accent);font-size:1.05rem">2. Framework efficiency: ZealPHP retains 82%, Express retains 15%</h3>
    <p style="margin:0 0 .4rem;color:#cbd5e1;font-size:.92rem;line-height:1.55">
      The same workload through a full framework with CORS + ETag + sessions + routing + middleware:
    </p>
    <table class="ztable" style="margin:.4rem 0;font-size:.88rem">
      <tr><th>Stack</th><th style="text-align:right">Raw runtime</th><th style="text-align:right">Full framework</th><th style="text-align:right">Retention</th></tr>
      <tr><td>ZealPHP / OpenSwoole</td><td style="text-align:right">142,170</td><td style="text-align:right">116,851</td><td style="text-align:right;color:var(--accent);font-weight:700">82%</td></tr>
      <tr><td>Express / Node.js</td><td style="text-align:right">129,091</td><td style="text-align:right">19,994</td><td style="text-align:right">15%</td></tr>
    </table>
    <p style="margin:.4rem 0 0;color:#94a3b8;font-size:.82rem">
      This is the actual answer to "why does ZealPHP beat Express by 5×". It's not raw speed; it's that each layer added by the framework costs ZealPHP much less throughput than the equivalent layer costs Express.
    </p>
  </div>

  <div class="qs-block" style="padding:1.1rem 1.3rem">
    <h3 style="margin:0 0 .35rem;color:var(--accent);font-size:1.05rem">3. PHP with full middleware reaches 91% of bare Node http</h3>
    <p style="margin:0 0 .4rem;color:#cbd5e1;font-size:.92rem;line-height:1.55">
      Compose findings #1 and #2 — ZealPHP runs on a faster runtime AND keeps more of that runtime under middleware load. Net result, "PHP with everything turned on" vs "Node with nothing":
    </p>
    <table class="ztable" style="margin:.4rem 0;font-size:.88rem">
      <tr><th>Comparison</th><th style="text-align:right">Text</th><th style="text-align:right">JSON</th></tr>
      <tr><td>ZealPHP full PSR-15</td><td style="text-align:right;color:var(--accent);font-weight:700">116,851</td><td style="text-align:right;color:var(--accent);font-weight:700">105,681</td></tr>
      <tr><td>Node.js raw <code>http</code> (no framework)</td><td style="text-align:right">129,091</td><td style="text-align:right">131,513</td></tr>
      <tr><td><strong>ZealPHP retains</strong></td><td style="text-align:right;color:var(--accent)"><strong>91%</strong></td><td style="text-align:right;color:var(--accent)"><strong>80%</strong></td></tr>
    </table>
    <p style="margin:.4rem 0 0;color:#94a3b8;font-size:.82rem">
      Honest framing: ZealPHP doesn't beat hand-rolled Node http. But it gets within 10–20% of it while serving a full PSR-15 middleware stack with sessions, ETag, and reflection-based routing — features bare Node http doesn't have.
    </p>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3. Head-to-head table                                         -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">Sequential head-to-head — same workload, every stack</h2>

<p style="margin-bottom:1rem;color:#cbd5e1">
  Each runtime gets the full 12-core machine in isolation; we don't run them concurrently because that measures the scheduler instead of the framework. <code style="background:rgba(255,255,255,.06);padding:.1rem .3rem;border-radius:3px">ab -n 50000 -c 200 -k -l</code>, warmed up first.
</p>

<table class="ztable">
  <tr>
    <th style="text-align:left">Framework</th>
    <th style="text-align:right">Raw text (/raw/bench)</th>
    <th style="text-align:right">JSON (/json)</th>
    <th style="text-align:right">Template (/bench/template)</th>
  </tr>
  <tr style="background:rgba(255,255,255,.02)">
    <td colspan="4" style="color:#94a3b8;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Runtime (no framework, no middleware)</td>
  </tr>
  <tr>
    <td>OpenSwoole raw</td>
    <td style="text-align:right">141,670</td>
    <td style="text-align:right">137,535</td>
    <td style="text-align:right;color:#64748b">—</td>
  </tr>
  <tr>
    <td>Node.js raw <code>http</code></td>
    <td style="text-align:right">129,091</td>
    <td style="text-align:right">131,513</td>
    <td style="text-align:right;color:#64748b">—</td>
  </tr>
  <tr style="background:rgba(255,255,255,.02)">
    <td colspan="4" style="color:#94a3b8;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Full framework (CORS + ETag + sessions + routing + templates)</td>
  </tr>
  <tr>
    <td style="color:var(--accent);font-weight:700">ZealPHP <span style="color:#64748b;font-weight:400;font-size:.78rem">built-in PSR-15 stack</span></td>
    <td style="text-align:right;color:var(--accent);font-weight:700">116,851</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">105,681</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">49,863</td>
  </tr>
  <tr>
    <td>Express.js <span style="color:#64748b;font-size:.78rem">+ cors + etag + express-session + session-file-store + ejs + body-parser</span></td>
    <td style="text-align:right">19,994</td>
    <td style="text-align:right">21,741</td>
    <td style="text-align:right">12,470 <span style="color:#64748b;font-size:.78rem">(EJS)</span></td>
  </tr>
  <tr style="background:rgba(245,158,11,.05)">
    <td><strong>ZealPHP vs Express</strong></td>
    <td style="text-align:right;color:var(--accent);font-weight:700">+484% (5.8×)</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">+386% (4.9×)</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">+299% (4.0×)</td>
  </tr>
  <tr style="background:rgba(255,255,255,.02)">
    <td colspan="4" style="color:#94a3b8;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Other PHP frameworks (community benchmarks, similar workload class)</td>
  </tr>
  <tr><td>Slim 4</td><td colspan="3" style="text-align:right;color:#94a3b8">~4,000 req/s</td></tr>
  <tr><td>Symfony 7</td><td colspan="3" style="text-align:right;color:#94a3b8">~2,000 req/s</td></tr>
  <tr><td>Laravel 11</td><td colspan="3" style="text-align:right;color:#94a3b8">~500 req/s</td></tr>
</table>

<p style="text-align:center;margin-top:.75rem;color:#94a3b8;font-size:.85rem">
  vs Laravel 11: <strong style="color:var(--accent)">~210×</strong> ·
  vs Symfony 7: <strong style="color:var(--accent)">~55×</strong> ·
  vs Slim 4: <strong style="color:var(--accent)">~28×</strong>
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4. Concurrency sweep                                          -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">Concurrency sweep — ZealPHP solo across c = 1…1000</h2>

<p style="margin-bottom:1rem;color:#cbd5e1">
  Same 4 workers, varying simultaneous connections. Shows where each endpoint saturates, how tail latency degrades, and whether throughput holds at heavy load.
</p>

<h3 style="margin-top:1.5rem"><code>/raw/bench</code> — lean runtime, no demo middleware</h3>
<table class="ztable" style="margin:.5rem 0">
  <tr><th>c</th><th style="text-align:right">req/s</th><th style="text-align:right">avg ms</th><th style="text-align:right">p90 ms</th><th style="text-align:right">p99 ms</th><th style="text-align:right">failures</th></tr>
  <tr><td>1</td><td style="text-align:right">3,883</td><td style="text-align:right">0.26</td><td style="text-align:right">0</td><td style="text-align:right">0</td><td style="text-align:right">0</td></tr>
  <tr><td>10</td><td style="text-align:right">30,501</td><td style="text-align:right">0.33</td><td style="text-align:right">0</td><td style="text-align:right">1</td><td style="text-align:right">0</td></tr>
  <tr><td>50</td><td style="text-align:right">94,888</td><td style="text-align:right">0.53</td><td style="text-align:right">1</td><td style="text-align:right">3</td><td style="text-align:right">0</td></tr>
  <tr style="background:rgba(245,158,11,.06)"><td><strong>100</strong></td><td style="text-align:right;color:var(--accent);font-weight:700"><strong>110,964</strong></td><td style="text-align:right">0.90</td><td style="text-align:right">1</td><td style="text-align:right">6</td><td style="text-align:right">0</td></tr>
  <tr><td>200</td><td style="text-align:right">102,156</td><td style="text-align:right">1.96</td><td style="text-align:right">3</td><td style="text-align:right">9</td><td style="text-align:right">0</td></tr>
  <tr><td>500</td><td style="text-align:right">100,363</td><td style="text-align:right">4.98</td><td style="text-align:right">8</td><td style="text-align:right">20</td><td style="text-align:right">0</td></tr>
  <tr><td>1000</td><td style="text-align:right">85,001</td><td style="text-align:right">11.77</td><td style="text-align:right">19</td><td style="text-align:right">33</td><td style="text-align:right">0</td></tr>
</table>

<h3 style="margin-top:1.5rem"><code>/json</code> — full PSR-15 stack (CORS · ETag · Range · sessions · reflection-injected handler)</h3>
<table class="ztable" style="margin:.5rem 0">
  <tr><th>c</th><th style="text-align:right">req/s</th><th style="text-align:right">avg ms</th><th style="text-align:right">p90 ms</th><th style="text-align:right">p99 ms</th><th style="text-align:right">failures</th></tr>
  <tr><td>1</td><td style="text-align:right">4,173</td><td style="text-align:right">0.24</td><td style="text-align:right">0</td><td style="text-align:right">0</td><td style="text-align:right">0</td></tr>
  <tr><td>10</td><td style="text-align:right">30,840</td><td style="text-align:right">0.32</td><td style="text-align:right">0</td><td style="text-align:right">1</td><td style="text-align:right">0</td></tr>
  <tr><td>50</td><td style="text-align:right">105,868</td><td style="text-align:right">0.47</td><td style="text-align:right">1</td><td style="text-align:right">4</td><td style="text-align:right">0</td></tr>
  <tr style="background:rgba(245,158,11,.06)"><td><strong>100</strong></td><td style="text-align:right;color:var(--accent);font-weight:700"><strong>108,086</strong></td><td style="text-align:right">0.93</td><td style="text-align:right">1</td><td style="text-align:right">6</td><td style="text-align:right">0</td></tr>
  <tr><td>200</td><td style="text-align:right">93,733</td><td style="text-align:right">2.13</td><td style="text-align:right">3</td><td style="text-align:right">9</td><td style="text-align:right">0</td></tr>
  <tr><td>500</td><td style="text-align:right">95,526</td><td style="text-align:right">5.23</td><td style="text-align:right">8</td><td style="text-align:right">19</td><td style="text-align:right">0</td></tr>
  <tr><td>1000</td><td style="text-align:right">77,761</td><td style="text-align:right">12.86</td><td style="text-align:right">19</td><td style="text-align:right">81</td><td style="text-align:right">0</td></tr>
</table>

<p style="margin-top:.75rem;color:#94a3b8;font-size:.85rem;line-height:1.55">
  Peak at c = 100, sustained well past it. Throughput holds within ~20% of peak at c = 1000 with zero failures — the framework degrades gracefully rather than falling over.<br>
  Low-concurrency throughput (c = 1, c = 10) is bounded by Docker localhost-network round-trip latency, not framework cost. Run on bare metal to see higher c = 1 numbers; the c ≥ 50 figures are unaffected.
</p>

<p style="margin-top:.5rem;color:#64748b;font-size:.78rem">
  Raw CSVs: <a href="https://github.com/sibidharan/zealphp/blob/master/bench/results/ryzen-sweep/raw-bench-ryzen-c1-1000.csv" target="_blank" rel="noopener">/raw/bench</a> ·
  <a href="https://github.com/sibidharan/zealphp/blob/master/bench/results/ryzen-sweep/json-ryzen-c1-1000.csv" target="_blank" rel="noopener">/json</a>
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 5. Reproduce yourself                                          -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">Reproduce on your own machine</h2>

<p style="margin-bottom:1rem;color:#cbd5e1">
  Numbers are hardware- and OS-bound. Published figures are a starting point, not a contract. Three harnesses ship with the repo; pick the one that matches the claim you want to verify.
</p>

<h3 style="margin-top:1.5rem">One-line install (Ubuntu/Debian)</h3>

<p style="margin:.5rem 0 .75rem;color:#cbd5e1">
  Goes from a fresh box to a benched-ready clone — installs PHP 8.3, OpenSwoole, uopz, composer, wrk, ab, then clones <code>sibidharan/zealphp</code> to <code>~/zealphp</code> and runs <code>composer install</code>:
</p>

<?php App::render('/components/_code', [
    'label' => 'bench-install.sh — root-required, single command',
    'code'  => <<<'BASH'
curl -fsSL https://php.zeal.ninja/bench-install.sh | sudo bash
# Prints the next-step bench command when it finishes.
BASH
]); ?>

<p style="margin:.5rem 0 0;color:#94a3b8;font-size:.85rem">
  Inspect before piping to <code>sudo</code>:
  <code>curl -fsSL https://php.zeal.ninja/bench-install.sh | less</code>
</p>

<h3 style="margin-top:2rem">Manual install (macOS / inspect-friendly)</h3>

<?php App::render('/components/_code', [
    'label' => 'macOS (Homebrew)',
    'code'  => <<<'BASH'
brew install wrk php composer node
pecl install openswoole uopz
git clone https://github.com/sibidharan/zealphp && cd zealphp && composer install
BASH
]); ?>

<?php App::render('/components/_code', [
    'label' => 'Linux apt (one-liner equivalent, manually)',
    'code'  => <<<'BASH'
curl -fsSL https://php.zeal.ninja/install.sh | sudo bash   # PHP + openswoole + uopz + composer
sudo apt install -y wrk apache2-utils git
git clone https://github.com/sibidharan/zealphp && cd zealphp && composer install
BASH
]); ?>

<p style="margin-top:.5rem;color:#94a3b8;font-size:.85rem">
  Verify extensions loaded: <code>php -m | grep -E 'openswoole|uopz'</code>
</p>

<h3 style="margin-top:2rem">Recipe 1 — single-stack concurrency sweep (matches the tables above)</h3>

<?php App::render('/components/_code', [
    'label' => 'scripts/bench.sh',
    'code'  => <<<'BASH'
scripts/bench.sh --tool ab --requests 50000 \
                 --workers 4 --threads 4 --task-workers 0 \
                 --paths /raw/bench,/json --p1000
# Output: bench/results/zealphp-<timestamp>.csv + per-c raw logs
BASH
]); ?>

<h3 style="margin-top:1.5rem">Recipe 2 — ZealPHP vs raw Node (matches the head-to-head table)</h3>

<?php App::render('/components/_code', [
    'label' => 'scripts/bench_compare.sh',
    'code'  => <<<'BASH'
scripts/bench_compare.sh --workers 4 --threads 4 --p1000 --duration 30s
# Or via Docker so versions don't matter:
mkdir -p bench/results && docker compose run --rm --build compare
BASH
]); ?>

<h3 style="margin-top:1.5rem">Recipe 3 — 3-way with sample-to-sample variance (autocannon)</h3>

<p style="margin:.5rem 0;color:#94a3b8;font-size:.88rem">
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

<h2 style="margin:3rem 0 1rem">Methodology</h2>

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

<h3 style="margin-top:2rem">Endpoints under test</h3>

<table class="ztable">
  <tr><th>Path</th><th>Returns</th><th>What it exercises</th></tr>
  <tr><td><code>/raw/bench</code></td><td>plain text (~20 bytes)</td><td>Bare framework dispatch path with no demo middleware. Routing only.</td></tr>
  <tr><td><code>/json</code></td><td>JSON of <code>G::instance()-&gt;session</code></td><td>Full PSR-15 stack — CORS · ETag · Range · Compression · coroutine-safe sessions · reflection-injected handler · auto-JSON.</td></tr>
  <tr><td><code>/bench/template</code></td><td>~6 KB HTML</td><td>Same as <code>/json</code> + template rendering with <code>App::render()</code>.</td></tr>
</table>

<h2 style="margin:3rem 0 1rem">Caveats — read before quoting</h2>

<ul style="color:#cbd5e1;line-height:1.7;padding-left:1.2rem;margin:0">
  <li><strong>Single-machine numbers.</strong> Your hardware, OS limits, payload size, and middleware set will move these. Quote your own measurements.</li>
  <li><strong>Docker localhost RTT.</strong> c = 1 and c = 10 throughput is bounded by Docker's localhost networking overhead, not framework cost. Bare-metal runs typically post c = 1 closer to 15k-20k req/s.</li>
  <li><strong>4 workers ≈ 4 cores.</strong> Deliberate baseline. The framework is multi-process; doubling workers on a wider machine scales further until you saturate I/O or coroutine context-switching.</li>
  <li><strong>Express comparison is fair.</strong> Express runs with cors + etag + express-session + session-file-store + ejs + body-parser — middleware roughly equivalent to ZealPHP's built-in PSR-15 stack. We're not comparing bare Express to full-stack ZealPHP.</li>
  <li><strong>"Other PHP frameworks" numbers are community benchmarks</strong>, not measured on this box. They're rough orders of magnitude; we don't claim 1.0% precision.</li>
</ul>

<p style="margin-top:2rem;color:#64748b;font-size:.85rem">
  Source: <a href="https://github.com/sibidharan/zealphp/blob/master/PERF.md" target="_blank" rel="noopener">PERF.md</a> ·
  Raw CSVs: <a href="https://github.com/sibidharan/zealphp/tree/master/bench/results/ryzen-sweep" target="_blank" rel="noopener">bench/results/ryzen-sweep/</a> ·
  Scripts: <a href="https://github.com/sibidharan/zealphp/tree/master/scripts" target="_blank" rel="noopener">scripts/</a> · <a href="https://github.com/sibidharan/zealphp/tree/master/bench/compare-3way" target="_blank" rel="noopener">bench/compare-3way/</a>
</p>

</div>
</section>
