<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Sessions</h1>
<p class="section-desc">ZealPHP overrides every <code>session_*()</code> function via ext-zealphp at startup — those calls route through the coroutine-local <code>RequestContext::instance()-&gt;session</code>. <strong>Direct writes to the <code>$_SESSION</code> superglobal are NOT intercepted</strong> — PHP has no hook for variable assignment, so <code>$_SESSION['k'] = $v</code> always lands in the process-wide PHP array. In coroutine mode that array leaks across concurrent requests; in <code>App::superglobals(true)</code> mode it's bridged through <code>$GLOBALS</code> per the <a href="/coroutines#state-parity"><code>$g</code> vs <code>$_*</code> parity rule</a>. <strong>Always read and write through <code>$g-&gt;session</code></strong> — it's the only form that's per-coroutine safe in both modes. (<code>G</code> remains as a <code>class_alias</code> for <code>RequestContext</code> since v0.2.6 — both names resolve to the same class.)</p>

<?php App::render('/components/_code', [
    'label' => 'How it works under the hood',
    'code'  => <<<'PHP'
// At App::__construct() time — runs once per server lifecycle:
// ext-zealphp intercepts session functions, routing to per-request state:
zealphp_override('session_start',       \Closure::fromCallable('ZealPHP\Session\zeal_session_start'));
zealphp_override('session_id',          \Closure::fromCallable('ZealPHP\Session\zeal_session_id'));
zealphp_override('session_write_close', \Closure::fromCallable('ZealPHP\Session\zeal_session_write_close'));
// ... + 15 more functions

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
  no data leaks between concurrent requests. See the <a href="/coroutines#state-parity"><code>$g</code> vs <code>$_*</code> parity rule</a> for the cross-mode story (when <code>$_SESSION</code> is safe vs. when only <code>$g-&gt;session</code> works).
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

<h2 class="sess-h2-sub">What else gets reset per request</h2>
<p class="sess-muted">In <strong>coroutine mode</strong>, the entire <code>RequestContext</code> instance is per-coroutine — when the coroutine ends, every field on it is freed. That includes session data, response headers/cookies pending emission (on the Response wrapper), and the handler stacks pushed by <code>set_error_handler()</code> / <code>set_exception_handler()</code> / <code>register_shutdown_function()</code>. Legacy code that calls those per-request without restoring them can't accumulate handlers across requests in this mode.</p>
<p class="sess-muted">In <strong>superglobals mode</strong> (the legacy migration bridge), <code>RequestContext</code> is a process-wide singleton, so handler stacks <strong>could</strong> grow unbounded across requests — fixed in v0.2.10 by an explicit reset in <code>SessionManager</code> at request entry. See <a href="/coroutines#what-survives">What survives a request</a> for the full lifecycle matrix.</p>
</div>
</section>
