<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/auth';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 17,
      'title'    => 'User Accounts',
      'subtitle' => 'SQLite, password hashing, and an auth guard. Real accounts in 50 lines.',
      'prev'     => ['slug' => 'learn/sessions', 'title' => 'Sessions'],
      'next'     => ['slug' => 'learn/notes', 'title' => 'Personal Notes'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Store user data in SQLite with PDO',
      'Hash passwords properly (never store plaintext)',
      'Build register and login forms',
      'Guard pages so only logged-in users can access them',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      Sessions remember <em>this browser</em>, but they don't know <em>who</em> is using it. Anyone
      who opens your app can see anyone else's data. You need real user accounts: a username, a password,
      and a way to prove "I am who I say I am."
    </p>

    <h2>Step 1: A database</h2>
    <p>
      You need a place to store users. SQLite is perfect for this — it's a database in a single file.
      No server to install, no credentials to configure. PHP includes PDO (PHP Data Objects) for talking
      to databases.
    </p>
    <pre><code class="language-php">// <a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/DB.php" style="color:#f59e0b">src/Learn/DB.php</a> — open the database and create tables
$pdo = new \PDO('sqlite:' . __DIR__ . '/../../storage/learn.db');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->query('PRAGMA journal_mode = WAL');
$pdo->query('PRAGMA foreign_keys = ON');

$pdo->query("CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at    INTEGER NOT NULL
)");</code></pre>
    <p>
      <code>WAL</code> mode lets multiple coroutines read the database simultaneously (important for ZealPHP).
      <code>foreign_keys</code> enforces data integrity when we add notes in the next lesson.
    </p>

    <h2>Step 2: Register a user</h2>
    <p>
      The key insight: <strong>never store passwords as plaintext</strong>. PHP's <code>password_hash()</code>
      generates a one-way hash that can't be reversed. Even if someone steals your database, they can't
      read the passwords.
    </p>
    <pre><code class="language-php">// <a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/Auth.php" style="color:#f59e0b">src/Learn/Auth.php</a> — register
public static function register(\PDO $db, string $username, string $password): ?int
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$username, $hash, time()]);
    return (int) $db->lastInsertId();
}</code></pre>
    <p>
      Think of <code>password_hash()</code> like a <strong>safe with a one-way lock</strong>. You put the
      password in, the safe locks, and even you can't open it to see what's inside. But you can check
      whether a new password matches the one inside — that's <code>password_verify()</code>.
    </p>

    <h2>Step 3: Log in</h2>
    <pre><code class="language-php">// <a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/Auth.php#L30" style="color:#f59e0b">src/Learn/Auth.php</a> — login
public static function login(\PDO $db, string $username, string $password): ?int
{
    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    return (int) $user['id'];
}</code></pre>
    <p>
      <code>password_verify()</code> checks whether the password matches the hash without ever decrypting it.
      If it matches, store the user ID in the session — now the server knows who you are on every request.
    </p>

    <h2>Step 4: The auth guard</h2>
    <p>
      Any page that needs a logged-in user checks the session at the top:
    </p>
    <pre><code class="language-php">$user = Auth::currentUser();
if (!$user) {
    // Show login form
} else {
    // Show the protected content
}</code></pre>
    <p>
      <code>Auth::currentUser()</code> reads <code>$g->session['user_id']</code>, looks up the user in
      SQLite, and returns the user row or <code>null</code>. If the session has a stale user_id (e.g.,
      after a database reset), it returns <code>null</code> too.
    </p>

    <h2>Architecture: proper OOP</h2>
    <p>
      Notice how the auth logic lives in <a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/Auth.php" target="_blank"><code>src/Learn/Auth.php</code></a> — a proper class, autoloaded
      via Composer. The API endpoint (<a href="https://github.com/sibidharan/zealphp/blob/master/api/learn/register.php" target="_blank"><code>api/learn/register.php</code></a>) is a thin wrapper:
    </p>
    <pre><code class="language-php">// <a href="https://github.com/sibidharan/zealphp/blob/master/api/learn/register.php" style="color:#f59e0b">api/learn/register.php</a> — thin endpoint
