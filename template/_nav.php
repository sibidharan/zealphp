<?php
$active ??= 'home';

$links = [
  'home'            => ['/',              'Home'],
  'why-zealphp'     => ['/why-zealphp',   'Why?'],
  'migration'       => ['/migration',     'Migration'],
  'performance'     => ['/performance',   'Benchmarks'],
  'getting-started' => ['/getting-started','Getting Started'],
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
      return $key === 'learn'
        ? ($active === 'learn' || str_starts_with((string)$active, 'learn/'))
        : $active === $key;
    }; ?>
    <div class="nav-row nav-row-core">
      <?php foreach (array_slice($links, 0, 10, true) as $key => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active"' : '' ?>><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="nav-row nav-row-features">
      <?php foreach (array_slice($links, 10, null, true) as $key => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active"' : '' ?>><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </nav>
  <div id="nav-actions" class="actions" hx-preserve="true">
    <a href="https://deepwiki.com/sibidharan/zealphp" target="_blank">DeepWiki ↗</a>
    <a id="gh-star-link" href="https://github.com/sibidharan/zealphp" target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:.35rem">
      <span style="color:#fbbf24">★</span>
      <span id="gh-star-count" style="color:#fbbf24;font-variant-numeric:tabular-nums;font-weight:600"></span>
      <span>GitHub ↗</span>
    </a>
  </div>
</nav>
</header>
<script>
(function() {
  fetch('https://api.github.com/repos/sibidharan/zealphp', { headers: { 'Accept': 'application/vnd.github+json' } })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => {
      const n = d.stargazers_count;
      if (typeof n !== 'number') return;
      const fmt = n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k' : String(n);
      const el = document.getElementById('gh-star-count');
      if (el) el.textContent = fmt;
    })
    .catch(() => { /* silent fallback: link still shows "GitHub ↗" */ });
})();
</script>
