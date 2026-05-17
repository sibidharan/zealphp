<?php
/**
 * Standalone HTML wrapper for demo viewer pages.
 * Opens in a new tab from lesson "Try it live" links.
 *
 * Expects:
 *   $title       — short heading shown in nav + <title>
 *   $description — HTML string describing what this demo proves
 *   $sections    — array of ['heading' => string, 'body' => string]; body is raw HTML
 *   $back_slug   — lesson slug to link back to (e.g. 'learn/injection')
 *   $back_label  — human-readable name (e.g. 'Parameter Injection')
 */
$title       ??= 'ZealPHP Demo';
$description ??= '';
$sections    ??= [];
$back_slug   ??= 'learn';
$back_label  ??= 'Learn';
$titleHtml   = htmlspecialchars($title);
$backHtml    = htmlspecialchars($back_label);
$backSlug    = htmlspecialchars($back_slug);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $titleHtml ?> · ZealPHP Demo</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ctext y='20' font-size='20'%3E%E2%9A%A1%3C/text%3E%3C/svg%3E">
  <style>
    *{box-sizing:border-box}
    body{font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,sans-serif;max-width:760px;margin:0 auto;padding:1.5rem 1rem 3rem;background:#fafaf9;color:#1c1917;line-height:1.55}
    nav.demo-nav{display:flex;align-items:center;gap:.75rem;padding:.6rem .85rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:.85rem;margin-bottom:1.5rem}
    nav.demo-nav a{color:#b45309;text-decoration:none;font-weight:600}
    nav.demo-nav a:hover{text-decoration:underline}
    nav.demo-nav .sep{color:#92400e;opacity:.5}
    h1{font-size:1.5rem;margin:0 0 .5rem;color:#1c1917;letter-spacing:-.01em}
    .demo-desc{color:#57534e;font-size:.95rem;margin:0 0 1.5rem}
    h2.demo-h{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#78716c;margin:1.5rem 0 .55rem}
    .demo-section{background:#fff;border:1px solid #e7e5e4;border-radius:10px;padding:1rem 1.15rem;margin-bottom:1rem;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    pre.demo-payload{background:#1c1917;color:#f5f5f4;padding:.9rem 1rem;border-radius:8px;overflow-x:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem;line-height:1.55;margin:0;white-space:pre-wrap;word-break:break-word}
    pre.demo-payload .k{color:#fbbf24}
    code.demo-inline{background:#f5f5f4;padding:.1rem .35rem;border-radius:3px;font-size:.85em;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .demo-kv{display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem;margin:0}
    .demo-kv dt{color:#a8a29e;font-weight:600}
    .demo-kv dd{margin:0;color:#1c1917;word-break:break-word}
    button.demo-btn{padding:.45rem 1rem;border-radius:6px;border:1px solid #f59e0b;background:#fef3c7;color:#92400e;font-weight:600;font-size:.85rem;cursor:pointer;font-family:inherit}
    button.demo-btn:hover{background:#fde68a}
    button.demo-btn.ghost{background:transparent;color:#78716c;border-color:#d6d3d1}
    .demo-output{margin-top:.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem;padding:.85rem 1rem;background:#1c1917;color:#f5f5f4;border-radius:8px;min-height:80px;max-height:340px;overflow:auto}
    .demo-output .ev{padding:.15rem 0;border-bottom:1px solid #292524}
    .demo-output .ev:last-child{border-bottom:0}
    .demo-output .ts{color:#a8a29e;margin-right:.5rem}
    .demo-output .open{color:#34d399}
    .demo-output .tick{color:#fbbf24}
    .demo-output .done{color:#a78bfa}
    .demo-status{display:inline-block;padding:.1rem .5rem;border-radius:3px;font-size:.75rem;font-weight:700;color:#fff}
    .demo-status.s2xx{background:#16a34a}
    .demo-status.s3xx{background:#3b82f6}
    .demo-status.s4xx,.demo-status.s5xx{background:#dc2626}
  </style>
</head>
<body>
  <nav class="demo-nav">
    <a href="/<?= $backSlug ?>">← Back to “<?= $backHtml ?>” lesson</a>
    <span class="sep">·</span>
    <strong style="color:#92400e"><?= $titleHtml ?></strong>
  </nav>

  <h1><?= $titleHtml ?></h1>
  <?php if ($description !== ''): ?>
    <p class="demo-desc"><?= $description ?></p>
  <?php endif; ?>

  <?php foreach ($sections as $sec): ?>
    <h2 class="demo-h"><?= htmlspecialchars($sec['heading'] ?? '') ?></h2>
    <div class="demo-section"><?= $sec['body'] ?? '' ?></div>
  <?php endforeach; ?>
</body>
</html>
