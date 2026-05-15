<?php use ZealPHP\App;
$user = function_exists('learn_current_user') ? learn_current_user() : null;
$active = $active ?? 'learn/sessions';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 6,
      'title'    => 'Sessions & Auth',
      'subtitle' => 'session_start() just works. Build a real auth flow on top, backed by SQLite.',
      'prev'     => ['slug' => 'learn/routing', 'title' => 'Routing'],
      'next'     => ['slug' => 'learn/htmx', 'title' => 'Add htmx'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Use $_SESSION exactly like in classic PHP — uopz makes it work async',
      'Hash passwords with password_hash, verify with password_verify',
      'Open a SQLite DB lazily and cache the PDO connection per worker',
      'Read the live source code that powers this site\'s auth',
    ]]); ?>

    <h2>Sessions, the classic way</h2>
    <p>OpenSwoole doesn't ship traditional <code>$_SESSION</code> — every request is a coroutine, not a separate process. ZealPHP solves that with a uopz-driven override: <code>session_start()</code> works exactly as you remember it, but reads/writes a per-coroutine context instead of polluting global state.</p>
    <pre><code>// Anywhere in a route handler:
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['favourite_colour'] = 'amber';
header('Content-Type: text/plain');
return 'Saved: ' . $_SESSION['favourite_colour'];</code></pre>
    <p>Session files land in <code>.sessions/</code> by default. The session cookie is sent automatically.</p>

    <h2>Building real auth</h2>
    <p>This site uses one ZealPHP route, one SQLite database, and two PHP functions to provide register / login / logout. Here's the full code — same file <code>route/learn.php</code> running right now:</p>

    <pre><code>// Hashed-password registration
function learn_register_user(\PDO $db, string $username, string $password): ?int {
    if (!learn_validate_username($username)) return null;
    if (!learn_validate_password($password)) return null;
    try {
        $stmt = $db-&gt;prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
        $stmt-&gt;execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
        return (int)$db-&gt;lastInsertId();
    } catch (\PDOException $e) {
        return null; // UNIQUE constraint — username taken
    }
}

// Verify on login
function learn_login_user(\PDO $db, string $username, string $password): ?int {
    $stmt = $db-&gt;prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt-&gt;execute([$username]);
    $user = $stmt-&gt;fetch();
    if (!$user) return null;
    if (!password_verify($password, $user['password_hash'])) return null;
    return (int)$user['id'];
}</code></pre>

    <h2>Bootstrapping the SQLite database</h2>
    <p>One helper opens the database, runs idempotent <code>CREATE TABLE IF NOT EXISTS</code>, and caches the PDO connection per worker so subsequent requests hit a warm handle:</p>
    <pre><code>function learn_db_open(): \PDO {
    static $cache = [];
    $path = learn_db_path();
    if (isset($cache[$path])) return $cache[$path];
    $pdo = new \PDO('sqlite:' . $path);
    $pdo-&gt;setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo-&gt;query('PRAGMA journal_mode = WAL');
    $pdo-&gt;query('PRAGMA foreign_keys = ON');
    $pdo-&gt;query("CREATE TABLE IF NOT EXISTS users (
      id            INTEGER PRIMARY KEY AUTOINCREMENT,
      username      TEXT UNIQUE NOT NULL,
      password_hash TEXT NOT NULL,
      created_at    INTEGER NOT NULL
    )");
    $cache[$path] = $pdo;
    return $pdo;
}</code></pre>

    <?php App::render('/components/_tryit', ['title' => 'Try it now — register and stay logged in', 'body' => $user
      ? '<p>You\'re already logged in as <strong>' . htmlspecialchars($user['username']) . '</strong>.</p><p>Visit <a href="/learn/notes">/learn/notes</a> to see your notes, or <a href="/api/learn/logout">log out</a>.</p>'
      : '<p>Pick a username and password. The page rerenders with your logged-in state — same form, different branch.</p><div class="auth-card"><form method="post" action="/api/learn/register"><input type="text" name="username" placeholder="username" required minlength="3" maxlength="64"><input type="password" name="password" placeholder="password (≥ 8)" required minlength="8"><button type="submit">Register</button></form></div>'
    ]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => 'Why uopz?',
      'body'  => '<p>The framework replaces <code>session_start</code>, <code>session_destroy</code>, <code>setcookie</code>, <code>http_response_code</code>, and friends at boot time using the <code>uopz</code> extension. Each becomes per-coroutine-safe without changing your code. <code>$_SESSION</code> writes go to the request\'s context object; the session file is persisted at end of request.</p>',
    ]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/routing">← Routing</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/htmx">Add htmx →</a>
    </div>
  </article>
</div>