$register = function () {
    $creds = Auth::readCredentials($this);
    $userId = Auth::register(DB::open(), $creds['username'], $creds['password']);
    // ... set session, redirect
};</code></pre>
    <p>
      Business logic in <code>src/</code>, endpoints in <code>api/</code>. The endpoint delegates;
      the class does the work. This pattern scales — your API files stay under 20 lines each.
    </p>

    <?php App::render('/components/_tryit', ['title' => 'Register now', 'body' => $user
      ? '<p>You\'re logged in as <strong>' . htmlspecialchars($user['username']) . '</strong>. <a href="/api/learn/logout">Log out</a> to try registering a new account, or head to <a href="/learn/notes">Lesson 18</a> to start building notes.</p>'
      : '<p>Pick a username and password. This creates a real account stored in SQLite. You\'ll use it in the next three lessons to save notes and chat with the AI.</p>
<div class="auth-card">
  <form hx-post="/api/learn/register" hx-target="#auth-feedback-reg" hx-swap="innerHTML">
    <input type="text" name="username" placeholder="username" required minlength="3" maxlength="64" autocomplete="username">
    <input type="password" name="password" placeholder="password (8+ chars)" required minlength="8" autocomplete="new-password">
    <button type="submit">Register</button>
    <div id="auth-feedback-reg"></div>
  </form>
  <details style="margin-top:.75rem"><summary>Already have an account?</summary>
    <form hx-post="/api/learn/login" hx-target="#auth-feedback-login" hx-swap="innerHTML" style="margin-top:.5rem">
      <input type="text" name="username" placeholder="username" required autocomplete="username">
      <input type="password" name="password" placeholder="password" required autocomplete="current-password">
      <button type="submit" class="auth-toggle">Log in</button>
      <div id="auth-feedback-login"></div>
    </form>
  </details>
</div>'
    ]); ?>

    <?php App::render('/components/_challenge', [
      'title' => 'Challenge 1: change password',
      'body'  => '<p>Build a "change password" feature. You\'ll need: a form with old password and new password fields, an endpoint that verifies the old password with <code>password_verify()</code>, then updates the hash with <code>password_hash()</code>.</p>',
      'hints' => [
        'Use a prepared UPDATE statement: <code>UPDATE users SET password_hash = ? WHERE id = ?</code>',
        'Always verify the old password first — never trust the client to send only valid requests',
      ],
    ]); ?>

    <?php App::render('/components/_challenge', [
      'title' => 'Challenge 2: rate-limit failed logins',
      'body'  => '<p>Right now <code>/api/learn/login</code> happily accepts unlimited password guesses. Add a per-IP rate limit: after 5 failed attempts in 60 seconds, return 429 for that IP for the next 5 minutes. Use <code>Store</code> for the counter — see Foundations &rarr; <a href="/learn/store">Sharing State</a> for the table-allocation pattern.</p>',
      'hints' => [
        'Allocate the table at boot: <code>Store::make(\'login_fails\', 10000, [\'count\' =&gt; [\OpenSwoole\Table::TYPE_INT, 4], \'reset_at\' =&gt; [\OpenSwoole\Table::TYPE_INT, 4]])</code>',
        'Key by <code>$request-&gt;server[\'REMOTE_ADDR\']</code>. Bump <code>count</code> only when the password check fails — never on success.',
        'On lockout, return <code>$response-&gt;status(429)-&gt;header(\'Retry-After\', \'300\')-&gt;end()</code>',
      ],
    ]); ?>

    <?php App::render('/components/_challenge', [
      'title' => 'Challenge 3: invalidate the session on logout',
      'body'  => '<p>The current <code>/api/learn/logout</code> probably just clears <code>$g-&gt;session[\'user_id\']</code>. That leaves the session file alive with whatever else was in it. Switch to <code>session_destroy()</code> and re-generate the session id on the user&rsquo;s next request, so the old <code>PHPSESSID</code> can\'t be replayed even if it leaked into a log.</p>',
      'hints' => [
        '<code>session_destroy()</code> wipes the server-side file but leaves <code>$_SESSION</code> alive in this request. Pair with <code>session_unset()</code>.',
        'On login (not logout) call <code>session_regenerate_id(true)</code> — that\'s where session fixation attacks land; <code>true</code> deletes the old file.',
        'Set the <code>PHPSESSID</code> cookie\'s <code>Max-Age</code> to <code>0</code> in the logout response so the browser drops its copy too.',
      ],
    ]); ?>

    <?php App::render('/components/_challenge', [
      'title' => 'Challenge 4: password reset via email (mocked)',
      'body'  => '<p>Build a password-reset flow. User enters an email; if it exists, you generate a single-use token, store it with a 30-min expiry, and "email" it (just log the URL for now). The reset link points at <code>/reset?token=...</code>. Submitting that page with a new password verifies the token, updates the hash, and invalidates the token.</p>',
      'hints' => [
        'Token storage: a <code>password_resets</code> table with columns <code>token</code> (unique), <code>user_id</code>, <code>expires_at</code>. Or use <code>Store::make(\'pw_resets\', 1024, [...])</code> for in-memory.',
        '<code>random_bytes(32)</code> + <code>bin2hex()</code> gives you a 64-char token. Constant-time compare on lookup.',
        '"Send the email" = <code>elog(\'Reset link: https://yourapp/reset?token=\' . $token)</code>. Wire real email later via <code>swiftmailer</code> or a transactional-mail API.',
        'Delete the token row the moment it\'s redeemed — never leave it around for replay.',
      ],
    ]); ?>

    <h2 id="wire-zealapi">Wire your auth into ZealAPI</h2>
    <p>
      <code>ZealAPI</code> handlers (the file-based API layer at <code>api/</code>) have built-in helpers for guarding endpoints — <code>$this-&gt;isAuthenticated()</code>, <code>$this-&gt;isAdmin()</code>, <code>$this-&gt;getUsername()</code>, and the composite <code>$this-&gt;requirePostAuth()</code>. But ZealPHP doesn't know what your auth system looks like, so by default these return <strong>fail-closed</strong> values (<code>false</code>, <code>false</code>, <code>null</code>). Endpoints guarded by <code>requirePostAuth()</code> reject everything until you wire the hooks up.
    </p>
    <p>
      Three one-liners in <code>app.php</code> tell ZealPHP how to consult <em>your</em> auth state. Configure once, every API handler downstream gets the answer:
    </p>

    <?php App::render('/components/_code', [
      'label' => 'app.php — wire ZealAPI to the Auth + Session classes we built above',
      'code'  => <<<'PHP'
<?php
use ZealPHP\App;
use App\Auth;          // the src/Auth.php class from Step 2

App::authChecker(fn(): bool       => Auth::currentUserId() !== null);
App::adminChecker(fn(): bool      => Auth::currentRole() === 'admin');
App::usernameProvider(fn(): ?string => Auth::currentUsername());

$app = App::init('0.0.0.0', 8080);
$app->run();
PHP,
    ]); ?>

    <p>Now any handler under <code>api/</code> can guard itself with one line:</p>

    <?php App::render('/components/_code', [
      'label' => 'api/notes/delete.php — guarded endpoint',
      'code'  => <<<'PHP'
<?php
// File: api/notes/delete.php → POST /api/notes/delete
$delete = function() {
    // POST + authenticated guard. Emits 403 JSON and returns false on failure.
    if (!$this->requirePostAuth()) return;

    $userId = \App\Auth::currentUserId();
    Note::delete((int)$_POST['note_id'], $userId);
    return ['ok' => true];
};
PHP,
    ]); ?>

    <p style="font-size:.9rem;color:var(--text-muted)">
      No subclassing, no monkey-patching <code>ZealAPI</code>. The framework asks your code at request time via a function pointer — adds no per-request cost when no checker is registered. Full surface: <a href="/api#auth-hooks">Pluggable auth hooks</a> on the API reference page.
    </p>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'SQLite + PDO gives you a full database in a single file — no server setup',
      '<code>password_hash()</code> and <code>password_verify()</code> handle passwords safely',
      'Store the user ID in <code>$g->session</code> after login — the session cookie handles the rest',
      'Business logic in <code>src/</code> classes, thin endpoint wrappers in <code>api/</code>',
      'Register <code>App::authChecker / adminChecker / usernameProvider</code> once in <code>app.php</code> to wire ZealAPI handlers to your auth code — no per-endpoint plumbing',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/sessions"
         hx-get="/api/learn/page?slug=learn/sessions" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/sessions">← Sessions</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/notes"
         hx-get="/api/learn/page?slug=learn/notes" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/notes">Personal Notes →</a>
    </div>
  </article>
</div>
