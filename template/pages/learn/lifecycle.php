<?php use ZealPHP\App; $active = $active ?? 'learn/lifecycle'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 7,
      'title'    => 'A Request\'s Journey',
      'subtitle' => 'A request walks into a server. Nine things happen before your code runs. Let\'s audit the trip.',
      'prev'     => ['slug' => 'learn/routes',            'title' => 'How Routes Work'],
      'next'     => ['slug' => 'learn/injection',         'title' => 'Parameter Injection'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'How the server boots — the one-time setup that happens before any request',
      'The nine steps a request goes through from socket to your handler',
      'Where per-request state actually lives (and why it\'s isolated)',
      'How middleware wraps a request (and why order is reversed at registration)',
      'What ResponseMiddleware does last that you never see',
    ]]); ?>

    <h2>Step 0 — the boot</h2>
    <p>
      Before any request, the server boots. <code>php app.php</code> runs your bootstrap top to
      bottom:
    </p>
    <pre><code class="language-php">$app = App::init('0.0.0.0', 8080);

$app->addMiddleware(new CorsMiddleware());      // registered (not running yet)
$app->addMiddleware(new ETagMiddleware());
$app->addMiddleware(new SessionStartMiddleware());

Store::make('rate_limits', 10000, [...]);       // shared memory allocated

$app->route('/health', fn() => ['ok' => true]); // route table populated

