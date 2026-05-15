<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/notes';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 8, 'title' => 'Build Personal Notes',
      'subtitle' => 'A real app — register, log in, save notes. Backed by SQLite, wired with htmx.',
      'prev' => ['slug' => 'learn/htmx', 'title' => 'Add htmx'],
      'next' => ['slug' => 'learn/ai-chat', 'title' => 'Add AI Chat'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Open a SQLite database with PDO from a ZealPHP route',
      'Insert / select / update / delete notes scoped to the logged-in user',
      'Use htmx to add and remove notes without page reloads',
      'Use App::renderStream + renderToString to build server-rendered HTML fragments',
    ]]); ?>

    <?php if (!$user): ?>
      <section class="auth-card">
        <h2>Sign in to your vault</h2>
        <p>No email needed — just pick a username and password. Lost the password? Make a new account.</p>
        <form method="post" action="/api/learn/login">
          <input type="text" name="username" placeholder="username" autocomplete="username" required minlength="3" maxlength="64">
          <input type="password" name="password" placeholder="password (≥ 8 chars)" autocomplete="current-password" required minlength="8">
          <button type="submit">Log in</button>
        </form>
        <details style="margin-top:1rem">
          <summary>New here? Register</summary>
          <form method="post" action="/api/learn/register" style="margin-top:.75rem">
            <input type="text" name="username" placeholder="new username" required minlength="3" maxlength="64">
            <input type="password" name="password" placeholder="new password" required minlength="8">
            <button type="submit" class="auth-toggle">Register</button>
          </form>
        </details>
      </section>
    <?php else: ?>
      <p>Logged in as <strong><?= htmlspecialchars($user['username']) ?></strong> · <a href="/api/learn/logout">Log out</a></p>

      <section class="notes-app">
        <form class="note-form"
              hx-post="/api/learn/notes"
              hx-target="#notes-list"
              hx-swap="afterbegin"
              hx-on::after-request="this.reset()">
          <input type="text" name="title" placeholder="Note title" required maxlength="200">
          <textarea name="body" placeholder="Body (any text)" maxlength="4096"></textarea>
          <button type="submit">Add note</button>
        </form>

        <div id="notes-list" class="notes-list"
             hx-get="/api/learn/notes"
             hx-trigger="load"
             hx-swap="innerHTML">
          <p class="notes-empty">Loading…</p>
        </div>
      </section>
    <?php endif; ?>

    <h2>How this works</h2>
    <p>Three files, ~200 lines total. Read the source for each:</p>
    <ul>
      <li><code>route/learn.php</code> — register / login / logout + notes CRUD endpoints</li>
      <li><code>template/components/_note_card.php</code> — the per-note <code>&lt;article&gt;</code> template</li>
      <li><code>template/pages/learn/notes.php</code> — this page (the form + htmx attributes)</li>
    </ul>
  </article>
</div>
