<?php
/**
 * Docs sidebar — mirrors the learn-sidebar shape (260px sticky pill list)
 * so /docs/ visually matches /learn/. Pass $topic (current guide slug, or
 * null on the landing, or '__api__' on the API page) to highlight the
 * active entry.
 */

$current = $topic ?? null;

$groups = [
    'Getting started' => [
        ['getting-started',      'Getting Started'],
        ['directory-structure',  'Directory Structure'],
        ['runtime-architecture', 'Runtime Architecture'],
    ],
    'Routing & responses' => [
        ['routing',                  'Routing'],
        ['api-layer',                'API Layer'],
        ['error-handling',           'Error Handling'],
        ['templates-and-rendering',  'Templates & Rendering'],
    ],
    'Surfaces' => [
        ['streaming',                     'Streaming'],
        ['websocket',                     'WebSocket'],
        ['tasks-and-concurrency',         'Tasks & Concurrency'],
        ['middleware-and-authentication', 'Middleware & Auth'],
    ],
    'Operations' => [
        ['deployment',       'Deployment'],
        ['fastcgi-backends', 'FastCGI Backends'],
        ['fuzzing',          'Fuzzing'],
    ],
    'Background' => [
        ['apache-parity',         'Apache Parity'],
        ['competitive-analysis',  'Competitive Analysis'],
        ['standards-and-roadmap', 'Standards & Roadmap'],
    ],
];

$apiChip = 'phpdoc';
?>
<input type="checkbox" id="docs-sidebar-toggle" class="learn-sidebar-toggle-input">
<label for="docs-sidebar-toggle" class="learn-sidebar-toggle-btn" aria-label="Toggle docs">&#9776; Docs</label>
<aside id="docs-sidebar" class="learn-sidebar" aria-label="Documentation navigation" hx-preserve="true">
  <div class="learn-sidebar-inner">
    <div class="learn-sidebar-group">
      <h4 class="learn-sidebar-group-title">Overview</h4>
      <ul class="learn-sidebar-list">
        <li class="learn-sidebar-item<?= $current === null ? ' active' : '' ?>" data-num="0">
          <a href="/docs/" class="learn-sidebar-link"
             hx-get="/api/docs/page?slug=__index__"
             hx-target=".lesson-content"
             hx-swap="outerHTML show:.learn-layout:top"
             hx-push-url="/docs/">All docs<?php if ($current === null): ?><span class="learn-sidebar-chip">home</span><?php endif; ?></a>
        </li>
        <li class="learn-sidebar-item<?= $current === '__api__' ? ' active' : '' ?>" data-num="•">
          <a href="/docs/api/" class="learn-sidebar-link">API Reference<span class="learn-sidebar-chip"><?= htmlspecialchars($apiChip) ?></span></a>
        </li>
      </ul>
    </div>

    <?php $num = 0; ?>
    <?php foreach ($groups as $heading => $items): ?>
      <div class="learn-sidebar-group">
        <h4 class="learn-sidebar-group-title"><?= htmlspecialchars($heading, ENT_QUOTES) ?></h4>
        <ul class="learn-sidebar-list">
          <?php foreach ($items as [$slug, $label]): ?>
            <?php $num++; $isActive = $slug === $current; ?>
            <li class="learn-sidebar-item<?= $isActive ? ' active' : '' ?>" data-num="<?= str_pad((string)$num, 2, '0', STR_PAD_LEFT) ?>">
              <a href="/docs/guide/<?= htmlspecialchars($slug, ENT_QUOTES) ?>"
                 class="learn-sidebar-link"
                 hx-get="/api/docs/page?slug=<?= urlencode($slug) ?>"
                 hx-target=".lesson-content"
                 hx-swap="outerHTML show:.learn-layout:top"
                 hx-push-url="/docs/guide/<?= htmlspecialchars($slug, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
</aside>