$app->run();                                     // ← OpenSwoole takes over here</code></pre>
    <p>
      At the moment <code>$app-&gt;run()</code> is called, the master process forks N workers (one
      per CPU core by default), each worker boots its own PHP runtime (autoloader, opcode cache),
      then sits in an event loop waiting for connections. The route table, middleware stack, and
      <code>Store</code> tables are inherited &mdash; <em>not re-computed per request</em>. Everything
      after this point is per-request work.
    </p>

    <h2>The trip, end to end</h2>
    <p>
      In Apache, a request's journey is short: php-fpm spawns a worker, the worker runs your
      script, the worker dies. ZealPHP's journey looks longer because the worker doesn't die,
      so the framework has to do per-request setup carefully — and just as carefully tear it down.
      Once you see the nine steps, the rest of the framework stops feeling magical.
    </p>

    <pre class="mermaid">sequenceDiagram
    participant B as Browser
    participant SW as OpenSwoole<br/>Server
    participant CO as Coroutine<br/>(spawned per request)
    participant CSM as CoSessionManager
    participant MW as Middleware stack
    participant RM as ResponseMiddleware
    participant H as Your handler
    B->>SW: HTTP request
    SW->>CO: spawn coroutine
    CO->>CSM: onRequest(req, res)
    CSM->>CSM: build RequestContext on<br/>Coroutine::getContext()
    CSM->>MW: handle(PSR-7 request)
    MW->>MW: CORS / ETag / Session / ...<br/>(first-registered runs first)
    MW->>RM: pass to innermost
    RM->>RM: match route, build param map
    RM->>H: invoke handler(...$injected)
    H-->>RM: return value
    RM-->>MW: wrap as PSR-7 response
    MW-->>SW: emit response
    SW-->>B: HTTP response</pre>

    <h2>The nine steps</h2>
    <ol>
      <li>
        <strong>OpenSwoole accepts the socket.</strong> The HTTP server you started with
        <code>$app-&gt;run()</code> is listening on port 8080. It parses the request line, headers,
        and body, then hands them to your worker process.
      </li>
      <li>
        <strong>A coroutine spawns.</strong> In coroutine mode, every request gets its own coroutine
        with its own <code>Coroutine::getContext()</code> bag — the per-request scratchpad that
        keeps your data from leaking into the next request.
      </li>
      <li>
        <strong>CoSessionManager runs.</strong> It's the registered <code>onRequest</code>
        handler. The framework's request closure builds a fresh <code>RequestContext</code>, attaches
        the request/response wrappers, and populates <code>$g-&gt;get</code>, <code>$g-&gt;post</code>,
        <code>$g-&gt;cookie</code>, <code>$g-&gt;server</code> on the per-coroutine context. In
        coroutine mode the real <code>$_GET</code>/<code>$_POST</code> superglobals are
        <em>not</em> populated — request input lives on <code>$g</code> only (populating
        process-wide superglobals would race across concurrent coroutines). From this point,
        <code>RequestContext::instance()</code> always returns the right object for the current
        coroutine — no globals, no cross-talk.
      </li>
      <li>
        <strong>The middleware stack engages.</strong> ZealPHP uses a PSR-15 middleware chain. CORS
        runs first, then ETag, then Range, then SessionStart… the outermost middleware gets the
        request first, passes it down, and processes the response on the way back up.
      </li>
      <li>
        <strong>Order matches registration.</strong> If you registered CORS first and ETag second,
        CORS still runs first at request time — the first middleware you add becomes the outermost
        wrapper. ZealPHP's internal double-reversal (wait-stack reverse + StackHandler prepend)
        makes the execution order match your registration order top-to-bottom. Register your
        outermost concerns (CORS, compression) first.
      </li>
      <li>
        <strong>ResponseMiddleware is the bottom of the stack.</strong> Every middleware eventually
        calls <code>$handler-&gt;handle($request)</code> on the next layer. The innermost handler is
        <code>ResponseMiddleware</code> — the one that actually finds your route.
      </li>
      <li>
        <strong>Routes match. Parameters resolve.</strong> ResponseMiddleware looks up your URI in the
        route table, finds the handler, and uses a parameter map built at registration time (via
        reflection, cached — no per-request reflection cost) to figure out which arguments your
        handler wants: <code>$request</code>, <code>$response</code>, <code>$app</code>, the captured
        <code>{id}</code> from the URL, anything else.
      </li>
      <li>
        <strong>Your handler runs.</strong> Whatever you return — an int, a string, an array, a
        Generator, void with <code>echo</code> — ResponseMiddleware translates into the right
        PSR-7 response. Streaming handlers skip the buffering step entirely.
      </li>
      <li>
        <strong>Response unwinds back up the stack.</strong> Each middleware that saw the request gets
        a turn with the response — ETag adds the cache header, compression compresses, CORS adds
        <code>Access-Control-*</code>, range middleware trims to the requested byte range. OpenSwoole
        emits the bytes. The coroutine ends. Your worker is ready for the next request.
      </li>
    </ol>

    <h2>Where state lives at each step</h2>
    <p>The persistent-vs-per-request distinction maps to three storage tiers:</p>
    <table class="cmp-table">
      <thead><tr><th>Tier</th><th>What lives there</th><th>Lifetime</th></tr></thead>
      <tbody>
        <tr>
          <td>Process</td>
          <td>Autoloader, route table, middleware stack, <code>Store</code>, <code>Counter</code>, WebSocket connections, timers</td>
          <td>Until the worker exits (or <code>max_request</code> recycles it)</td>
        </tr>
        <tr>
          <td>Coroutine</td>
          <td><code>RequestContext</code>, request/response objects, <code>$g-&gt;get</code> / <code>$g-&gt;post</code> / <code>$g-&gt;session</code> (superglobals are only populated in <code>superglobals(true)</code> mode)</td>
          <td>One request</td>
        </tr>
        <tr>
          <td>Session</td>
          <td>User-attached state (cart, login, preferences), keyed by cookie</td>
          <td>Until cookie expires or server cleans up the file</td>
        </tr>
      </tbody>
    </table>
    <p>
      Your handler can touch all three tiers freely. The only rule: don't put per-request data in
      a process-level static, and don't put process-level data in a per-request slot. The first
      causes data leaks between requests; the second wastes memory and gets discarded on the next
      request.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'The nine steps describe the default Coroutine isolation — other shapes exist',
      'body'    => '<p>The flow above uses <code>CoSessionManager</code> and per-coroutine <code>RequestContext</code> — that is <code>App::mode(App::MODE_COROUTINE)</code>, the recommended default for modern ZealPHP apps.</p><p>The isolation strategy is configurable via <code>App::mode()</code> and <code>App::isolation()</code>:</p><ul><li><code>App::mode(App::MODE_LEGACY_CGI)</code> — <code>SessionManager</code> + a pre-spawned subprocess pool (<code>CgiPool</code>, ~1–3 ms warm) for unmodified WordPress/Drupal-style PHP.</li><li><code>App::mode(App::MODE_COROUTINE_LEGACY)</code> <strong>(experimental)</strong> — coroutine concurrency <em>with</em> <code>superglobals(true)</code>; ext-zealphp isolates <code>$_GET</code>/<code>$_POST</code>/<code>$_SESSION</code>, <code>$GLOBALS</code>, function statics, and <code>require_once</code> per coroutine. (<code>define()</code> isolation is a separate opt-in via <code>App::defineIsolation(true)</code>.) Requires <code>ext-zealphp</code>; experimental because the &ldquo;just works&rdquo; promise is conditional on warming the class graph first, with open issues on unmodified <code>require_once</code> WordPress.</li><li><code>App::mode(App::MODE_MIXED)</code> — real <code>$_SESSION</code>, sequential workers, no CGI fork cost. Suits Symfony/Laravel bridges.</li></ul><p>See <a href="/learn/legacy-modes">Lesson&nbsp;26: Lifecycle Modes &amp; Legacy Apps</a> for the decision guide and the honest why-experimental breakdown, or the <a href="/coroutines#lifecycle-modes">lifecycle modes reference</a> for the full matrix.</p>',
    ]); ?>

    <?php App::render('/components/_callout', [
      'variant' => 'deep',
      'title'   => 'Why the reversal at registration?',
      'body'    => '<p>If you write <code>addMiddleware(A); addMiddleware(B); addMiddleware(C);</code> the stack is built as <code>A wraps B wraps C wraps ResponseMiddleware</code>, so A runs <em>first</em> — it is the outermost layer. The internal double-reversal in ZealPHP (the wait-stack is reversed before being handed to StackHandler, which prepends each entry) means the two reversals cancel out and execution order matches registration order top-to-bottom. Register your outermost concerns (CORS, compression) <em>first</em>; register your inner defaults (sessions, range) after.</p>',
    ]); ?>

    <h2>What never happens (the Apache trauma list)</h2>
    <p>
      Things you don't need to worry about in this lifecycle — that you might have spent
      years working around on Apache or php-fpm:
    </p>
    <ul>
      <li>The autoloader doesn't rebuild. It's already loaded in the worker.</li>
      <li>The opcode cache doesn't cold-start. Your scripts are already compiled.</li>
      <li>The DB connection doesn't reopen per request (if you use a pool). You hold it across requests.</li>
      <li>The framework doesn't re-parse routes. The route table is in memory.</li>
      <li>The worker doesn't fork. The coroutine spawns and parks — no <code>pcntl_fork</code>.</li>
    </ul>
    <p>
      That's the cost ZealPHP buys back. The nine-step journey above is what it spends to keep
      the cost down to memory access and a coroutine spawn — not 50 ms of boot.
    </p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'lifecycle1',
      'question' => 'You register middleware in this order: CORS, ETag, SessionStart. Which one runs first at request time?',
      'correct'  => 'c',
      'explain'  => 'ZealPHP\'s internal double-reversal makes the FIRST middleware you add the outermost. CORS was registered first, so it wraps everything and runs first; SessionStart was registered last, so it sits closest to the handler. CORS is the first to see the inbound request and the last to touch the outbound response.',
      'options'  => [
        'a' => 'SessionStart, because it was registered last.',
        'b' => 'ETag, because it sits in the middle.',
        'c' => 'CORS, because the first-registered middleware becomes the outermost wrapper.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Nine steps: OpenSwoole → coroutine → CoSessionManager → middleware stack → ResponseMiddleware → handler → response unwind → emit → coroutine ends.',
      'Per-request state lives on <code>Coroutine::getContext()</code> — never on globals you control.',
      'Middleware is registered "outermost first" — the first-added middleware runs first at request time.',
      '<code>ResponseMiddleware</code> is always the innermost layer; it matches routes and resolves handler parameters by name.',
      'The lifecycle is short by design: every step that <em>could</em> happen per-request was moved to startup.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/routes"
         hx-get="/api/learn/page?slug=learn/routes" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/routes">← How Routes Work</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/injection"
         hx-get="/api/learn/page?slug=learn/injection" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/injection">Parameter Injection →</a>
    </div>
  </article>
</div>
