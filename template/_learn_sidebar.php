<?php
$active ??= 'learn';
$groups = [
  'Hello World' => [
    ['learn',              'Hello, ZealPHP'],
    ['learn/create-app',   'Create a ZealPHP App'],
    ['learn/first-page',   'Your First Page'],
  ],
  'Foundations' => [
    ['learn/mental-model',     'The Mental Model'],
    ['learn/project-structure','Project Structure'],
    ['learn/routes',           'How Routes Work'],
    ['learn/lifecycle',        'A Request\'s Journey'],
    ['learn/injection',        'Parameter Injection'],
    ['learn/responses',        'Returning a Response'],
    ['learn/middleware',       'Middleware: The Wrap'],
    ['learn/streaming',        'Streaming Done Right'],
    ['learn/store',            'Sharing State'],
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
<aside id="learn-sidebar" class="learn-sidebar" aria-label="Lesson navigation" hx-preserve="true">
  <div class="learn-sidebar-inner">
    <?php $i = 1; foreach ($groups as $title => $items): ?>
      <div class="learn-sidebar-group">
        <h4 class="learn-sidebar-group-title"><?= htmlspecialchars($title) ?></h4>
        <ol class="learn-sidebar-list" start="<?= $i ?>">
          <?php foreach ($items as [$slug, $label]): ?>
            <li<?= $active === $slug ? ' class="active"' : '' ?>>
              <a href="/<?= $slug ?>"
                 hx-get="/api/learn/page?slug=<?= urlencode($slug) ?>"
                 hx-target=".lesson-content"
                 hx-swap="outerHTML show:.lesson-content:top"
                 hx-push-url="/<?= $slug ?>"><?= htmlspecialchars($label) ?></a>
            </li>
            <?php $i++; endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
</aside>
