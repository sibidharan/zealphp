<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/notes';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number' => 18, 'title' => 'Personal Notes',
      'subtitle' => 'Everything comes together. Auth, htmx, SQLite, components — a real app.',
      'prev' => ['slug' => 'learn/auth', 'title' => 'User Accounts'],
      'next' => ['slug' => 'learn/websocket', 'title' => 'Real-Time Sync'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Build a full CRUD app with SQLite and htmx',
      'Return HTML fragments from API endpoints with App::renderToString()',
      'Wire htmx to add, list, and delete notes without page reloads',
      'Reuse components across different contexts (list, create, chat history)',
    ]]); ?>

    <h2 id="step-overview">1. Overview — what you&rsquo;re building</h2>
    <p>
      Users can log in. Now they want to <strong>store things</strong> — each user seeing only their
      own notes, adding new ones, deleting old ones. No page reloads. This is the lesson where
      everything you&rsquo;ve learned comes together.
    </p>
    <p>
      Every data-driven app follows the <strong>CRUD loop</strong>: Create, Read, Update, Delete.
      Build this once, and you can build any data app — a todo list, a blog, an inventory system.
    </p>

    <div class="callout info">
      <strong>Build it yourself.</strong> The business logic classes (<code>Notes</code>, <code>Auth</code>,
      <code>DB</code>, <code>WS</code>) ship with the framework library &mdash; they&rsquo;re already in
      your <code>vendor/</code> directory after <code>composer create-project</code>. This lesson walks you
      through creating the <strong>glue files</strong> that wire them into a working app:
      <ol>
        <li><code>api/learn/notes.php</code> &mdash; CRUD endpoint</li>
        <li><code>route/learn.php</code> &mdash; path-param routes + WebSocket registration</li>
        <li><code>template/components/_notes_widget.php</code> &mdash; the notes UI</li>
        <li><code>template/components/_note_card.php</code> &mdash; individual note display</li>
      </ol>
      Create each file as you reach its section. Restart the server (<code>php app.php restart</code>) after
      adding route files.
    </div>

    <h2 id="step-components">2. Component extraction — the same widget, two places</h2>
    <p>
      Before we wire anything up, let&rsquo;s build the UI. The note-creation form, the list of
      notes below it, and the user bar at the top form a self-contained UI block. Create it as a
      reusable partial:
    </p>
    <p><strong>Create <code>template/components/_notes_widget.php</code>:</strong></p>
    <pre><code class="language-php">&lt;?php
$user ??= null;
if (!$user) return;
?&gt;
&lt;div class="notes-user-bar"&gt;
  &lt;span class="notes-user-avatar"&gt;&lt;?= strtoupper(substr($user['username'], 0, 1)) ?&gt;&lt;/span&gt;
  &lt;span class="notes-user-name"&gt;&lt;?= htmlspecialchars($user['username']) ?&gt;&lt;/span&gt;
  &lt;a href="/api/learn/logout" class="notes-user-logout"&gt;Log out&lt;/a&gt;
&lt;/div&gt;

&lt;section class="notes-app"&gt;
  &lt;form class="note-form"
        hx-post="/api/learn/notes"
        hx-target="#notes-list"
        hx-swap="afterbegin"
        hx-on::after-request="this.reset()"&gt;
    &lt;input type="text" name="title" placeholder="Note title" required maxlength="200"&gt;
    &lt;textarea name="body" placeholder="What's on your mind?" maxlength="4096"&gt;&lt;/textarea&gt;
    &lt;button type="submit"&gt;Add note&lt;/button&gt;
  &lt;/form&gt;

  &lt;div id="notes-list" class="notes-list"
       hx-get="/api/learn/notes"
       hx-trigger="load"
       hx-swap="innerHTML"&gt;
    &lt;p class="notes-empty"&gt;Loading&amp;hellip;&lt;/p&gt;
  &lt;/div&gt;
