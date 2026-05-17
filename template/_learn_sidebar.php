<?php
$active ??= 'learn';

// Plain-link groups: 2-tuples of [slug, label]. Rendered as the existing
// numbered <ol> with one <a> per item.
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
  // Build the App is rendered separately below — each tutorial collapses
  // into its own <details> with a step list inside. See $buildTheApp.
  'Under the Hood' => [
    ['learn/routing',      'Routes & APIs'],
    ['learn/async',        'Async & Coroutines'],
    ['learn/deployment',   'Ship It'],
  ],
];

// Build the App tutorials. Each tuple: [slug, label, chip, desc, demoSlug, steps].
// `steps` is a list of [anchor-id, step-label] tuples that become jump-links
// inside the <details> body. demoSlug = path under /demo/view/ (or null).
$buildTheApp = [
  ['learn/notes',     'Personal Notes', 'auth+CRUD',
   'Per-user notes with auth, sessions, and cross-tab WebSocket sync.', 'notes/widget',
   [['step-overview','Overview'], ['step-components','Component extraction'],
    ['step-auth','Auth gate'],     ['step-crud','CRUD operations'],
    ['step-sync','Live sync'],     ['step-tryit','Try it']]],
  ['learn/ai-chat',   'AI Chat', 'SSE+stream',
   'OpenAI Agents SDK streaming via PHP Server-Sent Events.', 'chat/widget',
   [['step-overview','Overview'], ['step-components','Component extraction'],
    ['step-sse','Server-Sent Events'], ['step-agent','Python agent bridge'],
    ['step-stream','Streaming + tools'], ['step-tryit','Try it']]],
  ['learn/websocket', 'Real-Time Sync', 'realtime',
   'Cross-tab counter with broadcasts.', null,
   [['step-overview','Overview'], ['step-server','Server setup'],
    ['step-broadcast','Broadcast patterns'], ['step-client','Client lifecycle'],
    ['step-tryit','Try it']]],
  ['learn/tictactoe', 'Tic-Tac-Toe', 'multiplayer',
   'Two-player WebSocket game with rooms and viewer fan-out.', 'tictactoe/play',
   [['step-setup','Setup'], ['step-state','Game state'],
    ['step-pairing','WS pairing'], ['step-moves','Player moves'],
    ['step-broadcast','Broadcasting'], ['step-reconnect','Reconnects + viewers'],
    ['step-tryit','Try it']]],
];

// Decide whether the Build-the-App section appears between Interactivity
// and Under the Hood. We render the plain groups in their natural order,
// then splice the BTA section in at its slot.
$groupOrder = ['Hello World', 'Foundations', 'Interactivity', '__BTA__', 'Under the Hood'];

// Numbering tracker — keeps the global "1, 2, 3, …" ordering across all
// groups (BTA tutorials count too).
$i = 1;
?>
<input type="checkbox" id="learn-sidebar-toggle" class="learn-sidebar-toggle-input">
<label for="learn-sidebar-toggle" class="learn-sidebar-toggle-btn" aria-label="Toggle lessons">&#9776; Lessons</label>
<aside id="learn-sidebar" class="learn-sidebar" aria-label="Lesson navigation" hx-preserve="true">
  <div class="learn-sidebar-inner">
    <?php foreach ($groupOrder as $slot): ?>
      <?php if ($slot === '__BTA__'): ?>
        <div class="learn-sidebar-group">
          <h4 class="learn-sidebar-group-title">Build the App</h4>
          <ol class="learn-sidebar-list learn-bta-list" start="<?= $i ?>">
            <?php foreach ($buildTheApp as [$slug, $label, $chip, $desc, $demoSlug, $steps]): ?>
              <li class="learn-bta-item<?= $active === $slug ? ' active' : '' ?>">
                <details<?= $active === $slug ? ' open' : '' ?>>
                  <summary>
                    <a class="learn-bta-link"
                       href="/<?= $slug ?>"
                       hx-get="/api/learn/page?slug=<?= urlencode($slug) ?>"
                       hx-target=".lesson-content"
                       hx-swap="outerHTML show:.learn-layout:top"
                       hx-push-url="/<?= $slug ?>"><?= htmlspecialchars($label) ?></a>
                    <span class="learn-bta-chip"><?= htmlspecialchars($chip) ?></span>
                  </summary>
                  <div class="learn-bta-body">
                    <ol class="learn-bta-steps">
                      <?php foreach ($steps as [$stepId, $stepLabel]): ?>
                        <li><a href="/<?= $slug ?>#<?= $stepId ?>"
                               hx-get="/api/learn/page?slug=<?= urlencode($slug) ?>"
                               hx-target=".lesson-content"
                               hx-swap="outerHTML show:#<?= $stepId ?>:top"
                               hx-push-url="/<?= $slug ?>#<?= $stepId ?>"
                            ><?= htmlspecialchars($stepLabel) ?></a></li>
                      <?php endforeach; ?>
                    </ol>
                    <?php if ($demoSlug): ?>
                      <a class="learn-bta-open" href="/demo/view/<?= $demoSlug ?>" target="_blank" rel="noopener">Open standalone <span class="learn-bta-open-arrow">↗</span></a>
                    <?php endif; ?>
                  </div>
                </details>
              </li>
              <?php $i++; endforeach; ?>
          </ol>
        </div>
      <?php else: ?>
        <?php $items = $groups[$slot] ?? []; ?>
        <div class="learn-sidebar-group">
          <h4 class="learn-sidebar-group-title"><?= htmlspecialchars($slot) ?></h4>
          <ol class="learn-sidebar-list" start="<?= $i ?>">
            <?php foreach ($items as [$slug, $label]): ?>
              <li<?= $active === $slug ? ' class="active"' : '' ?>>
                <a href="/<?= $slug ?>"
                   hx-get="/api/learn/page?slug=<?= urlencode($slug) ?>"
                   hx-target=".lesson-content"
                   hx-swap="outerHTML show:.learn-layout:top"
                   hx-push-url="/<?= $slug ?>"><?= htmlspecialchars($label) ?></a>
              </li>
              <?php $i++; endforeach; ?>
          </ol>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</aside>
