<?php
$active ??= 'learn';
$groups = [
  'Hello World' => [
    ['learn',              'Hello, ZealPHP'],
    ['learn/create-app',   'Create a ZealPHP App'],
    ['learn/first-page',   'Your First Page'],
  ],
  'Interactivity' => [
    ['learn/components',    'Layouts & Components'],
    ['learn/react-vs-php',  'React vs PHP'],
    ['learn/htmx',          'Forms & htmx'],
    ['learn/sessions',      'Sessions'],
    ['learn/auth',          'User Accounts'],
  ],
  'Build the App' => [
    ['learn/notes',        'Personal Notes'],
    ['learn/ai-chat',      'AI Chat'],
    ['learn/websocket',    'Real-Time Sync'],
  ],
  'Under the Hood' => [
    ['learn/routing',      'Routes & APIs'],
    ['learn/async',        'Async & Coroutines'],
    ['learn/deployment',   'Ship It'],
  ],
];
?>
<input type="checkbox" id="learn-sidebar-toggle" class="learn-sidebar-toggle-input">
<label for="learn-sidebar-toggle" class="learn-sidebar-toggle-btn" aria-label="Toggle lessons">&#9776; Lessons</label>
<aside class="learn-sidebar" aria-label="Lesson navigation">
  <div class="learn-sidebar-inner">
    <?php $i = 1; foreach ($groups as $title => $items): ?>
      <div class="learn-sidebar-group">
        <h4 class="learn-sidebar-group-title"><?= htmlspecialchars($title) ?></h4>
        <ol class="learn-sidebar-list" start="<?= $i ?>">
          <?php foreach ($items as [$slug, $label]): ?>
            <li<?= $active === $slug ? ' class="active"' : '' ?>>
              <a href="/<?= $slug ?>"
                 hx-get="/api/learn/page?slug=<?= urlencode($slug) ?>"
                 hx-target=".learn-layout"
                 hx-swap="outerHTML show:.learn-layout:top"
                 hx-push-url="/<?= $slug ?>"><?= htmlspecialchars($label) ?></a>
            </li>
            <?php $i++; endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
</aside>
