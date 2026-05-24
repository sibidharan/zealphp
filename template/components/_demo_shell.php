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
  <!-- pages.css = boot-time concatenation of public/css/pages/*.css. Demo
       shells render the same widget partials as the lesson pages
       (_chatroom_widget, _notes_widget, _tictactoe_widget, …), so they need
       the same per-page CSS the lessons load. Without this link, widgets
       in /demo/view/* render unstyled. -->
  <link rel="stylesheet" href="/css/pages.css?v=<?= $v ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/stackoverflow-dark.min.css">
  <script src="https://unpkg.com/htmx.org@1.9.12" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js" defer></script>
  <script src="/js/learn-demo-viewers.js?v=<?= $v ?>" defer></script>
  <script src="/js/learn-tictactoe.js?v=<?= $v ?>" defer></script>
  <!-- The chat widget's behaviour (initChat → loadHistory + SSE send + notes
       WS sync) lives in learn.js. This shell renders the same _chat_widget
       partial as the /learn/ai-chat lesson, so it needs learn.js too — without
       it the standalone chat never initialised: no history loaded (it shares
       the lesson's thread_id via localStorage) and a dead Send button. -->
  <script src="/js/learn.js?v=<?= $v ?>" defer></script>
  <link rel="stylesheet" href="/css/demo-shell.css?v=<?= $v ?>">
  <script src="/js/demo-shell.js?v=<?= $v ?>" defer></script>
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
</body>
</html>
