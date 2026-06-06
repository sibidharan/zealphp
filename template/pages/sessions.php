<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Sessions</h1>
<p class="section-desc">ZealPHP overrides every <code>session_*()</code> function via ext-zealphp at startup — those calls route through the coroutine-local <code>RequestContext::instance()-&gt;session</code>. <strong>Direct writes to the <code>$_SESSION</code> superglobal are NOT intercepted</strong> — PHP has no hook for variable assignment, so <code>$_SESSION['k'] = $v</code> always lands in the process-wide PHP array. In plain coroutine mode (<code>superglobals(false)</code>) that array leaks across concurrent requests. In <code>App::mode(App::MODE_COROUTINE_LEGACY)</code>, <code>$_SESSION</code> is reference-bound to <code>$g-&gt;session</code> per coroutine (writes are captured and safe). In plain <code>superglobals(true)</code> mode it's bridged through <code>$GLOBALS</code> per the <a href="/coroutines#state-parity"><code>$g</code> vs <code>$_*</code> parity rule</a>. <strong>Always read and write through <code>$g-&gt;session</code></strong> — it's the only form that's per-coroutine safe in all modes. (<code>G</code> remains as a <code>class_alias</code> for <code>RequestContext</code> since v0.2.6 — both names resolve to the same class.)</p>

<div class="callout info sess-mt">
  <strong>The model in one line.</strong> Sessions work like classic PHP — call <code>session_start()</code> and read/write <code>$g-&gt;session</code> (or <code>$_SESSION</code> in superglobals modes). The framework picks the <strong>session manager</strong> from the active mode (<code>CoSessionManager</code> in <code>coroutine</code> / <code>coroutine-legacy</code>, <code>SessionManager</code> in <code>mixed</code> / <code>legacy-cgi</code>), isolates session state <strong>per request</strong>, and routes storage through a pluggable <strong>handler</strong> (<a href="#session-backends">Table / Redis / Store / File</a>) that makes <strong>concurrent writes to the same session id safe</strong>. Handlers are registered in code before <code>App::run()</code> — no php.ini <code>session.save_handler</code> needed.
</div>

<?php App::render('/components/_code', [
    'label' => 'How it works under the hood',
    'code'  => <<<'PHP'
// At App::__construct() time — runs once per server lifecycle:
// ext-zealphp intercepts session functions, routing to per-request state:
zealphp_override('session_start',       \Closure::fromCallable('ZealPHP\Session\zeal_session_start'));
zealphp_override('session_id',          \Closure::fromCallable('ZealPHP\Session\zeal_session_id'));
zealphp_override('session_write_close', \Closure::fromCallable('ZealPHP\Session\zeal_session_write_close'));
// ... + 15 more functions (18 session_*() overrides in total)

// Use $g->session for per-coroutine safety in both modes:
$g = \ZealPHP\RequestContext::instance();
session_start();                                          // zeal_session_start — loads disk → $g->session
$g->session['user'] = ['id' => 42, 'name' => 'alice'];    // safe in both modes
session_write_close();                                    // zeal_session_write_close — serializes $g->session

// AVOID direct $_SESSION writes — they hit the process-wide superglobal and
// leak across concurrent requests in coroutine mode. The framework cannot
// intercept the assignment, only the session_*() function calls around it.
PHP]); ?>

<h2 class="sess-h2">Overridden functions</h2>
<table class="ztable sess-table-mb-2">
  <tr><th>Native PHP</th><th>ZealPHP replacement</th><th>Notes</th></tr>
  <tr><td><code>session_start()</code></td><td><code>zeal_session_start()</code></td><td>Reads session file into G::session</td></tr>
  <tr><td><code>session_id()</code></td><td><code>zeal_session_id()</code></td><td>Gets/sets session ID from cookie or G::cookie</td></tr>
  <tr><td><code>session_write_close()</code></td><td><code>zeal_session_write_close()</code></td><td>Serializes G::session to file</td></tr>
  <tr><td><code>session_destroy()</code></td><td><code>zeal_session_destroy()</code></td><td>Deletes session file</td></tr>
  <tr><td><code>session_regenerate_id()</code></td><td><code>zeal_session_regenerate_id()</code></td><td>Renames session file with new ID</td></tr>
  <tr><td><code>session_unset()</code></td><td><code>zeal_session_unset()</code></td><td>Clears all session data</td></tr>
