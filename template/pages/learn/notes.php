<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/notes';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 9, 'title' => 'Personal Notes',
      'subtitle' => 'Everything comes together. Auth, htmx, SQLite, components — a real app.',
      'prev' => ['slug' => 'learn/auth', 'title' => 'User Accounts'],
      'next' => ['slug' => 'learn/ai-chat', 'title' => 'AI Chat'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Build a full CRUD app with SQLite and htmx',
      'Return HTML fragments from API endpoints with App::renderToString()',
      'Wire htmx to add, list, and delete notes without page reloads',
      'Reuse components across different contexts (list, create, chat history)',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      You have users who can log in. Now they want to <strong>store things</strong> — each user
      seeing only their own notes, adding new ones, deleting old ones. No page reloads. This is the
      lesson where everything you've learned comes together.
    </p>
    <p>
      Every data-driven app follows the <strong>CRUD loop</strong>: Create, Read, Update, Delete.
      Build this once, and you can build any data app — a todo list, a blog, an inventory system.
    </p>

    <?php if (!$user): ?>
      <section class="auth-card">
        <h2>Sign in to your vault</h2>
        <p>No email needed — just pick a username and password. Lost the password? Make a new account.</p>
        <form hx-post="/api/learn/login" hx-target="#notes-auth-fb-login" hx-swap="innerHTML">
          <input type="text" name="username" placeholder="username" autocomplete="username" required minlength="3" maxlength="64">
          <input type="password" name="password" placeholder="password (8+ chars)" autocomplete="current-password" required minlength="8">
          <button type="submit">Log in</button>
          <div id="notes-auth-fb-login"></div>
        </form>
        <details style="margin-top:1rem">
          <summary>New here? Register</summary>
          <form hx-post="/api/learn/register" hx-target="#notes-auth-fb-reg" hx-swap="innerHTML" style="margin-top:.75rem">
            <input type="text" name="username" placeholder="new username" required minlength="3" maxlength="64">
            <input type="password" name="password" placeholder="new password" required minlength="8">
            <button type="submit" class="auth-toggle">Register</button>
            <div id="notes-auth-fb-reg"></div>
          </form>
        </details>
      </section>
    <?php else: ?>
      <div class="notes-user-bar">
        <span class="notes-user-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
        <span class="notes-user-name"><?= htmlspecialchars($user['username']) ?></span>
        <a href="/api/learn/logout" class="notes-user-logout">Log out</a>
      </div>

      <section class="notes-app">
        <form class="note-form"
              hx-post="/api/learn/notes"
              hx-target="#notes-list"
              hx-swap="afterbegin"
              hx-on::after-request="this.reset()"
              hx-on::after-settle="var f=document.querySelector('#notes-list .note:first-child');if(f){f.classList.add('note-created');setTimeout(function(){f.classList.remove('note-created')},2500)}">
          <input type="text" name="title" placeholder="Note title" required maxlength="200">
          <textarea name="body" placeholder="What's on your mind?" maxlength="4096"></textarea>
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

    <?php App::render('/components/_callout', [
      'variant' => 'success',
      'title'   => 'Watch what happens',
      'body'    => '<p><strong>Create a note</strong> above and watch: the card slides in with a green glow. Now <strong>open this page in a second tab</strong>, delete a note in one — the other tab updates instantly (via WebSocket). On the <a href="/learn/ai-chat">AI Chat page</a>, the Event Log terminal shows every SSE and WebSocket event as it flows.</p>',
    ]); ?>

    <h2>The architecture</h2>
    <pre class="mermaid">sequenceDiagram
    participant B as Browser
    participant H as htmx
    participant API as /api/learn/notes
    participant N as Notes.php
    participant DB as SQLite
    participant WS as WebSocket
    B->>H: Submit form
    H->>API: POST /api/learn/notes
    API->>N: Notes::create($db, $userId, ...)
    N->>DB: INSERT INTO notes
    DB-->>N: id = 42
    N-->>API: note row
    API->>WS: broadcast(note_changed)
    WS-->>B: push to all tabs
    API-->>H: HTML card fragment
    H-->>B: afterbegin swap (green glow)</pre>
    <p>Three layers, each with one job:</p>
    <ol>
      <li><strong><a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/Notes.php" target="_blank"><code>src/Learn/Notes.php</code></a></strong> — Business logic. SQL queries scoped by <code>user_id</code>.</li>
      <li><strong><a href="https://github.com/sibidharan/zealphp/blob/master/api/learn/notes.php" target="_blank"><code>api/learn/notes.php</code></a></strong> — Endpoint. Reads the request, calls the class, returns HTML.</li>
      <li><strong>Template + htmx</strong> — UI. The form and list, wired with four htmx attributes.</li>
    </ol>

    <h3>The data layer</h3>
    <p>Every method takes a <code>$userId</code> parameter. The user can never read or modify another user's notes:</p>
    <pre><code class="language-php">// <a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/Notes.php" style="color:#f59e0b">src/Learn/Notes.php</a>
class Notes
{
    public static function create(\PDO $db, int $userId, string $title, string $body): ?int
    {
        $stmt = $db->prepare(
            'INSERT INTO notes (user_id, title, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, trim($title), $body, time(), time()]);
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

    <h3>Introducing <code>App::renderToString()</code></h3>
    <p>
      In Lesson 4, you learned <code>App::render()</code> which echoes HTML. But htmx sent a POST and
      expects HTML back as the <em>response body</em>. You need the HTML as a string, not echoed to
      the page. That's <code>App::renderToString()</code>:
    </p>
    <pre><code class="language-php">// <a href="https://github.com/sibidharan/zealphp/blob/master/api/learn/notes.php" style="color:#f59e0b">api/learn/notes.php</a> — return the rendered note card
$note = Notes::read($db, $userId, $id);
$html = App::renderToString('/components/_note_card', $note);
$this->response($html, 200);</code></pre>
    <p>Same component, same template file — but now you get the HTML as a string to return from your API.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Why return HTML, not JSON?',
      'body'    => '<p>htmx expects HTML fragments. The server renders the note card and returns it directly. htmx swaps it into the DOM. No client-side template. No JSON parsing. No React state management. The server is the single source of truth.</p>',
    ]); ?>

    <h3>The htmx wiring</h3>
    <p>The form above has four htmx attributes that replace 30+ lines of JavaScript:</p>
    <pre><code class="language-html">&lt;form hx-post="/api/learn/notes"
      hx-target="#notes-list"
      hx-swap="afterbegin"
      hx-on::after-request="this.reset()"&gt;</code></pre>
    <ul>
      <li><code>hx-post</code> — sends the form data as POST</li>
      <li><code>hx-target</code> — which DOM element receives the response</li>
      <li><code>hx-swap="afterbegin"</code> — insert the new note as the first child (top of list)</li>
      <li><code>hx-on::after-request</code> — clear the form after success</li>
    </ul>
    <p>Delete uses a similar pattern:</p>
    <pre><code class="language-html">&lt;button hx-delete="/api/learn/notes/&lt;?= $id ?&gt;"
        hx-target="#note-&lt;?= $id ?&gt;"
        hx-swap="outerHTML"
        hx-confirm="Delete this note?"&gt;Delete&lt;/button&gt;</code></pre>
    <p><code>hx-swap="outerHTML"</code> replaces the entire note card with the empty response — effectively removing it.</p>

    <h3>Component reuse</h3>
    <p>The <a href="https://github.com/sibidharan/zealphp/blob/master/template/components/_note_card.php" target="_blank"><code>_note_card</code></a> component is used in three places: the notes list (GET), the create response (POST), and the chat history bubbles (Lesson 9). Same file, three contexts — that's the power of server-rendered components.</p>

    <?php App::render('/components/_deepdive', [
      'title' => 'Cross-tab sync via WebSocket',
      'body'  => '<p>When you add or delete a note, the server also broadcasts a <code>note_changed</code> event via WebSocket. Other browser tabs receive it and refresh their notes list with <code>htmx.ajax()</code>. Open this page in two tabs and try it — <a href="/learn/websocket">Lesson 10</a> explains how.</p>',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'CRUD is four verbs: Create, Read, Update, Delete — every data app follows this pattern',
      '<code>App::renderToString()</code> returns HTML as a string for htmx fragment responses',
      'Four htmx attributes replace 30+ lines of JavaScript for form submission',
      'User-scoped queries (<code>WHERE user_id = ?</code>) ensure data isolation',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/auth"
         hx-get="/api/learn/page?slug=learn/auth" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/auth">← User Accounts</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/ai-chat"
         hx-get="/api/learn/page?slug=learn/ai-chat" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/ai-chat">AI Chat →</a>
    </div>
  </article>
</div>
