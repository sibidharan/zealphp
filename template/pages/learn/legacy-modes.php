<?php use ZealPHP\App; $active = $active ?? 'learn/legacy-modes'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 26,
      'title'    => 'Lifecycle Modes & Legacy Apps',
      'subtitle' => 'The four runtime modes, when each one fits, and an honest look at why coroutine-legacy is still experimental.',
      'prev'     => ['slug' => 'learn/async',       'title' => 'Async Patterns'],
      'next'     => ['slug' => 'learn/deployment',  'title' => 'Ship It'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'The four modes <code>App::mode()</code> selects — and the one knob that distinguishes them',
      'A decision guide: which mode for new apps, Symfony/Laravel, and unmodified WordPress',
      'What <code>coroutine-legacy</code> actually does — and what it requires (ext-zealphp)',
      'Why <code>coroutine-legacy</code> is flagged <strong>experimental</strong> right now — the real reasons',
      'The dividing line: Composer/PSR-4 apps work; pure <code>require_once</code> apps belong in <code>legacy-cgi</code>',
    ]]); ?>

    <h2>One call picks the runtime: <code>App::mode()</code></h2>
    <p>
      Everything about how a request is handled &mdash; whether <code>$_GET</code>/<code>$_SESSION</code> are real,
      whether requests run concurrently, whether each <code>.php</code> file gets its own process &mdash; is decided
      by a single call near the top of <code>app.php</code>. The trade-off behind every mode is the same one you met
      in <a href="/learn/mental-model">Lesson&nbsp;3</a>: <strong>process-wide superglobals are not safe across
      concurrent coroutines</strong>. Each mode resolves that tension differently.
    </p>

    <table class="ztable">
      <tr>
        <th>Mode</th>
        <th>Superglobals</th>
        <th>Concurrency</th>
        <th>Best for</th>
      </tr>
      <tr>
        <td><code>App::mode('coroutine')</code><br><span class="muted">the default</span></td>
        <td>Off &mdash; use <code>$g-&gt;get</code> / <code>$g-&gt;session</code></td>
        <td>✅ Full coroutine concurrency</td>
        <td>New apps. Fastest, cleanest, real in-request parallelism.</td>
      </tr>
      <tr>
        <td><code>App::mode('mixed')</code></td>
        <td>✅ Real <code>$_GET</code>/<code>$_SESSION</code></td>
        <td>Sequential (one request at a time per worker)</td>
        <td>Symfony / Laravel bridges &mdash; the stable PHP-FPM drop-in.</td>
      </tr>
      <tr>
        <td><code>App::mode('legacy-cgi')</code></td>
        <td>✅ Real, fully isolated per process</td>
        <td>Sequential, process-per-file (warm pool, ~1&ndash;3&nbsp;ms)</td>
        <td>Unmodified WordPress / Drupal &mdash; maximum compatibility.</td>
      </tr>
      <tr>
        <td><code>App::mode('coroutine-legacy')</code><br><span class="muted">experimental</span></td>
        <td>✅ Real, isolated per coroutine</td>
        <td>✅ Full coroutine concurrency</td>
        <td>Composer/PSR-4 legacy apps that want real superglobals <em>and</em> concurrency.</td>
      </tr>
    </table>

    <p>
      The first three are stable and well-trodden. <code>mixed</code> is the closest apples-to-apples PHP-FPM
      replacement; <code>legacy-cgi</code> is the conservative choice for apps that assume &ldquo;this script runs
      alone.&rdquo; The fourth, <code>coroutine-legacy</code>, is the ambitious one &mdash; and the rest of this
      lesson is an honest look at it.
    </p>

    <h2>What <code>coroutine-legacy</code> is trying to do</h2>
    <p>
      It is a <strong>compatibility runtime</strong>: it lets traditional request-style PHP &mdash; the PHP-FPM
      &ldquo;fresh state per request&rdquo; mental model &mdash; run under OpenSwoole coroutine concurrency, with
      <em>every request-state primitive isolated per coroutine</em>. Real <code>$_GET</code>, <code>$_POST</code>,
      <code>$_SESSION</code>, <code>$GLOBALS</code>, function-local <code>static $x</code>, and
      <code>require_once</code> state all behave as if each request had its own process &mdash; while dozens of
      requests run concurrently on one worker.
    </p>
    <p>
      It pulls this off with <strong>ext-zealphp</strong>, a small C extension that hooks OpenSwoole&rsquo;s
      scheduler and snapshots/restores per-coroutine state across every yield. <code>App::mode('coroutine-legacy')</code>
      auto-enables the whole isolation stack:
    </p>

    <?php App::render('/components/_code', [
      'label' => 'app.php — coroutine-legacy is one call (ext-zealphp required)',
      'code'  => <<<'PHP'
use ZealPHP\App;

// Requires ext-zealphp: pie install sibidharan/ext-zealphp
App::mode(App::MODE_COROUTINE_LEGACY);
// equivalent to: superglobals(true) + isolation(coroutine) + silentRedeclare(true)
//                + includeIsolation(true) + coroutineGlobalsIsolation(true)
//                + coroutineStaticsIsolation(true)

$app = App::init('0.0.0.0', 8080);
$app->run();
PHP
    ]); ?>

    <?php App::render('/components/_callout', [
      'variant' => 'warning',
      'title'   => 'Why it is experimental — the honest version',
      'body'    => '<p>coroutine-legacy works today for a <strong>well-defined class of apps</strong>, with caveats and open issues on the hardest targets. The reasons it carries the experimental flag:</p>
        <ol>
          <li><strong>It needs a C extension.</strong> The isolation runtime depends on <code>ext-zealphp</code> being compiled and loaded. The other three modes run on stock PHP.</li>
          <li><strong>&ldquo;Old PHP just works&rdquo; is <em>conditional</em>.</strong> Request <em>state</em> isolates transparently, but <em>class loading</em> does not. A class with <code>extends</code>/<code>implements</code> first compiled while several coroutines overlap (the first cold concurrent wave) can land present-but-<em>unlinked</em> for a moment &rarr; intermittent <code>Class not found</code> 500s. The honest promise is &ldquo;runs concurrently <em>provided its class graph is warmed before concurrency hits it</em>&rdquo; (via <code>App::preloadClassmap()</code>).</li>
          <li><strong>Open frontier issues on unmodified WordPress.</strong> A <code>$wpdb</code> connection-teardown crash and a bounded per-worker memory leak under <code>require_once</code> re-execution are still being worked at the extension level. Composer apps don&rsquo;t hit these; classic <code>require_once</code> WordPress does.</li>
          <li><strong>opcache rebinding.</strong> A warm opcache can fight the per-request class re-declaration (&ldquo;Cannot redeclare class&rdquo;) unless you set <code>opcache.dups_fix=1</code> (and, for the function case, use the patched opcache the Docker image ships).</li>
          <li><strong>It is young and moving fast.</strong> The isolation stack has shipped a dozen memory-safety fixes in a matter of weeks &mdash; stabilising, but not yet &ldquo;set and forget.&rdquo;</li>
        </ol>',
    ]); ?>

    <h2>The dividing line: how is your app&rsquo;s class graph loaded?</h2>
    <p>
      The single best predictor of whether <code>coroutine-legacy</code> will be smooth is <strong>how your app loads
      its classes</strong> &mdash; because that determines whether the class graph can be warmed before concurrency
      arrives.
    </p>

    <table class="ztable">
      <tr><th>Your app</th><th>Class loading</th><th>Recommendation</th></tr>
      <tr>
        <td>Symfony, Laravel, Slim, any modern Composer app</td>
        <td>PSR-4 autoload (each class <code>require</code>d once per worker)</td>
        <td>✅ <code>coroutine-legacy</code> works &mdash; warm with <code>App::preloadClassmap()</code> (run <code>composer dump-autoload --optimize</code>). Validated across a 12-app sweep.</td>
      </tr>
      <tr>
        <td>Unmodified WordPress, Drupal&nbsp;7, phpBB</td>
        <td>Pure <code>require_once</code> bootstrap, no autoloader</td>
        <td>⚠️ Use <code>legacy-cgi</code> instead. It is process-isolated, has <em>no</em> coroutine race at all, and is the correct race-free home for these apps today.</td>
      </tr>
      <tr>
        <td>Brand-new ZealPHP app</td>
        <td>n/a &mdash; you write <code>$g-&gt;get</code> code</td>
        <td>✅ Plain <code>coroutine</code> mode. You don&rsquo;t need any of this.</td>
      </tr>
    </table>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Rule of thumb',
      'body'    => '<p>Reach for the simplest mode that fits: <strong><code>coroutine</code></strong> for new code, <strong><code>mixed</code></strong> for a Composer legacy app that just needs real <code>$_SESSION</code> (no concurrency-inside-a-request), <strong><code>legacy-cgi</code></strong> for unmodified WordPress/Drupal, and <strong><code>coroutine-legacy</code></strong> only when a Composer-based legacy app genuinely needs <em>both</em> real superglobals <em>and</em> coroutine concurrency &mdash; and you can warm its class graph. When in doubt, <code>mixed</code> is the stable PHP-FPM equivalent.</p>',
    ]); ?>

    <?php App::render('/components/_concept_check', [
      'id'       => 'legacymodes1',
      'question' => 'You want to run an <strong>unmodified WordPress</strong> install (pure <code>require_once</code> bootstrap, no autoloader) on ZealPHP today. Which mode?',
      'correct'  => 'c',
      'explain'  => 'Classic WordPress loads its class graph via <code>require_once</code> with no autoloader, so it can\'t be pre-warmed &mdash; which is exactly the condition <code>coroutine-legacy</code> needs. <code>legacy-cgi</code> runs each request in an isolated process (warm pool), so there is no coroutine race at all. It\'s the race-free home for unmodified WordPress/Drupal. Reach for <code>coroutine-legacy</code> only for Composer/PSR-4 apps whose class graph you can warm.',
      'options'  => [
        'a' => '<code>coroutine</code> — it\'s the fastest, so always start there.',
        'b' => '<code>coroutine-legacy</code> — it keeps real superglobals and runs concurrently.',
        'c' => '<code>legacy-cgi</code> — process-isolated, no coroutine race, built for this.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'One call &mdash; <code>App::mode()</code> &mdash; selects the runtime; the axis behind every mode is the superglobals-vs-concurrency trade-off.',
      '<code>coroutine</code> (default) for new apps; <code>mixed</code> for stable PHP-FPM-style Composer legacy; <code>legacy-cgi</code> for unmodified WordPress/Drupal.',
      '<code>coroutine-legacy</code> is the experimental compatibility runtime: real superglobals + coroutine concurrency, via per-coroutine isolation in <strong>ext-zealphp</strong> (required).',
      'It\'s experimental because it needs a C extension, the &ldquo;just works&rdquo; promise is conditional on warming the class graph, there are open WordPress-teardown issues, and opcache can fight class re-declaration.',
      'The dividing line is class loading: PSR-4/Composer apps can be warmed and work; pure <code>require_once</code> apps (classic WordPress) belong in <code>legacy-cgi</code> for now.',
      'When unsure, pick <code>mixed</code> &mdash; the stable apples-to-apples PHP-FPM swap.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/async"
         hx-get="/api/learn/page?slug=learn/async" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/async">← Async Patterns</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/deployment"
         hx-get="/api/learn/page?slug=learn/deployment" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/deployment">Ship It →</a>
    </div>
  </article>
</div>
