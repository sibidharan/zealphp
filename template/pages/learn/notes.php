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
      'Use App::renderToString to build server-rendered HTML fragments for htmx',
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

    <h3>The database layer</h3>
    <p>Business logic lives in <code>src/Learn/Notes.php</code> — a plain PHP class autoloaded via Composer. Each method takes a <code>PDO</code> connection and a <code>$userId</code>, ensuring every query is scoped to the logged-in user:</p>
    <pre><code class="language-php">// src/Learn/Notes.php
class Notes
{
    public static function create(\PDO $db, int $userId, string $title, string $body): ?int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) return null;
        if (strlen($body) > 4096) return null;
        $now = time();
        $stmt = $db->prepare(
            'INSERT INTO notes (user_id, title, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $body, $now, $now]);
        return (int) $db->lastInsertId();
    }

    public static function list(\PDO $db, int $userId): array
    {
        $stmt = $db->prepare(
            'SELECT id, title, body, updated_at FROM notes
             WHERE user_id = ? ORDER BY updated_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}</code></pre>
    <p>Every method uses prepared statements with bound <code>$userId</code> — the user can never read or modify another user's notes.</p>

    <h3>The API endpoint (ZealAPI file)</h3>
    <p>The notes endpoint lives at <code>api/learn/notes.php</code>. The closure variable name <code>$notes</code> matches the filename. Inside, it checks auth, reads the HTTP method, and delegates to the <code>Notes</code> class:</p>
    <pre><code class="language-php">// api/learn/notes.php
$notes = function () {
    $u = Auth::currentUser();
    if (!$u) { $this->response($this->json(['error' => 'auth_required']), 401); return; }
    $g = G::instance();
    $method = strtoupper($g->server['REQUEST_METHOD'] ?? 'GET');
    $db = DB::open();

    if ($method === 'POST') {
        // Create a note and return the rendered card HTML
        $id = Notes::create($db, $u['user_id'], $title, $bodyText);
        $note = Notes::read($db, $u['user_id'], $id);
        $this->response(App::renderToString('/components/_note_card', $note), 200);
        return;
    }

    // GET — list all notes as rendered HTML cards
    $notesList = Notes::list($db, $u['user_id']);
    $html = '';
    foreach ($notesList as $n) {
        $html .= App::renderToString('/components/_note_card', $n);
    }
    $this->response($html, 200);
};</code></pre>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Why return HTML, not JSON?',
      'body'    => '<p>htmx expects HTML fragments. The server renders the note card via <code>App::renderToString(\'/components/_note_card\', $note)</code> and returns it directly. htmx swaps it into the DOM. No client-side template, no JSON parsing, no React state management.</p>',
    ]); ?>

    <h3>The htmx wiring</h3>
    <p>The form above has four htmx attributes that replace 30+ lines of JavaScript:</p>
    <pre><code class="language-html">&lt;form hx-post="/api/learn/notes"
      hx-target="#notes-list"
      hx-swap="afterbegin"
      hx-on::after-request="this.reset()"&gt;</code></pre>
    <ul>
      <li><code>hx-post</code> — sends the form data as a POST request</li>
      <li><code>hx-target</code> — which DOM element to update with the response</li>
      <li><code>hx-swap="afterbegin"</code> — insert the new note as the first child (top of list)</li>
      <li><code>hx-on::after-request</code> — clear the form after successful submit</li>
    </ul>
    <p>The delete button on each note uses a similar pattern:</p>
    <pre><code class="language-html">&lt;button hx-delete="/api/learn/notes/&lt;?= $id ?&gt;"
        hx-target="#note-&lt;?= $id ?&gt;"
        hx-swap="outerHTML"
        hx-confirm="Delete this note?"&gt;Delete&lt;/button&gt;</code></pre>
    <p><code>hx-swap="outerHTML"</code> replaces the entire note card with the empty response — effectively removing it from the page.</p>

    <h3>The note card component</h3>
    <p>Each note renders via a reusable template at <code>template/components/_note_card.php</code>:</p>
    <pre><code class="language-php">// template/components/_note_card.php
&lt;article class="note" id="note-&lt;?= $id ?&gt;" data-id="&lt;?= $id ?&gt;"&gt;
  &lt;h4 class="note-title"&gt;&lt;?= htmlspecialchars($title) ?&gt;&lt;/h4&gt;
  &lt;p class="note-body"&gt;&lt;?= nl2br(htmlspecialchars($body)) ?&gt;&lt;/p&gt;
  &lt;div class="note-meta"&gt;
    &lt;span&gt;Updated &lt;?= date('Y-m-d H:i', $updated_at) ?&gt;&lt;/span&gt;
    &lt;button hx-delete="..." hx-confirm="Delete?"&gt;Delete&lt;/button&gt;
  &lt;/div&gt;
&lt;/article&gt;</code></pre>
    <p>This component is used in three places: the notes list (GET), the create response (POST), and the chat history bubbles. Same file, three contexts — that's the power of server-rendered components.</p>

    <?php App::render('/components/_deepdive', [
      'title' => 'Cross-tab sync via WebSocket',
      'body'  => '<p>When you add or delete a note, the server also broadcasts a <code>note_changed</code> event via <code>App::ws(\'/ws/learn\')</code>. Other browser tabs receive it and refresh their notes list via <code>htmx.ajax()</code>. Open this page in two tabs and try it — <a href="/learn/websocket">Lesson 10</a> explains how.</p>',
    ]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/htmx" hx-get="/api/learn/page?slug=learn/htmx" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/htmx">← Add htmx</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/ai-chat" hx-get="/api/learn/page?slug=learn/ai-chat" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/ai-chat">Add AI Chat →</a>
    </div>
  </article>
</div>