</table>
<p class="sess-note">The &ldquo;file&rdquo; in these notes is the <em>default</em> backend. Storage actually goes through the active <a href="#session-backends">session handler</a> — Table (coroutine default), Redis, Store, or File — so <code>zeal_session_write_close()</code> persists to whatever backend you registered, not necessarily a disk file.</p>

<?php
$demos = [
  ['sess-write', 'Write session data', '/demo/session/write',
   <<<'PHP'
$app->route('/demo/session/write', function() {
    $g = G::instance();
    // session_start() is called automatically by CoSessionManager per request
    $g->session['user']    = ['id' => 1, 'name' => 'alice'];
    $g->session['login_at']= time();
    return ['written' => $g->session['user']];
});
PHP],
  ['sess-read',  'Read session data back', '/demo/session/read',
   <<<'PHP'
$app->route('/demo/session/read', function() {
    $g = G::instance();
    return [
        'session_keys' => array_keys($g->session),
        'has_user'     => isset($g->session['user']),
        'session_id'   => session_id(),
    ];
});
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<div class="callout warn sess-mt">
  Sessions are per-coroutine in coroutine mode. Each request gets its own isolated
  <code>RequestContext::instance()-&gt;session</code> via <code>Coroutine::getContext()</code> —
  in-request reads and writes are isolated. Concurrent writes to the <strong>same session id</strong>
  (two requests for the same user arriving simultaneously) are made safe by the session handler's
  optimistic-merge layer: <code>TableSessionHandler</code> uses CAS + a recursive 3-way merge;
  <code>RedisSessionHandler</code> uses WATCH/MULTI with merge-on-conflict. Per-coroutine context isolation
  alone does not resolve two coroutines flushing the same session row.
  See the <a href="/coroutines#state-parity"><code>$g</code> vs <code>$_*</code> parity rule</a> for the cross-mode story (when <code>$_SESSION</code> is safe vs. when only <code>$g-&gt;session</code> works).
</div>

<h2 id="objects-in-session" class="sess-h2-sub">Storing objects in sessions — the <code>stdClass</code> whitelist</h2>
<p>
  PHP's <code>unserialize()</code> can be turned into a remote-code-execution vector when fed
  attacker-controlled data — any class on the autoload graph with <code>__wakeup()</code> /
  <code>__destruct()</code> magic methods becomes a "gadget". Sessions are user-controlled
  storage (tampered cookie, compromised Redis), so since v0.2.25 ZealPHP's session decode
  refuses to instantiate arbitrary classes on read.
</p>
<p>
  v0.2.26 (<a href="https://github.com/sibidharan/zealphp/issues/15" target="_blank" rel="noopener">issue #15</a>) narrowed the policy to an explicit whitelist:
</p>
<table class="ztable sess-table-mb-1">
<tr><th>Stored as</th><th>Read back as</th><th>Why</th></tr>
<tr><td>Scalar (string, int, float, bool, null)</td><td>Same scalar</td><td>No instantiation needed; trivially safe.</td></tr>
<tr><td>Array (assoc or list)</td><td>Same array</td><td>Same — recursive scalars only by default.</td></tr>
<tr><td><code>stdClass</code></td><td>Live <code>stdClass</code></td><td>Zero magic methods (<code>__wakeup</code>, <code>__destruct</code>, <code>__get</code>, etc.) — no gadget chain. <code>json_decode()</code> output rides this path: OAuth token responses, API profile data, anything from <code>json_decode($x)</code> without the assoc flag.</td></tr>
<tr><td>Any other class (<code>DateTime</code>, your <code>User</code>, …)</td><td><code>__PHP_Incomplete_Class</code></td><td>Property access prints a warning and yields nulls. The class is <em>refused</em> at unserialize time. Add it to the whitelist only after a security review of its magic methods.</td></tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'In practice: storing an OAuth token from json_decode',
    'code'  => <<<'PHP'
$g = \ZealPHP\RequestContext::instance();
session_start();

$tokenResponse = json_decode($curl_body);   // returns stdClass
$g->session['oauth_token'] = $tokenResponse;
session_write_close();

// On the next request:
session_start();
echo $g->session['oauth_token']->access_token;  // ✓ works — stdClass round-trips
echo $g->session['oauth_token']->expires_in;
PHP,
]); ?>

<p class="sess-note">
  Need another class on the whitelist (rare)? Audit its <code>__wakeup</code> / <code>__unserialize</code> / <code>__destruct</code> first — those are the gadget surfaces — then patch the four <code>unserialize()</code> calls in <code>src/Session/utils.php</code>. The function-level docblock at <code>php_session_decode_to_array()</code> documents the constraint.
</p>

<h2 id="session-backends" class="sess-h2-sub">Session storage backends</h2>
<p>
  ZealPHP picks the storage handler automatically based on mode, or you can choose one explicitly
  before <code>App::run()</code> via <code>App::sessionHandler()</code>:
</p>
<table class="ztable sess-table-mb-2">
  <tr><th>Handler</th><th>When used</th><th>Concurrency safety</th></tr>
  <tr>
    <td><code>TableSessionHandler</code></td>
    <td>Opt in with <code>App::sessionHandler('table')</code> &mdash; concurrent-safe, no Redis. <strong>Not</strong> the unconfigured default (see the note under the table).</td>
    <td>Optimistic versioning: CAS version check + recursive 3-way merge on conflict. Up to 3 retries. No Redis required.</td>
  </tr>
  <tr>
    <td><code>RedisSessionHandler</code></td>
    <td><code>App::sessionHandler('redis')</code></td>
    <td>WATCH/MULTI optimistic locking with 3-way merge retry on conflict. Cross-node safe.</td>
  </tr>
  <tr>
    <td><code>StoreSessionHandler</code></td>
    <td><code>StoreSessionHandler::register(int $ttl)</code> or <code>App::sessionHandler(new StoreSessionHandler(...))</code> (coroutine-family modes only)</td>
    <td>Rides whichever backend <code>Store::defaultBackend()</code> is configured with (Table / Redis / Tiered). TTL-mode rows expire server-side on Redis.</td>
  </tr>
  <tr>
    <td><code>FileSessionHandler</code></td>
    <td>The <strong>unconfigured default in every mode</strong> (the inline file path used when <code>App::sessionHandler()</code> is <code>null</code>); or set explicitly with <code>App::sessionHandler('file')</code>.</td>
    <td>Last-writer-wins plain <code>file_put_contents</code> in the handler class. The coroutine session write path (<code>zeal_session_write_close</code>) does a read-merge-write under <code>LOCK_EX</code> for file-backed sessions. Safe for sequential workers; not recommended under coroutine concurrency &mdash; opt into <code>'table'</code>/<code>'redis'</code> there.</td>
  </tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'Selecting a session handler',
    'code'  => <<<'PHP'
// App::sessionHandler() is honored by BOTH session managers — CoSessionManager
// (coroutine / coroutine-legacy) and SessionManager (sync mixed / legacy-cgi).

// Default (no call): the framework inline FILE path in every mode — NOT
// TableSessionHandler. Under coroutine concurrency, opt into a concurrent-safe
// backend with App::sessionHandler('table') (or 'redis' for cross-node).

// Force Redis (cross-node, WATCH/MULTI concurrency safety):
App::sessionHandler('redis');   // requires Store::defaultBackend(Store::BACKEND_REDIS) or ext-redis

// Enable the Store-backed handler (backend follows Store::defaultBackend()):
StoreSessionHandler::register(3600);
// — or — App::sessionHandler(new StoreSessionHandler(...))

// Force file-backed sessions (simple, sequential workers only):
App::sessionHandler('file');

// Or pass a \SessionHandlerInterface directly:
App::sessionHandler(new MyCustomHandler());

App::init('0.0.0.0', 8080);
// ... routes ...
App::run();
PHP,
]); ?>

