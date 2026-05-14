<?php
$active ??= 'learn';
$groups = [
  'Get Started' => [
    ['learn',              'Quick Start'],
    ['learn/create-app',   'Create a ZealPHP App'],
    ['learn/first-page',   'Your First Page'],
  ],
  'Core Concepts' => [
    ['learn/components',   'Components'],
    ['learn/routing',      'Routing'],
    ['learn/sessions',     'Sessions & Auth'],
    ['learn/htmx',         'Add htmx'],
  ],
  'Build the App' => [
    ['learn/notes',        'Build Personal Notes'],
    ['learn/ai-chat',      'Add AI Chat'],
    ['learn/async',        'Async & Coroutines'],
    ['learn/deployment',   'Deployment'],
    ['learn/philosophy',   'Philosophy'],
  ],
];
?>
<input type="checkbox" id="learn-sidebar-toggle" class="learn-sidebar-toggle-input">
<label for="learn-sidebar-toggle" class="learn-sidebar-toggle-btn" aria-label="Toggle lessons">☰ Lessons</label>
<aside class="learn-sidebar" aria-label="Lesson navigation">
  <div class="learn-sidebar-inner">
    <?php $i = 1; foreach ($groups as $title => $items): ?>
      <div class="learn-sidebar-group">
        <h4 class="learn-sidebar-group-title"><?= htmlspecialchars($title) ?></h4>
        <ol class="learn-sidebar-list" start="<?= $i ?>">
          <?php foreach ($items as [$slug, $label]): ?>
            <li<?= $active === $slug ? ' class="active"' : '' ?>>
              <a href="/<?= $slug ?>"><?= htmlspecialchars($label) ?></a>
            </li>
            <?php $i++; endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
</aside>
