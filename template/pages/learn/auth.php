<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/auth';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 8,
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
      You need a place to store users. SQLite is perfect for this &mdash; it's a database in a single file.
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
      whether a new password matches the one inside &mdash; that's <code>password_verify()</code>.
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
      If it matches, store the user ID in the session &mdash; now the server knows who you are on every request.
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
      Notice how the auth logic lives in <a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/Auth.php" target="_blank"><code>src/Learn/Auth.php</code></a> &mdash; a proper class, autoloaded
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
      the class does the work. This pattern scales &mdash; your API files stay under 20 lines each.
    </p>

    <?php App::render('/components/_tryit', ['title' => 'Register now', 'body' => $user
      ? '<p>You\'re logged in as <strong>' . htmlspecialchars($user['username']) . '</strong>. <a href="/api/learn/logout">Log out</a> to try registering a new account, or head to <a href="/learn/notes">Lesson 8</a> to start building notes.</p>'
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
      'title' => 'Challenge: change password',
      'body'  => '<p>Build a "change password" feature. You\'ll need: a form with old password and new password fields, an endpoint that verifies the old password with <code>password_verify()</code>, then updates the hash with <code>password_hash()</code>.</p>',
      'hints' => [
        'Use a prepared UPDATE statement: <code>UPDATE users SET password_hash = ? WHERE id = ?</code>',
        'Always verify the old password first &mdash; never trust the client to send only valid requests',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'SQLite + PDO gives you a full database in a single file &mdash; no server setup',
      '<code>password_hash()</code> and <code>password_verify()</code> handle passwords safely',
      'Store the user ID in <code>$g->session</code> after login &mdash; the session cookie handles the rest',
      'Business logic in <code>src/</code> classes, thin endpoint wrappers in <code>api/</code>',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/sessions"
         hx-get="/api/learn/page?slug=learn/sessions" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/sessions">&larr; Sessions</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/notes"
         hx-get="/api/learn/page?slug=learn/notes" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/notes">Personal Notes &rarr;</a>
    </div>
  </article>
</div>
