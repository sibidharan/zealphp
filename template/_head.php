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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <style>
    /* Instrument Sans — display / heading font */
    @import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap');
  </style>
  <script src="https://unpkg.com/htmx.org@2.0.10" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js" defer></script>
  <script>document.addEventListener('DOMContentLoaded',()=>{if(window.mermaid)mermaid.initialize({startOnLoad:true,theme:'base',themeVariables:{darkMode:false,background:'#ffffff',primaryColor:'#fffbeb',primaryBorderColor:'#f59e0b',primaryTextColor:'#1c1917',secondaryColor:'#f5f5f4',tertiaryColor:'#ecfdf5',lineColor:'#78716c',textColor:'#1c1917',mainBkg:'#fffbeb',nodeBorder:'#d6d3d1',clusterBkg:'#fafaf9',clusterBorder:'#e7e5e4',actorBkg:'#ffffff',actorBorder:'#d6d3d1',actorTextColor:'#1c1917',actorLineColor:'#78716c',signalColor:'#78716c',signalTextColor:'#1c1917',sequenceNumberColor:'#fff',noteBkgColor:'#fffbeb',noteTextColor:'#1c1917',noteBorderColor:'#f59e0b',activationBkgColor:'#f5f5f4',activationBorderColor:'#d6d3d1'}})});function fixMermaid(){document.querySelectorAll('pre.mermaid svg').forEach(s=>{s.style.background='transparent';s.querySelectorAll('rect[fill="#eaeaea"],rect[fill="#ECECFF"]').forEach(r=>r.setAttribute('fill','#f5f5f4'))})};document.addEventListener('htmx:afterSettle',()=>{if(window.mermaid)mermaid.run().then(fixMermaid)});setTimeout(fixMermaid,800)</script>
  <link rel="stylesheet" href="/css/learn.css?v=<?= $v ?>">
  <script src="/js/learn.js?v=<?= $v ?>" defer></script>
  <script src="/js/streaming-demos.js?v=<?= $v ?>" defer></script>
  <script src="/js/learn-demo-viewers.js?v=<?= $v ?>" defer></script>
  <script src="/js/learn-tictactoe.js?v=<?= $v ?>" defer></script>
  <script src="/js/timers.js?v=<?= $v ?>" defer></script>
</head>
