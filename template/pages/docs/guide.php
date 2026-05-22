<?php
/**
 * /docs/guide/{topic} — render a single docs/*.md file inside _master.
 *
 * Body is pre-rendered HTML from league/commonmark (GitHub-flavoured, html_input=strip).
 * This template only wraps it in the consistent docs chrome.
 *
 * Expected $args from route/docs.php: $topic, $heading, $body.
 */
?>
<section class="docs-guide">
  <nav class="docs-breadcrumbs" aria-label="Breadcrumbs">
    <a href="/docs/">Docs</a>
    <span class="sep">›</span>
    <a href="/docs/">Guides</a>
    <span class="sep">›</span>
    <span class="current"><?= htmlspecialchars($heading ?? $topic, ENT_QUOTES) ?></span>
  </nav>

  <article class="docs-markdown">
    <?= $body /* rendered HTML, html_input=strip already sanitised */ ?>
  </article>

  <footer class="docs-foot">
    <p>
      Source: <a href="https://github.com/sibidharan/zealphp/blob/master/docs/<?= htmlspecialchars($topic, ENT_QUOTES) ?>.md" target="_blank" rel="noopener">docs/<?= htmlspecialchars($topic, ENT_QUOTES) ?>.md</a>
      · Found a drift?
      <a href="https://github.com/sibidharan/zealphp/issues/new" target="_blank" rel="noopener">Open an issue</a>.
    </p>
  </footer>
</section>
