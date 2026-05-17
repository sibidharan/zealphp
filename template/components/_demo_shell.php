<?php
/**
 * Standalone demo viewer shell. Mirrors the clean look of
 * learn_demo_shell() in route/learn.php — site CSS, slim breadcrumb,
 * focused content area, no big top-nav. Open in new tab/popup.
 *
 * Expects:
 *   $title       — page heading
 *   $description — HTML string (can include <code>)
 *   $sections    — array of ['heading' => string, 'body' => string]
 *   $back_slug   — lesson slug for the back-link (e.g. 'learn/sessions')
 *   $back_label  — human-readable lesson name
 */
$title       ??= 'Demo';
$description ??= '';
$sections    ??= [];
$back_slug   ??= 'learn';
$back_label  ??= 'Learn';
$v           = defined('ZEALPHP_ASSET_VERSION') ? ZEALPHP_ASSET_VERSION : (string)time();
$titleHtml   = htmlspecialchars($title);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $titleHtml ?> · ZealPHP Demo</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ctext y='20' font-size='20'%3E%E2%9A%A1%3C/text%3E%3C/svg%3E">
  <link rel="stylesheet" href="/css/zealphp.css?v=<?= $v ?>">
  <link rel="stylesheet" href="/css/learn.css?v=<?= $v ?>">
  <script src="https://unpkg.com/htmx.org@1.9.12" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js" defer></script>
  <script src="/js/learn-demo-viewers.js?v=<?= $v ?>" defer></script>
  <style>
    body.demo-shell-body { max-width: 760px; margin: 0 auto; padding: 1.25rem 1rem 4rem; background: var(--bg, #fafaf9); }
    .demo-shell-crumb { display: flex; align-items: center; gap: .35rem; padding: .55rem .9rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: .85rem; color: #92400e; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .demo-shell-crumb a { color: #b45309; text-decoration: none; font-weight: 600; }
    .demo-shell-crumb a:hover { text-decoration: underline; }
    .demo-shell-crumb .sep { color: #92400e; opacity: .55; }
    .demo-shell-crumb .here { color: #1c1917; font-weight: 700; }
    .demo-shell-crumb .close { margin-left: auto; padding: .15rem .7rem; border: 1px solid #fcd34d; border-radius: 999px; background: #fef3c7; font-size: .78rem; }
    .demo-shell-crumb .close:hover { background: #fde68a; text-decoration: none; }
    .demo-shell-title { font-size: 1.7rem; margin: 0 0 .55rem; letter-spacing: -.01em; color: #1c1917; }
    .demo-shell-desc { color: #57534e; font-size: .98rem; margin: 0 0 1.5rem; line-height: 1.6; }
    .demo-shell-h { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #78716c; margin: 1.5rem 0 .5rem; }
  </style>
</head>
<body class="demo-shell-body">
  <nav class="demo-shell-crumb">
    <a href="/learn">ZealPHP Learn</a>
    <span class="sep">&rsaquo;</span>
    <a href="/<?= htmlspecialchars($back_slug) ?>"><?= htmlspecialchars($back_label) ?></a>
    <span class="sep">&rsaquo;</span>
    <span class="here"><?= $titleHtml ?></span>
    <a class="close"
       href="/<?= htmlspecialchars($back_slug) ?>"
       onclick="if (window.opener || window.history.length <= 2) { window.close(); return false; }">&larr; Close</a>
  </nav>

  <h1 class="demo-shell-title"><?= $titleHtml ?></h1>
  <?php if ($description !== ''): ?>
    <p class="demo-shell-desc"><?= $description ?></p>
  <?php endif; ?>

  <?php foreach ($sections as $sec): ?>
    <h2 class="demo-shell-h"><?= htmlspecialchars($sec['heading'] ?? '') ?></h2>
    <div class="inject-case">
      <div class="inject-case-body inject-case-body--solo">
        <div class="demo-output">
          <?= $sec['body'] ?? '' ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <script>
    // Code highlighting once highlight.js loads
    window.addEventListener('load', () => {
      if (window.hljs) document.querySelectorAll('pre code').forEach(el => hljs.highlightElement(el));
    });
  </script>
</body>
</html>
