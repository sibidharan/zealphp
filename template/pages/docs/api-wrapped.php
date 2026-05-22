<?php
/**
 * /docs/api/* — phpDocumentor HTML wrapped in our learn-layout shell.
 *
 * route/docs.php's patternRoute extracts <main class="phpdocumentor"> from
 * the generated HTML and passes it as $apiHtml. We also need to ship
 * phpDocumentor's own CSS so the extracted content renders, plus an
 * amber-palette overlay (docs-api.css) that re-paints the green default
 * theme to match the rest of the site.
 *
 * Expected $args:
 *   $apiHtml    — the inner HTML of phpDocumentor's <main> (already escaped
 *                 against XSS by phpDocumentor's own renderer).
 *   $apiCssHref — relative path to phpDocumentor's stylesheet (e.g.
 *                 "/docs/api/css/template.css") so we can <link> it.
 *   $apiTitle   — page title (the phpdoc <title> from the source HTML).
 */
use ZealPHP\App;

$v = defined('ZEALPHP_ASSET_VERSION') ? ZEALPHP_ASSET_VERSION : '';

// htmx attributes that make an API link swap ONLY the .lesson-content
// region in place (like the guide nav) instead of a full reload. hx-select
// pulls .lesson-content out of the target page's full HTML. Reused for the
// breadcrumb here and the phpdoc-internal links (rewritten in route/docs.php).
$apiHxAttrs = static fn (string $url): string => 'hx-get="' . htmlspecialchars($url, ENT_QUOTES) . '"'
    . ' hx-target=".lesson-content" hx-select=".lesson-content"'
    . ' hx-swap="outerHTML show:.learn-layout:top"'
    . ' hx-push-url="' . htmlspecialchars($url, ENT_QUOTES) . '"';
?>
<div class="learn-layout docs-api-layout">
  <?php
  // We DO render our docs sidebar even on API pages so users can jump
  // back to guides easily. Active item is "API Reference".
  \ZealPHP\App::render('/pages/docs/_sidebar', ['topic' => '__api__']);
  ?>

  <article class="lesson-content docs-api">
    <?php
    // phpDocumentor's stylesheet + our amber overlay live INSIDE the swapped
    // region so an inline .lesson-content swap (hx-select) carries the API
    // styling with it — navigating from a guide into the API ref still loads
    // the CSS without a full page reload. Browsers accept <link> in <body>;
    // re-inserting the same href on subsequent swaps is served from cache. ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($apiCssHref, ENT_QUOTES) ?>">
    <link rel="stylesheet" href="/css/docs-api.css?v=<?= $v ?>">

    <?php App::render('/pages/docs/_search'); ?>

    <?php $apiCrumb = $apiCrumb ?? []; if (!empty($apiCrumb)): ?>
      <nav class="docs-breadcrumbs" aria-label="Breadcrumbs">
        <?php foreach ($apiCrumb as $i => $seg): ?>
          <?php if ($i > 0): ?><span class="sep">›</span><?php endif; ?>
          <?php if (!empty($seg['href'])): ?>
            <a href="<?= htmlspecialchars($seg['href'], ENT_QUOTES) ?>" <?= $apiHxAttrs($seg['href']) ?>><?= htmlspecialchars($seg['label'], ENT_QUOTES) ?></a>
          <?php else: ?>
            <span class="current"><?= htmlspecialchars($seg['label'], ENT_QUOTES) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if (!empty($apiSidebar)): ?>
      <details class="docs-api-index">
        <summary>API Index — Namespaces, Packages, Reports, Indices</summary>
        <?= $apiSidebar /* phpDocumentor's <aside class="phpdocumentor-sidebar"> */ ?>
      </details>
    <?php endif; ?>

    <?= $apiHtml /* extracted from phpDocumentor's <main>; already
                    output-encoded by their renderer */ ?>
  </article>
</div>