&lt;/section&gt;</code></pre>
    <p>
      The partial renders identical HTML in two consumers — and that&rsquo;s the lesson:
    </p>
    <ol>
      <li><strong>Inline in this lesson</strong> — the lesson page calls
        <code>App::render('/components/_notes_widget', ['user' =&gt; $user])</code> below, embedded
        right between explanation paragraphs so you can try it without leaving the page.</li>
      <li><strong>Standalone in a popup</strong> — <code>/demo/view/notes/widget</code> renders the
        same partial inside <code>_demo_shell.php</code> (the focused, no-nav shell). Open it in a
        second tab to test cross-tab sync without two lesson pages cluttering the screen.</li>
    </ol>
    <p>
      Both consumers pass the same <code>$user</code> array; the widget itself trusts that and emits
      the same DOM, with the same <code>#notes-list</code> id so <code>/js/learn.js</code> wires up
      WebSocket sync identically in both contexts. <strong>One component, two consumers</strong> —
      that&rsquo;s the React-style reuse pattern, just at the server-side template layer.
    </p>

    <h2 id="step-auth">3. Auth gate</h2>
    <p>
      Notes are per-user, so the lesson page checks for a logged-in user. If none, it renders the
      login/register card (lesson-specific copy). If a user is found, it scrolls down to the working
      widget at <a href="#step-tryit">Try it</a>. The widget itself does not check auth — that&rsquo;s
      the caller&rsquo;s job, which means the same widget can be rendered in any context that
      already has a user (lesson page, standalone popup, future admin tool, etc).
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
        <details class="lnotes-details">
          <summary>New here? Register</summary>
          <form hx-post="/api/learn/register" hx-target="#notes-auth-fb-reg" hx-swap="innerHTML" class="lnotes-reg-form">
            <input type="text" name="username" placeholder="new username" required minlength="3" maxlength="64">
            <input type="password" name="password" placeholder="new password" required minlength="8">
            <button type="submit" class="auth-toggle">Register</button>
            <div id="notes-auth-fb-reg"></div>
          </form>
        </details>
      </section>
    <?php else: ?>
      <p>Logged in as <strong><?= htmlspecialchars($user['username']) ?></strong>. Scroll to <a href="#step-tryit">Try it</a> to see the working widget — or read on for the CRUD wiring underneath.</p>
    <?php endif; ?>

    <h2 id="step-crud">4. CRUD operations</h2>
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
      <li><strong><code>ZealPHP\Learn\Notes</code></strong> — Business logic (already in <code>vendor/</code> via the framework). SQL queries scoped by <code>user_id</code>.</li>
      <li><strong><code>api/learn/notes.php</code></strong> — Endpoint you&rsquo;ll create. Reads the request, calls the class, returns HTML.</li>
      <li><strong>Template + htmx</strong> — UI you created in step 2, wired with four htmx attributes.</li>
    </ol>

    <h3>The data layer (already in vendor)</h3>
    <p>The <code>ZealPHP\Learn\Notes</code> class ships with the framework &mdash; you don&rsquo;t need to create it. Every method takes a <code>$userId</code> parameter. The user can never read or modify another user&rsquo;s notes:</p>
    <pre><code class="language-php">// vendor/sibidharan/zealphp/src/Learn/Notes.php — already installed
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
      In Lesson 13 (<a href="/learn/components">Layouts &amp; Components</a>), you learned
      <code>App::render()</code> which echoes HTML. But htmx sent a POST and expects HTML back as the
      <em>response body</em>. You need the HTML as a string, not echoed to the page. That's
      <code>App::renderToString()</code>:
    </p>
    <p><strong>Create <code>api/learn/notes.php</code></strong> &mdash; this is the endpoint htmx talks to:</p>
    <pre><code class="language-php">&lt;?php
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;
use ZealPHP\Learn\Notes;
use ZealPHP\Learn\WS;

${basename(__FILE__, '.php')} = function () {
    $u = Auth::currentUser();
    if (!$u) { $this->response($this->json(['error' => 'auth_required']), 401); return; }
    $g = G::instance();
    $method = strtoupper($g->server['REQUEST_METHOD'] ?? 'GET');
    $db = DB::open();
    $wantsJson = stripos($g->server['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    if ($method === 'POST') {
        $body = $g->post;
        $title    = (string) ($body['title'] ?? '');
        $bodyText = (string) ($body['body'] ?? '');
        $id = Notes::create($db, $u['user_id'], $title, $bodyText);
        if ($id === null) { $this->response($this->json(['error' => 'validation_failed']), 422); return; }
        WS::broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'create', 'id' => $id]);
        $note = Notes::read($db, $u['user_id'], $id);
        header('Content-Type: text/html; charset=utf-8');
        $this->response(App::renderToString('/components/_note_card', $note), 200);
        return;
    }

    // GET — list notes
    $notesList = Notes::list($db, $u['user_id']);
    header('Content-Type: text/html; charset=utf-8');
    if (empty($notesList)) {
        $this->response('&lt;p class="notes-empty"&gt;No notes yet. Add one above.&lt;/p&gt;', 200);
        return;
    }
    $html = '';
    foreach ($notesList as $n) {
        $html .= App::renderToString('/components/_note_card', $n);
    }
    $this->response($html, 200);
};</code></pre>
    <p>The key line: <code>App::renderToString('/components/_note_card', $note)</code> renders the card you created above and returns it as a string &mdash; exactly what htmx expects as the response body.</p>

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

    <h3>Create the note card component</h3>
    <p>
      Each note renders as a card. This component is used in three places: the notes list (GET), the create response (POST), and the chat history bubbles (<a href="/learn/ai-chat">Lesson 20, AI Chat</a>). Same file, three consumers.
    </p>
    <p><strong>Create <code>template/components/_note_card.php</code>:</strong></p>
    <pre><code class="language-php">&lt;?php
