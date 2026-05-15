<?php
$title       ??= 'ZealPHP';
$description ??= 'The PHP runtime for AI web applications. Upgrade existing PHP codebases to async — SSR streaming, WebSocket, SSE, coroutines, shared memory. One server, coroutine-native concurrency.';
$v = defined('ZEALPHP_ASSET_VERSION') ? ZEALPHP_ASSET_VERSION : '';
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="<?= htmlspecialchars($description) ?>">
  <title><?= htmlspecialchars($title) ?> · ZealPHP</title>
  <link rel="stylesheet" href="/css/zealphp.css?v=<?= $v ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <style>
    /* Instrument Sans — display / heading font */
    @import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap');
  </style>
  <script src="https://unpkg.com/htmx.org@1.9.12" defer></script>
  <?php if (str_starts_with((string)($page ?? ''), 'learn')): ?>
    <link rel="stylesheet" href="/css/learn.css?v=<?= $v ?>">
    <script src="/js/learn.js?v=<?= $v ?>" defer></script>
  <?php endif; ?>
</head>