<h2 class="sess-h2-sub">What else gets reset per request</h2>
<p class="sess-muted">In <strong>coroutine mode</strong>, the entire <code>RequestContext</code> instance is per-coroutine — when the coroutine ends, every field on it is freed. That includes session data, response headers/cookies pending emission (on the Response wrapper), and the handler stacks pushed by <code>set_error_handler()</code> / <code>set_exception_handler()</code> / <code>register_shutdown_function()</code>. Legacy code that calls those per-request without restoring them can't accumulate handlers across requests in this mode.</p>
<p class="sess-muted">In <strong>sync superglobals modes</strong> (<code>mixed</code> / <code>legacy-cgi</code> — one request at a time per worker), <code>RequestContext</code> is a process-wide singleton, so handler stacks <strong>could</strong> grow unbounded across requests — fixed in v0.2.10 by an explicit reset in <code>SessionManager</code> at request entry. <strong><code>coroutine-legacy</code> is the exception</strong>: it runs <code>superglobals(true)</code> but still keeps a <strong>per-coroutine</strong> <code>RequestContext</code> via <code>CoSessionManager</code>, so it frees per-request state exactly like coroutine mode. See <a href="/coroutines#what-survives">What survives a request</a> for the full lifecycle matrix.</p>
</div>
</section>
