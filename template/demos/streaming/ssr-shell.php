<?php
/**
 * /stream/ssr — the page shell, sent before any section resolves.
 * The skeletons hold the place of the sections that stream in later.
 */
?>
<!doctype html><html lang="en"><head>
  <meta charset="utf-8">
  <title>ZealPHP · Generator SSR</title>
  <link rel="stylesheet" href="/css/stream-ssr.css">
</head><body>
  <nav><a href="/streaming">← Hub</a><a href="/stream/words">Words demo</a><a href="/stream/events">SSE</a></nav>
  <h1>Generator SSR <span class="badge">yield</span></h1>
  <p>Shell arrived instantly. Two sections stream in as coroutines resolve (parallel, not sequential).</p>
  <div class="skeleton" id="sk1"></div>
  <div class="skeleton" id="sk2"></div>
