<?php
$active ??= 'home';

$groups = [
  ['items' => [
    'home'            => ['/', 'Home'],
    'getting-started' => ['/getting-started', 'Start'],
  ]],
  ['label' => 'Core', 'items' => [
    'routing'    => ['/routing',    'Routing'],
    'responses'  => ['/responses',  'Responses'],
    'middleware' => ['/middleware',  'Middleware'],
    'sessions'   => ['/sessions',   'Sessions'],
  ]],
  ['label' => 'Async', 'items' => [
    'coroutines' => ['/coroutines', 'Coroutines'],
    'streaming'  => ['/streaming',  'Streaming'],
    'websocket'  => ['/ws',         'WebSocket'],
    'timers'     => ['/timers',     'Timers'],
  ]],
  ['label' => 'More', 'items' => [
    'store'       => ['/store',       'Store'],
    'http'        => ['/http',        'HTTP'],
    'api'         => ['/api',         'ZealAPI'],
    'legacy-apps' => ['/legacy-apps', 'Legacy Apps'],
  ]],
];
?>
<header>
<nav class="topnav">
  <a href="/" class="logo">Zeal<span>PHP</span></a>
  <nav>
    <?php foreach ($groups as $group): ?>
      <?php if (empty($group['label'])): ?>
        <?php foreach ($group['items'] as $key => [$href, $label]): ?>
          <a href="<?= $href ?>"<?= ($active === $key ? ' class="active"' : '') ?>><?= $label ?></a>
        <?php endforeach; ?>
      <?php else: ?>
        <?php
          $groupActive = false;
          foreach ($group['items'] as $key => $item) {
            if ($active === $key) { $groupActive = true; break; }
          }
        ?>
        <div class="nav-group<?= $groupActive ? ' group-active' : '' ?>">
          <span class="nav-group-label"><?= $group['label'] ?> <svg width="10" height="6" viewBox="0 0 10 6" fill="currentColor"><path d="M1 1l4 4 4-4"/></svg></span>
          <div class="nav-dropdown"><div class="nav-dropdown-inner">
            <?php foreach ($group['items'] as $key => [$href, $label]): ?>
              <a href="<?= $href ?>"<?= ($active === $key ? ' class="active"' : '') ?>><?= $label ?></a>
            <?php endforeach; ?>
          </div></div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="actions">
    <a href="https://deepwiki.com/sibidharan/zealphp" target="_blank">DeepWiki ↗</a>
    <a href="https://github.com/sibidharan/zealphp" target="_blank">GitHub ↗</a>
  </div>
</nav>
</header>
