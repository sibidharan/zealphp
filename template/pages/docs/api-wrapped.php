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

$v = defined('ZEALPHP_ASSET_VERSION') ? ZEALPHP_ASSET_VERSION : '';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($apiCssHref, ENT_QUOTES) ?>">
<link rel="stylesheet" href="/css/docs-api.css?v=<?= $v ?>">

<div class="learn-layout docs-api-layout">
  <?php
  // We DO render our docs sidebar even on API pages so users can jump
  // back to guides easily. Active item is "API Reference".
  \ZealPHP\App::render('/pages/docs/_sidebar', ['topic' => '__api__']);
  ?>

  <article class="lesson-content docs-api">
    <div class="api-search" data-controller="api-search">
      <svg class="api-search-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
        <path d="M9 17A8 8 0 109 1a8 8 0 000 16zm5.5-2.5l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
      </svg>
      <input type="search"
             name="q"
             placeholder="Search the API — App, route, Middleware, $request…"
             autocomplete="off"
             spellcheck="false"
             class="api-search-input"
             hx-get="/api/docs/search"
             hx-trigger="input changed delay:180ms, search"
             hx-target="#api-search-results"
             hx-swap="innerHTML"
             hx-indicator=".api-search-spinner"
             onkeydown="if(event.key==='Escape'){this.value='';document.getElementById('api-search-results').replaceChildren();this.blur();}else if(event.key==='ArrowDown'){const f=document.querySelector('#api-search-results .api-search-item');if(f){event.preventDefault();f.focus();}}">
      <div class="api-search-spinner htmx-indicator" aria-hidden="true"></div>
      <kbd class="api-search-hint">Esc</kbd>
      <div id="api-search-results"
           class="api-search-results"
           tabindex="-1"
           onkeydown="if(event.key==='Escape'){const i=document.querySelector('.api-search-input');i.value='';this.replaceChildren();i.focus();}else if(event.key==='ArrowDown'||event.key==='ArrowUp'){const items=Array.from(this.querySelectorAll('.api-search-item'));const cur=items.indexOf(document.activeElement);if(cur===-1)return;event.preventDefault();const nxt=event.key==='ArrowDown'?items[cur+1]:items[cur-1];if(nxt)nxt.focus();else if(event.key==='ArrowUp')document.querySelector('.api-search-input').focus();}"></div>
    </div>

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
