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
  <script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js" defer></script>
  <script>document.addEventListener('DOMContentLoaded',()=>{if(window.mermaid)mermaid.initialize({startOnLoad:true,theme:'base',themeVariables:{darkMode:false,background:'#ffffff',primaryColor:'#fffbeb',primaryBorderColor:'#f59e0b',primaryTextColor:'#1c1917',secondaryColor:'#f5f5f4',tertiaryColor:'#ecfdf5',lineColor:'#78716c',textColor:'#1c1917',mainBkg:'#fffbeb',nodeBorder:'#d6d3d1',clusterBkg:'#fafaf9',clusterBorder:'#e7e5e4',actorBkg:'#ffffff',actorBorder:'#d6d3d1',actorTextColor:'#1c1917',actorLineColor:'#78716c',signalColor:'#78716c',signalTextColor:'#1c1917',sequenceNumberColor:'#fff',noteBkgColor:'#fffbeb',noteTextColor:'#1c1917',noteBorderColor:'#f59e0b',activationBkgColor:'#f5f5f4',activationBorderColor:'#d6d3d1'}})});function fixMermaid(){document.querySelectorAll('pre.mermaid svg').forEach(s=>{s.style.background='transparent';s.querySelectorAll('rect[fill="#eaeaea"],rect[fill="#ECECFF"]').forEach(r=>r.setAttribute('fill','#f5f5f4'))})};document.addEventListener('htmx:afterSettle',()=>{if(window.mermaid)mermaid.run().then(fixMermaid)});setTimeout(fixMermaid,800)</script>
  <link rel="stylesheet" href="/css/learn.css?v=<?= $v ?>">
  <script src="/js/learn.js?v=<?= $v ?>" defer></script>
</head>
