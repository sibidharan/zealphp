<?php
/**
 * /docs/api/* fallback. Only fires when the auto-build at boot failed —
 * the normal path serves the generated phpDocumentor HTML directly via
 * route/docs.php's patternRoute → sendFile(). If you're seeing this in
 * production, something went wrong during the boot-time build.
 */
use ZealPHP\App;
?>
<div class="learn-layout">
  <?php App::render('/pages/docs/_sidebar', ['topic' => '__api__']); ?>

  <article class="lesson-content docs-landing">
    <header class="lesson-header">
      <nav class="lesson-crumb"><a href="/docs/">Docs</a> &nbsp;›&nbsp; API Reference</nav>
      <h1 class="lesson-title">API reference build pending</h1>
      <p class="lesson-subtitle">
        The auto-build at boot didn't produce
        <code>public/docs/api/index.html</code>. The framework runs
        phpDocumentor on first <code>php app.php</code> start; this fallback
        appears when that build hasn't completed (or failed). The
        <a href="/docs/">narrative guides</a> are the primary user-facing
        reference meanwhile.
      </p>
    </header>

    <div class="docs-markdown">
      <h2>Build it manually</h2>
      <p>From the repo root:</p>

      <pre><code># Download the PHAR (33 MB, one-time)
mkdir -p tools
curl -fsSL https://github.com/phpDocumentor/phpDocumentor/releases/latest/download/phpDocumentor.phar \
  -o tools/phpdoc.phar
chmod +x tools/phpdoc.phar

# Generate the API reference
php tools/phpdoc.phar -d "$(pwd)/src" -t "$(pwd)/public/docs/api" --title="ZealPHP API Reference"</code></pre>

      <p>Once <code>public/docs/api/index.html</code> exists, refresh this page —
      <code>route/docs.php</code> serves the generated HTML directly via
      <code>$response-&gt;sendFile()</code> with full Range / ETag support.</p>

      <h2>Or skip the auto-build entirely</h2>
      <p>Set <code>ZEALPHP_SKIP_DOCS_BUILD=1</code> before booting — useful in
      CI / production environments where outbound network is restricted or
      the 33 MB PHAR isn't acceptable.</p>

      <h2>Browse docblocks on GitHub</h2>
      <p>Every public method in <code>src/</code> is documented in-source.
        Browse the
        <a href="https://github.com/sibidharan/zealphp/tree/master/src" target="_blank" rel="noopener">src/ tree on GitHub</a>
        — IDE-friendly PHPDoc, link to specific line numbers, full type
        information.</p>
    </div>
  </article>
</div>
