<?php
require_once __DIR__ . '/../bootstrap/g.php';

$method = $g->server['REQUEST_METHOD'] ?? 'GET';
$ua     = htmlspecialchars($g->server['HTTP_USER_AGENT'] ?? '', ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>About · LAMP scaffold</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; line-height: 1.6 }
    code { background: #f3f4f6; padding: .1rem .35rem; border-radius: 3px }
    th, td { padding: .35rem .6rem; text-align: left; border-bottom: 1px solid #e5e7eb }
  </style>
</head>
<body>
  <p><a href="/">&larr; Home</a></p>
  <h1>About this scaffold</h1>
  <p>This is the smallest possible "vanilla PHP" app that runs on both Apache+mod_php and ZealPHP. There are three files in <code>public/</code>, a single compat shim in <code>bootstrap/g.php</code>, and an <code>app.php</code> that boots ZealPHP. That's it.</p>

  <h2>Server side</h2>
  <table>
    <tr><th>Request method</th><td><code><?= htmlspecialchars($method) ?></code></td></tr>
    <tr><th>User agent</th><td><code><?= $ua ?></code></td></tr>
    <tr><th>Server software</th><td><code><?= htmlspecialchars($g->server['SERVER_SOFTWARE'] ?? '?') ?></code></td></tr>
    <tr><th>Document root</th><td><code><?= htmlspecialchars($g->server['DOCUMENT_ROOT'] ?? '?') ?></code></td></tr>
  </table>

  <p>The <code>$g-&gt;server</code> array is populated by PHP on Apache, and by ZealPHP from the OpenSwoole request object — same shape, same keys, same values. Code that reads it doesn't know which server it's on.</p>
</body>
</html>
