<?php
$title       ??= 'ZealPHP';
$description ??= 'The PHP runtime for AI web applications. Upgrade existing PHP codebases to async — SSR streaming, WebSocket, SSE, coroutines, shared memory. One server, coroutine-native concurrency.';
$v = defined('ZEALPHP_ASSET_VERSION') ? ZEALPHP_ASSET_VERSION : '';

// Social / link-unfurl metadata (Open Graph + Twitter Card). Derived
// once here so every page that renders _master gets a correct share
// preview — Slack/Twitter/Discord/iMessage read these server-side, no
// JS. The page <title> already appends " · ZealPHP"; the share title
// mirrors that for consistency.
$shareTitle = str_contains($title, 'ZealPHP') ? $title : ($title . ' · ZealPHP');

// Canonical absolute URL of the page being served — built from the
// request so it's correct on any host (php.zeal.ninja, localhost, a
// preview tunnel). Unfurlers fetch the raw URL, so this reflects the
// shared page even though htmx swaps don't re-render <head>.
$__g       = \ZealPHP\G::instance();
$__srv     = $__g->server ?? [];
$__https   = (($__srv['HTTPS'] ?? '') === 'on')
    || (($__srv['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ((int) ($__srv['SERVER_PORT'] ?? 0) === 443);
$__scheme  = $__https ? 'https' : 'http';
$__host    = (string) ($__srv['HTTP_HOST'] ?? $__srv['SERVER_NAME'] ?? 'php.zeal.ninja');
$__path    = parse_url((string) ($__srv['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$canonicalUrl = $__scheme . '://' . $__host . $__path;

// Optional share image. Pages may set $og_image (absolute path under
// the doc root, e.g. "/og.png"); we emit the tag only if the file
// exists so a missing asset never produces a broken-image card.
$ogImage = $og_image ?? '/og.png';
$ogImageExists = is_string($ogImage)
    && is_file(dirname(__DIR__) . '/public' . parse_url($ogImage, PHP_URL_PATH));
$ogImageUrl = $ogImageExists ? ($__scheme . '://' . $__host . $ogImage) : null;
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="<?= htmlspecialchars($description) ?>">
  <title><?= htmlspecialchars($title) ?> · ZealPHP</title>
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES) ?>">

  <!-- Open Graph (Facebook, Slack, Discord, iMessage, LinkedIn) -->
  <meta property="og:site_name" content="ZealPHP">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= htmlspecialchars($shareTitle, ENT_QUOTES) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($description, ENT_QUOTES) ?>">
  <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES) ?>">
  <?php if ($ogImageUrl !== null): ?>
  <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES) ?>">
  <?php endif; ?>

  <!-- Twitter Card -->
  <meta name="twitter:card" content="<?= $ogImageUrl !== null ? 'summary_large_image' : 'summary' ?>">
  <meta name="twitter:title" content="<?= htmlspecialchars($shareTitle, ENT_QUOTES) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($description, ENT_QUOTES) ?>">
  <?php if ($ogImageUrl !== null): ?>
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="/css/zealphp.css?v=<?= $v ?>">
  <!-- All page-scoped styles in one always-loaded bundle (route/assets.php).
       Loaded up front, not lazily per page, so hx-boost navigation never
       flashes unstyled content waiting on a per-page stylesheet to fetch. -->
  <link rel="stylesheet" href="/css/pages.css?v=<?= $v ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/stackoverflow-dark.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <!-- Instrument Sans — display / heading font; Fira Code — code font -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap">
  <script src="https://unpkg.com/htmx.org@2.0.10" defer></script>
  <!-- head-support: hx-boost swaps <body> + <title> but NOT <head>; this
       extension reconciles <head> on boosted navigation so each page's
       page-scoped CSS/JS module (css/pages/*.css, js/pages/*.js) loads
       when you navigate INTO it, not just on a full reload. -->
  <script src="https://unpkg.com/htmx-ext-head-support@2.0.4/head-support.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js" defer></script>
  <script src="/js/mermaid-init.js?v=<?= $v ?>" defer></script>
  <script src="/js/site-nav.js?v=<?= $v ?>" defer></script>
  <link rel="stylesheet" href="/css/learn.css?v=<?= $v ?>">
  <script src="/js/learn.js?v=<?= $v ?>" defer></script>
  <script src="/js/streaming-demos.js?v=<?= $v ?>" defer></script>
  <script src="/js/learn-demo-viewers.js?v=<?= $v ?>" defer></script>
  <script src="/js/learn-tictactoe.js?v=<?= $v ?>" defer></script>
  <script src="/js/timers.js?v=<?= $v ?>" defer></script>
<?php
  // Page-scoped JS modules — scripts extracted out of the page templates
  // (no inline <script> in templates, per the separation-of-concerns
  // rule). A page keyed "$page" (e.g. "home", "learn/htmx") gets its
  // module auto-loaded when the file exists; slashes flatten to dashes
  // so "learn/htmx" → learn-htmx.js. Existence-gated. (Page CSS is NOT
  // lazy-loaded here — it ships in the always-loaded /css/pages.css
  // bundle above, so boosted navigation never flashes unstyled.)
  $__pageKey  = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($page ?? ''));
  $__pubRoot  = dirname(__DIR__) . '/public';
  if ($__pageKey !== '' && is_file($__pubRoot . '/js/pages/' . $__pageKey . '.js')): ?>
  <script src="/js/pages/<?= $__pageKey ?>.js?v=<?= $v ?>" defer></script>
<?php endif; ?>
</head>
