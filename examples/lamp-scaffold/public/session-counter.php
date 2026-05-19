<?php
/**
 * Session counter — proves $g->session is the SAME store across page loads.
 *
 * On Apache: backs onto PHP's native file-based session in /var/lib/php/sessions.
 * On ZealPHP: ZealPHP's session handler (file-backed by default) — same disk,
 *   same cookie, same key, same data. Visitors can hop between servers and
 *   their session persists.
 */
require_once __DIR__ . '/../bootstrap/g.php';

session_start();

$g->session['count'] = ($g->session['count'] ?? 0) + 1;

$count  = (int) $g->session['count'];
$cookie = $g->cookie['PHPSESSID'] ?? '(not set)';
?><!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Session counter · LAMP scaffold</title>
  <meta http-equiv="refresh" content="3">
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; line-height: 1.6; text-align: center }
    .big { font-size: 4rem; margin: 1rem 0; color: #f59e0b; font-weight: 700 }
    code { background: #f3f4f6; padding: .1rem .35rem; border-radius: 3px }
    .meta { color: #6b7280; font-size: .9rem; word-break: break-all }
  </style>
</head>
<body>
  <p><a href="/">&larr; Home</a></p>
  <h1>You've loaded this page</h1>
  <div class="big"><?= $count ?> times</div>
  <p>This page auto-refreshes every 3 seconds. The counter lives in <code>$g-&gt;session['count']</code>, which is backed by the standard <code>PHPSESSID</code> cookie on both servers.</p>
  <p class="meta">Your session cookie: <code><?= htmlspecialchars($cookie) ?></code></p>
  <p><a href="/api/users.php?id=42">Try the JSON API &rarr;</a></p>
</body>
</html>
