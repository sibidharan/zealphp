<?php
$active ??= 'home';

$links = [
  'home'            => ['/',              'Home'],
  'getting-started' => ['/getting-started','Start'],
  'routing'         => ['/routing',        'Routing'],
  'rendering'       => ['/templates',      'Rendering'],
  'async'           => ['/coroutines',     'Async'],
  'middleware'      => ['/middleware',      'Middleware'],
  'state'           => ['/store',          'State'],
  'api'             => ['/api',            'ZealAPI'],
  'legacy-apps'     => ['/legacy-apps',    'Legacy Apps'],
];

// Map merged keys so child pages highlight the parent nav item
$activeMap = [
  'responses' => 'routing', 'http' => 'routing',
  'templates' => 'rendering', 'streaming' => 'rendering',
  'coroutines' => 'async', 'websocket' => 'async',
  'sessions' => 'state', 'store' => 'state', 'timers' => 'state',
];
$active = $activeMap[$active] ?? $active;
?>
<header>
<nav class="topnav">
  <a href="/" class="logo">Zeal<span>PHP</span></a>
  <input type="checkbox" id="nav-toggle" class="nav-toggle-input">
  <label for="nav-toggle" class="nav-toggle-btn" aria-label="Toggle menu">
    <span></span><span></span><span></span>
  </label>
  <nav class="nav-links">
    <?php foreach ($links as $key => [$href, $label]): ?>
      <a href="<?= $href ?>"<?= ($active === $key ? ' class="active"' : '') ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="actions">
    <a href="https://deepwiki.com/sibidharan/zealphp" target="_blank">DeepWiki ↗</a>
    <a href="https://github.com/sibidharan/zealphp" target="_blank">GitHub ↗</a>
  </div>
</nav>
</header>
