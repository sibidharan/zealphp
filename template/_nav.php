<?php
$active ??= 'home';

$topLinks = [
  'home'        => ['/', 'Home'],
  'getting-started' => ['/getting-started', 'Start'],
  'routing'     => ['/routing', 'Routing'],
  'responses'   => ['/responses', 'Responses'],
  'coroutines'  => ['/coroutines', 'Coroutines'],
  'streaming'   => ['/streaming', 'Streaming'],
];

$moreItems = [
  'templates'   => ['/templates',  'Templates'],
  'middleware'  => ['/middleware',  'Middleware'],
  'sessions'    => ['/sessions',   'Sessions'],
  'websocket'   => ['/ws',         'WebSocket'],
  'timers'      => ['/timers',     'Timers'],
  'store'       => ['/store',      'Store'],
  'http'        => ['/http',       'HTTP'],
  'api'         => ['/api',        'ZealAPI'],
  'legacy-apps' => ['/legacy-apps', 'Legacy Apps'],
];

$moreActive = false;
foreach ($moreItems as $key => $item) {
  if ($active === $key) { $moreActive = true; break; }
}
?>
<header>
<nav class="topnav">
  <a href="/" class="logo">Zeal<span>PHP</span></a>
  <input type="checkbox" id="nav-toggle" class="nav-toggle-input">
  <label for="nav-toggle" class="nav-toggle-btn" aria-label="Toggle menu">
    <span></span><span></span><span></span>
  </label>
  <nav class="nav-links">
    <?php foreach ($topLinks as $key => [$href, $label]): ?>
      <a href="<?= $href ?>"<?= ($active === $key ? ' class="active"' : '') ?>><?= $label ?></a>
    <?php endforeach; ?>
    <div class="nav-group<?= $moreActive ? ' group-active' : '' ?>">
      <span class="nav-group-label">More <svg width="10" height="6" viewBox="0 0 10 6" fill="currentColor"><path d="M1 1l4 4 4-4"/></svg></span>
      <div class="nav-dropdown"><div class="nav-dropdown-inner">
        <?php foreach ($moreItems as $key => [$href, $label]): ?>
          <a href="<?= $href ?>"<?= ($active === $key ? ' class="active"' : '') ?>><?= $label ?></a>
        <?php endforeach; ?>
      </div></div>
    </div>
  </nav>
  <div class="actions">
    <a href="https://deepwiki.com/sibidharan/zealphp" target="_blank">DeepWiki ↗</a>
    <a href="https://github.com/sibidharan/zealphp" target="_blank">GitHub ↗</a>
  </div>
</nav>
</header>
