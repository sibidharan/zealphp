<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Sessions</h1>
<p class="section-desc">ZealPHP overrides all <code>session_*()</code> functions via uopz at startup. Your code calls the same PHP functions — they write to the coroutine-local <code>RequestContext::instance()-&gt;session</code> instead of the global <code>$_SESSION</code>. (<code>G</code> remains as a <code>class_alias</code> for <code>RequestContext</code> since v0.2.6 — both names resolve to the same class, examples here use whichever reads more naturally.)</p>

<?php App::render('/components/_code', [
    'label' => 'How it works under the hood',
    'code'  => <<<'PHP'
// At App::__construct() time — runs once per server lifecycle:
\uopz_set_return('session_start',       \Closure::fromCallable('ZealPHP\Session\zeal_session_start'));
\uopz_set_return('session_id',          \Closure::fromCallable('ZealPHP\Session\zeal_session_id'));
\uopz_set_return('session_write_close', \Closure::fromCallable('ZealPHP\Session\zeal_session_write_close'));
// ... + 15 more functions

// Your code stays unchanged:
session_start();
$_SESSION['user'] = ['id' => 42, 'name' => 'alice'];
session_write_close();
// → writes to G::instance()->session, not $GLOBALS['_SESSION']
PHP]); ?>

<h2 style="margin:1.5rem 0 .5rem">Overridden functions</h2>
<table class="ztable" style="margin-bottom:2rem">
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

<div class="callout warn" style="margin-top:1.5rem">
  Sessions are per-coroutine in coroutine mode. Each request gets its own isolated
  <code>RequestContext::instance()-&gt;session</code> via <code>Coroutine::getContext()</code> —
  no data leaks between concurrent requests.
</div>

<h2 style="margin:1.75rem 0 .5rem">What else gets reset per request</h2>
<p style="color:var(--text-muted)">In <strong>coroutine mode</strong>, the entire <code>RequestContext</code> instance is per-coroutine — when the coroutine ends, every field on it is freed. That includes session data, response headers/cookies pending emission (on the Response wrapper), and the handler stacks pushed by <code>set_error_handler()</code> / <code>set_exception_handler()</code> / <code>register_shutdown_function()</code>. Legacy code that calls those per-request without restoring them can't accumulate handlers across requests in this mode.</p>
<p style="color:var(--text-muted)">In <strong>superglobals mode</strong> (the legacy migration bridge), <code>RequestContext</code> is a process-wide singleton, so handler stacks <strong>could</strong> grow unbounded across requests — fixed in v0.2.10 by an explicit reset in <code>SessionManager</code> at request entry. See <a href="/coroutines#what-survives">What survives a request</a> for the full lifecycle matrix.</p>
</div>
</section>
