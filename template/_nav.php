<?php
$active ??= 'home';

$links = [
  'home'            => ['/',              'Home'],
  'why-zealphp'     => ['/why-zealphp',   'Why?'],
  'case-studies'    => ['/case-studies/sna-labs', 'Case Study'],
  'migration'       => ['/migration',     'Migration'],
  'performance'     => ['/performance',   'Benchmarks'],
  'vs-fpm'          => ['/vs-fpm',         'vs FPM'],
  'design-tradeoffs'=> ['/design-tradeoffs','Trade-offs'],
  'getting-started' => ['/getting-started','Get Started'],
  'learn'           => ['/learn',          'Learn'],
  'routing'         => ['/routing',        'Routing'],
  'responses'       => ['/responses',      'Responses'],
  'http'            => ['/http',           'HTTP'],
  'api'             => ['/api',            'REST API'],
  'legacy-apps'     => ['/legacy-apps',    'Legacy Apps'],
  'templates'       => ['/templates',      'Components'],
  'streaming'       => ['/streaming',      'Streaming'],
  'coroutines'      => ['/coroutines',     'Coroutines'],
  'websocket'       => ['/ws',             'WebSocket'],
  'middleware'      => ['/middleware',      'Middleware'],
  'sessions'        => ['/sessions',       'Sessions'],
  'store'           => ['/store',          'Store & Cache'],
  'timers'          => ['/timers',         'Timers'],
  'deployment'      => ['/deployment',     'Deploy'],
];
?>
<header>
<nav class="topnav">
  <a href="/" class="logo">Zeal<span>PHP</span></a>
  <input type="checkbox" id="nav-toggle" class="nav-toggle-input">
  <label for="nav-toggle" class="nav-toggle-btn" aria-label="Toggle menu">
    <span></span><span></span><span></span>
  </label>
  <nav class="nav-links">
    <?php $isActive = function(string $key) use ($active): bool {
      if ($key === 'learn') {
        return $active === 'learn' || str_starts_with((string)$active, 'learn/');
      }
      if ($key === 'case-studies') {
        return $active === 'case-studies' || str_starts_with((string)$active, 'case-studies/');
      }
      return $active === $key;
    }; ?>
    <div class="nav-row nav-row-core">
      <?php foreach (array_slice($links, 0, 12, true) as $key => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active"' : '' ?>><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="nav-row nav-row-features">
      <?php foreach (array_slice($links, 12, null, true) as $key => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active"' : '' ?>><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </nav>
  <div id="nav-actions" class="actions" hx-preserve="true">
    <a href="https://deepwiki.com/sibidharan/zealphp" target="_blank">DeepWiki ↗</a>
    <a id="gh-star-link" href="https://github.com/sibidharan/zealphp" target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:.35rem">
      <span style="color:#fbbf24">★</span>
      <?php $__ghStars = \ZealPHP\GithubStars::format(); ?>
      <?php if ($__ghStars !== ''): ?>
        <span id="gh-star-count" style="color:#fbbf24;font-variant-numeric:tabular-nums;font-weight:600"><?= htmlspecialchars($__ghStars, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
      <span>GitHub ↗</span>
    </a>
  </div>
</nav>
</header>
