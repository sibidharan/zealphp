<?php
/**
 * /docs/api/* fallback when public/docs/api/ doesn't exist yet — only fires
 * before the first-boot phpdoc build finishes (or if the build failed).
 */
?>
<section class="docs-landing">
  <h1>API reference not built yet</h1>
  <p class="lede">
    The auto-generated phpDocumentor HTML at <code>public/docs/api/</code>
    isn't shipped in this build. The <a href="/docs/">narrative guides</a> are
    the primary user-facing reference; the source-level docblocks live in
    <code>src/</code>.
  </p>

  <p><strong>To generate locally:</strong> phpDocumentor 3.10 currently
    conflicts with this project's <code>league/uri</code> 7.x transitive
    dep (via <code>league/commonmark</code> → <code>symfony/console</code>).
    Download the self-contained PHAR instead — it bundles its own deps and
    sidesteps the conflict:</p>

  <pre><code># From the repo root
curl -L https://github.com/phpDocumentor/phpDocumentor/releases/latest/download/phpDocumentor.phar \
  -o tools/phpdoc.phar
chmod +x tools/phpdoc.phar
php tools/phpdoc.phar -d src -t public/docs/api --title="ZealPHP API Reference"</code></pre>

  <p>
    Or browse the source docblocks directly on
    <a href="https://github.com/sibidharan/zealphp/tree/master/src" target="_blank" rel="noopener">GitHub</a>
    while we ship the bundled PHAR in a follow-up release.
  </p>
</section>
