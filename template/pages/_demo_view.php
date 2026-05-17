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
 */
$demo_title       ??= 'Demo';
$demo_back_slug   ??= 'learn';
$demo_back_label  ??= 'Learn';
$demo_description ??= '';
$demo_sections    ??= [];
?>
<section class="hero-narrow">
  <div class="demo-viewer">
    <nav class="demo-viewer-crumb">
      <a href="/<?= htmlspecialchars($demo_back_slug) ?>">&larr; Back to &ldquo;<?= htmlspecialchars($demo_back_label) ?>&rdquo; lesson</a>
      <span aria-hidden="true">·</span>
      <strong><?= htmlspecialchars($demo_title) ?></strong>
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
</section>
