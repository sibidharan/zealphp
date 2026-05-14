<?php
$active ??= 'home';

$links = [
  'home'            => ['/',              'Home'],
  'getting-started' => ['/getting-started','Start'],
  'routing'         => ['/routing',        'Routing'],
  'responses'       => ['/responses',      'Responses'],
  'http'            => ['/http',           'HTTP'],
  'templates'       => ['/templates',      'Templates'],
  'streaming'       => ['/streaming',      'Streaming'],
  'coroutines'      => ['/coroutines',     'Coroutines'],
  'websocket'       => ['/ws',             'WebSocket'],
  'middleware'      => ['/middleware',      'Middleware'],
  'sessions'        => ['/sessions',       'Sessions'],
  'store'           => ['/store',          'Store & Cache'],
  'timers'          => ['/timers',         'Timers'],
  'api'             => ['/api',            'ZealAPI'],
  'legacy-apps'     => ['/legacy-apps',    'Legacy Apps'],
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