$id    = (int)($id ?? 0);
$title = (string)($title ?? '');
$body  = (string)($body ?? '');
$ts    = (int)($updated_at ?? time());
?&gt;
&lt;article class="note" id="note-&lt;?= $id ?&gt;" data-id="&lt;?= $id ?&gt;"&gt;
  &lt;details&gt;
    &lt;summary class="note-title"&gt;&lt;?= htmlspecialchars($title) ?&gt;&lt;/summary&gt;
    &lt;p class="note-body"&gt;&lt;?= nl2br(htmlspecialchars($body)) ?&gt;&lt;/p&gt;
  &lt;/details&gt;
  &lt;div class="note-meta"&gt;
    &lt;span&gt;Updated &lt;?= date('Y-m-d H:i', $ts) ?&gt;&lt;/span&gt;
    &lt;button hx-delete="/api/learn/notes/&lt;?= $id ?&gt;"
            hx-target="#note-&lt;?= $id ?&gt;"
            hx-swap="outerHTML"
            hx-confirm="Delete this note?"&gt;Delete&lt;/button&gt;
  &lt;/div&gt;
&lt;/article&gt;</code></pre>

    <h2 id="step-sync">5. Live sync — cross-tab via WebSocket</h2>
    <p>
      Adding a note and seeing it appear in your own tab is just htmx swapping HTML. The harder
      problem is: <strong>another tab is open on the same account</strong> — maybe on the AI Chat
      page, maybe in a popup, maybe on a phone — and it should also reflect the change without the
      user reloading anything.
    </p>
    <p>
      The Notes API does it by broadcasting a <code>note_changed</code> event over WebSocket every
      time a note is created, updated, or deleted. Every connected tab on that user&rsquo;s account
      receives the event and calls <code>htmx.ajax('GET', '/api/learn/notes', '#notes-list')</code>
      to refresh its list. One-liner on the client, one broadcast on the server.
    </p>
    <pre><code class="language-php">// api/learn/notes.php — inside the POST handler, after the INSERT
learn_ws_broadcast($userId, ['type' =&gt; 'note_changed', 'op' =&gt; 'create', 'id' =&gt; $id]);

// public/js/learn.js — inside the WebSocket onmessage
if (msg.type === 'note_changed') {
    htmx.ajax('GET', '/api/learn/notes', { target: '#notes-list', swap: 'innerHTML' });
}</code></pre>
    <p>
      The next lesson — <a href="/learn/websocket">Lesson 19, Real-Time Sync</a> — walks through
      how <code>learn_ws_broadcast</code> iterates the <code>Store</code> table of connected fds
      and filters by <code>user_id</code> (the tic-tac-toe lesson later applies the same
      broadcaster shape with a different filter key: <code>room</code>).
    </p>

    <h2 id="step-tryit">6. Try it — the live widget</h2>
    <?php if (!$user): ?>
      <?php App::render('/components/_callout', [
        'variant' => 'warn',
        'title'   => 'Log in first',
        'body'    => '<p>Sign in at <a href="#step-auth">step 3</a> to render the widget here.</p>',
      ]); ?>
    <?php else: ?>
      <?php App::render('/components/_notes_widget', ['user' => $user]); ?>
      <a class="lesson-popout-cta" href="/demo/view/notes/widget" target="_blank" rel="noopener">
        Open this widget in a new tab ↗
      </a>
      <?php App::render('/components/_callout', [
        'variant' => 'success',
        'title'   => 'Watch what happens',
        'body'    => '<p><strong>Create a note</strong> above and watch the card slide in with a green glow. Now <strong>open the widget in a new tab</strong> via the popout link, delete a note in one — the other updates instantly via WebSocket. Same widget partial, two consumers, the cross-tab sync just works.</p>',
      ]); ?>
    <?php endif; ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'CRUD is four verbs: Create, Read, Update, Delete — every data app follows this pattern',
      '<code>App::renderToString()</code> returns HTML as a string for htmx fragment responses',
      'Four htmx attributes replace 30+ lines of JavaScript for form submission',
      'User-scoped queries (<code>WHERE user_id = ?</code>) ensure data isolation',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/auth"
         hx-get="/api/learn/page?slug=learn/auth" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/auth">← User Accounts</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/websocket"
         hx-get="/api/learn/page?slug=learn/websocket" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/websocket">Real-Time Sync →</a>
    </div>
  </article>
</div>
