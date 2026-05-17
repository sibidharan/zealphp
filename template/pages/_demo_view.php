<?php
/**
 * Demo viewer body — rendered inside _master.php (full site shell: nav,
 * banner, footer, /css/zealphp.css, /css/learn.css). Used by every
 * /demo/view/* route. Expects:
 *
 *   $demo_title       — page heading
 *   $demo_back_slug   — lesson slug for the back-link (e.g. 'learn/injection')
 *   $demo_back_label  — human-readable lesson name
 *   $demo_description — HTML string (can include <code>)
 *   $demo_sections    — array of ['heading' => string, 'body' => string]
 *
 * Demo viewer pages are normally opened in a new tab from a lesson's "Try
 * it live" link, so the back-link prefers to close the tab and only
 * navigates if window.close() is rejected by the browser. hx-boost is
 * disabled on every nav link below so the body doesn't get swapped in
 * place (which would duplicate the demo shell inside the lesson).
 */
$demo_title       ??= 'Demo';
$demo_back_slug   ??= 'learn';
$demo_back_label  ??= 'Learn';
$demo_description ??= '';
$demo_sections    ??= [];
?>
<div class="demo-viewer" hx-boost="false">
  <nav class="lesson-crumb demo-viewer-crumb">
    <a href="/learn" hx-boost="false">ZealPHP Learn</a>
    &nbsp;&rsaquo;&nbsp;
    <a href="/<?= htmlspecialchars($demo_back_slug) ?>" hx-boost="false"><?= htmlspecialchars($demo_back_label) ?></a>
    &nbsp;&rsaquo;&nbsp;
    <span><?= htmlspecialchars($demo_title) ?></span>
    <a class="demo-viewer-close"
       href="/<?= htmlspecialchars($demo_back_slug) ?>"
       hx-boost="false"
       onclick="if (window.opener || history.length === 1) { window.close(); return false; }">
      &larr; Close &amp; back to lesson
    </a>
  </nav>

  <h1 class="demo-viewer-title"><?= htmlspecialchars($demo_title) ?></h1>
  <?php if ($demo_description !== ''): ?>
    <p class="demo-viewer-desc"><?= $demo_description ?></p>
  <?php endif; ?>

  <?php foreach ($demo_sections as $sec): ?>
    <h2 class="demo-viewer-h"><?= htmlspecialchars($sec['heading'] ?? '') ?></h2>
    <div class="inject-case">
      <div class="inject-case-body inject-case-body--solo">
        <div class="demo-output">
          <?= $sec['body'] ?? '' ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
