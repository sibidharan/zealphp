<?php
/**
 * Landing page — vanilla PHP, no framework imports.
 *
 * Runs unchanged on Apache (mod_php) AND on ZealPHP. The only line that
 * makes that possible is the `require_once` below — it bootstraps `$g`
 * regardless of which server is loading this file.
 */
require_once __DIR__ . '/../bootstrap/g.php';

session_start();

// First visit? give the user a name; show how long they've been here.
$g->session['first_seen'] = $g->session['first_seen'] ?? time();
$g->session['hits']       = ($g->session['hits'] ?? 0) + 1;

$secondsHere = time() - (int) $g->session['first_seen'];
$server      = $g->server['SERVER_SOFTWARE'] ?? 'unknown';
$name        = htmlspecialchars($g->get['name'] ?? 'stranger', ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>LAMP scaffold · Hello <?= $name ?></title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; line-height: 1.6 }
    code { background: #f3f4f6; padding: .1rem .35rem; border-radius: 3px }
    .stack { padding: 1rem; background: #fef3c7; border-left: 3px solid #f59e0b; margin: 1rem 0; border-radius: 4px }
    a { color: #b45309 }
  </style>
</head>
<body>
  <h1>Hello, <?= $name ?></h1>
  <p>This page is <strong>identical bytes</strong> on Apache and on ZealPHP. Open the URL on either server — same code, same result.</p>

  <div class="stack">
    <strong>You're hitting:</strong> <code><?= htmlspecialchars($server) ?></code><br>
    <strong>Session hits:</strong> <?= (int) $g->session['hits'] ?><br>
    <strong>Seconds since first visit:</strong> <?= $secondsHere ?>
  </div>

  <p>Try a query string: <a href="?name=Daisy">?name=Daisy</a>. <code>$g->get['name']</code> reads it on both servers.</p>

  <h2>More pages</h2>
  <ul>
    <li><a href="/about.php">About</a></li>
    <li><a href="/session-counter.php">Session counter</a> — same session, multiple pages</li>
    <li><a href="/api/users.php?id=42">API: GET /api/users.php?id=42</a> — JSON via <code>$g->get['id']</code></li>
  </ul>

  <hr>
  <p style="color:#6b7280;font-size:.85rem">
    Bootstrap: <code>require_once __DIR__ . '/../bootstrap/g.php'</code> &mdash;
    that one line is what makes the rest of this file portable.
  </p>
</body>
</html>
