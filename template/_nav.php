<?php
$active ??= 'home';
$links = [
  'home'        => ['/', 'Home'],
  'getting-started' => ['/getting-started', 'Start'],
  'routing'     => ['/routing',   'Routing'],
  'responses'   => ['/responses', 'Responses'],
  'coroutines'  => ['/coroutines','Coroutines'],
  'streaming'   => ['/streaming', 'Streaming'],
  'websocket'   => ['/ws',        'WebSocket'],
  'middleware'  => ['/middleware','Middleware'],
  'sessions'    => ['/sessions',  'Sessions'],
  'store'       => ['/store',     'Store'],
  'timers'      => ['/timers',    'Timers'],
  'http'        => ['/http',      'HTTP'],
  'api'         => ['/api',       'ZealAPI'],
  'legacy-apps' => ['/legacy-apps', 'Legacy Apps'],
];
?>
<header>
<nav class="topnav">
  <a href="/" class="logo">Zeal<span>PHP</span></a>
  <nav>
    <?php foreach ($links as $key => [$href, $label]): ?>
      <a href="<?= $href ?>"<?= ($active === $key ? ' class="active"' : '') ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="actions">
    <a href="https://github.com/sibidharan/zealphp" target="_blank">GitHub ↗</a>
  </div>
</nav>
</header>
