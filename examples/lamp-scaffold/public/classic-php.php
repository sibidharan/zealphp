<?php
/**
 * Classic LAMP-era PHP — uses ONLY $_GET / $_POST / $_SESSION / $_SERVER.
 *
 * No `$g`. No `require_once '../bootstrap/g.php'`. No framework imports.
 * This file is the kind of code your grandparent's PHP class taught in
 * 2003. Runs unchanged on:
 *
 *   - Apache + mod_php  (because PHP populates the superglobals natively)
 *   - ZealPHP >= v0.2.27 in Mixed-mode  (because the request handler now
 *     populates $_GET / $_POST / $_COOKIE / $_FILES / $_SERVER / $_REQUEST
 *     when App::superglobals(true) — restoring v0.1.x behaviour)
 *
 * If you migrate WordPress, Drupal, or a hand-rolled LAMP app onto
 * ZealPHP today, this is the file you don't have to touch.
 */

// Native session — works the same on both servers.
session_start();

$_SESSION['classic_visits'] = ($_SESSION['classic_visits'] ?? 0) + 1;

// Headers — `header()` is uopz-overridden by ZealPHP to write through the
// per-request response wrapper. Apache: native.
header('Content-Type: text/html; charset=utf-8');

// Read query string / form fields exactly the way you'd read them in 2003.
$name = htmlspecialchars($_GET['name'] ?? 'world', ENT_QUOTES, 'UTF-8');
$ua   = htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '', ENT_QUOTES, 'UTF-8');
$srv  = htmlspecialchars($_SERVER['SERVER_SOFTWARE']  ?? '?', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Classic LAMP · Hello <?= $name ?></title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; line-height: 1.6 }
    code { background: #f3f4f6; padding: .1rem .35rem; border-radius: 3px }
    .box { padding: 1rem; background: #fef3c7; border-left: 3px solid #f59e0b; margin: 1rem 0; border-radius: 4px }
    table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .9rem }
    th, td { padding: .35rem .6rem; text-align: left; border-bottom: 1px solid #e5e7eb }
    a { color: #b45309 }
  </style>
</head>
<body>
  <p><a href="/">&larr; Home</a></p>

  <h1>Hello, <?= $name ?> (classic-php.php)</h1>
  <p>This file uses <strong>only</strong> PHP superglobals — no <code>$g</code>, no bootstrap. It's literally a 2003-style LAMP page, and it just runs.</p>

  <div class="box">
    <strong>You're hitting:</strong> <code><?= $srv ?></code><br>
    <strong>Session counter (via <code>$_SESSION</code>):</strong> <?= (int) $_SESSION['classic_visits'] ?><br>
    <strong>User agent:</strong> <code><?= $ua ?></code>
  </div>

  <p>Try the query string: <a href="?name=Daisy">?name=Daisy</a>. <code>$_GET['name']</code> reads it on both servers.</p>

  <h2>What v0.2.27 fixed</h2>
  <p>Before v0.2.27, ZealPHP populated <code>$g->get</code> / <code>$g->session</code> but NOT <code>$_GET</code> / <code>$_SESSION</code> in <code>superglobals(true)</code> mode. The flag's whole purpose was misleading — code reading <code>$_GET['x']</code> got nothing. v0.2.27 restores the v0.1.x behaviour: both names are populated, and <code>$g->session</code> + <code>$_SESSION</code> are now the SAME array (mutations through either are visible through the other immediately).</p>

  <table>
    <tr><th>Server</th><th><code>$_GET</code></th><th><code>$_SESSION</code></th><th>How</th></tr>
    <tr><td>Apache + mod_php</td><td>✓ Populated</td><td>✓ Populated</td><td>PHP/SAPI populates natively</td></tr>
    <tr><td>ZealPHP &lt; v0.2.27</td><td>✗ Empty</td><td>✗ Inconsistent with $g->session</td><td>Declared-property bypass dropped the bridge</td></tr>
    <tr><td>ZealPHP &gt;= v0.2.27</td><td>✓ Populated</td><td>✓ Same array as $g->session</td><td>App.php:3565 + SessionManager + RequestContext __get/__set proxy</td></tr>
  </table>

  <h2>More pages in this scaffold</h2>
  <ul>
    <li><a href="/">/index.php</a> — uses <code>$g</code> (the cross-server portable form)</li>
    <li><a href="/about.php">/about.php</a> — server info via <code>$g->server</code></li>
    <li><a href="/session-counter.php">/session-counter.php</a> — counter via <code>$g->session</code></li>
    <li><a href="/api/users.php?id=42">/api/users.php?id=42</a> — JSON API via <code>$g->get</code></li>
  </ul>

  <p style="color:#6b7280;font-size:.85rem;margin-top:2rem">
    Both styles are now supported in <code>superglobals(true)</code> mode. Pick whichever fits the codebase you're migrating. New code on ZealPHP-only should still prefer <code>$g</code> — it's the only form that works in coroutine mode (where superglobals are intentionally not populated, since they'd race across coroutines).
  </p>
</body>
</html>
