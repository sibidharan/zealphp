<?php
$active ??= 'learn';

// Every lesson uses the SAME row shape. Optional 4th + 5th values are a
// monochrome chip and a /demo/view/ slug for the standalone popout link
// (rendered as the last sub-step under the active item).
$groups = [
  'Hello World' => [
    ['learn',                  'Hello, ZealPHP'],
    ['learn/create-app',       'Create a ZealPHP App'],
    ['learn/first-page',       'Your First Page'],
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
    ['learn/components',       'Layouts & Components'],
    ['learn/react-vs-php',     'React vs PHP'],
    ['learn/htmx',             'Forms & htmx'],
    ['learn/sessions',         'Sessions'],
    ['learn/auth',             'User Accounts'],
  ],
  'Build the App' => [
    ['learn/notes',            'Personal Notes', 'auth + crud',  'notes/widget'],
    ['learn/ai-chat',          'AI Chat',        'sse + stream', 'chat/widget'],
    ['learn/websocket',        'Real-Time Sync', 'realtime',     'websocket/counter'],
    ['learn/tictactoe',        'Tic-Tac-Toe',    'multiplayer',  'tictactoe/play'],
  ],
  'Under the Hood' => [
    ['learn/routing',          'Routes & APIs'],
    ['learn/async',            'Async & Coroutines'],
    ['learn/deployment',       'Ship It'],
  ],
];
?>
<input type="checkbox" id="learn-sidebar-toggle" class="learn-sidebar-toggle-input">
<label for="learn-sidebar-toggle" class="learn-sidebar-toggle-btn" aria-label="Toggle lessons">&#9776; Lessons</label>
<aside id="learn-sidebar" class="learn-sidebar" aria-label="Lesson navigation" hx-preserve="true">
  <div class="learn-sidebar-inner">
    <?php $lessonNum = 0; ?>
    <?php foreach ($groups as $title => $items): ?>
      <div class="learn-sidebar-group">
        <h4 class="learn-sidebar-group-title"><?= htmlspecialchars($title) ?></h4>
        <ul class="learn-sidebar-list">
          <?php foreach ($items as $item): ?>
            <?php $lessonNum++;
                  [$slug, $label] = [$item[0], $item[1]];
                  $chip = $item[2] ?? null;
                  $demo = $item[3] ?? null; ?>
            <li class="learn-sidebar-item<?= $active === $slug ? ' active' : '' ?>"
                data-num="<?= sprintf('%02d', $lessonNum) ?>"
                <?= $chip ? 'data-chip="'.htmlspecialchars($chip, ENT_QUOTES).'"' : '' ?>
                <?= $demo ? 'data-demo="'.htmlspecialchars($demo, ENT_QUOTES).'"' : '' ?>>
              <a href="/<?= $slug ?>"
                 class="learn-sidebar-link"
                 hx-get="/api/learn/page?slug=<?= urlencode($slug) ?>"
                 hx-target=".lesson-content"
                 hx-swap="outerHTML show:.learn-layout:top"
                 hx-push-url="/<?= $slug ?>"><?= htmlspecialchars($label) ?><?php if ($chip): ?><span class="learn-sidebar-chip"><?= htmlspecialchars($chip) ?></span><?php endif; ?></a>
              <!-- /js/learn.js auto-injects <ul class="learn-substeps">…</ul> here for the active item -->
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
</aside>
