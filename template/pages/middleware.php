<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>
<section class="section">
<div class="container">
<h1 class="section-title">Middleware</h1>
<p class="section-desc">ZealPHP uses PSR-15 middleware. Add with <code>$app->addMiddleware()</code>. The last added runs outermost (first to process request, last to process response). Middleware always returns a <code>Psr\Http\Message\ResponseInterface</code> — handlers inside use the <a href="/responses#return-contract">universal return contract</a>; <code>ResponseMiddleware</code> coerces handler returns to PSR-7 before your middleware sees them.</p>

<h2 class="mw-h2">Built-in middleware</h2>
<table class="ztable mw-table">
  <tr><th>Class</th><th>Apache / nginx parity</th><th>What it does</th></tr>
  <tr><td><a href="#cors"><code>CorsMiddleware</code></a></td><td>n/a (modern browser feature)</td><td>CORS preflight + Access-Control headers on every response</td></tr>
  <tr><td><a href="#etag"><code>ETagMiddleware</code></a></td><td>Apache <code>FileETag</code>, nginx <code>etag on</code></td><td>Generates <code>W/"md5"</code> ETag, returns 304 on cache hit</td></tr>
  <tr><td><a href="#compression"><code>CompressionMiddleware</code></a></td><td>Apache <code>mod_deflate</code>, nginx <code>gzip on</code></td><td>Reference gzip/deflate; runtime compression is handled by OpenSwoole by default</td></tr>
  <tr><td><a href="#range"><code>RangeMiddleware</code></a></td><td>HTTP/1.1 RFC 7233 (universally expected)</td><td><code>Accept-Ranges: bytes</code>; 206 for single/multi-range; 416 for unsatisfiable</td></tr>
  <tr><td><a href="#session-start"><code>SessionStartMiddleware</code></a></td><td>n/a (PHP-native sessions)</td><td>Eagerly starts a session and sends <code>Set-Cookie</code> for new visitors</td></tr>
  <tr><td><a href="#ini-isolation"><code>IniIsolationMiddleware</code></a></td><td>n/a (long-running runtime concern)</td><td>Snapshots and restores <code>ini_set()</code> changes per request</td></tr>
  <tr><td><a href="#charset"><code>CharsetMiddleware</code></a></td><td>Apache <code>AddDefaultCharset</code> / <code>AddCharset</code></td><td>Appends <code>; charset=utf-8</code> to text-ish response <code>Content-Type</code></td></tr>
  <tr><td><a href="#cache-control"><code>CacheControlMiddleware</code></a></td><td>Apache <code>&lt;FilesMatch&gt; Header set Cache-Control</code></td><td>Extension-keyed <code>Cache-Control: max-age=N, public</code> for static assets</td></tr>
  <tr><td><a href="#expires"><code>ExpiresMiddleware</code></a></td><td>Apache <code>mod_expires</code>, nginx <code>expires 30d</code></td><td>Adds <code>Expires:</code> header by content type</td></tr>
  <tr><td><a href="#header"><code>HeaderMiddleware</code></a></td><td>Apache <code>mod_headers</code> (<code>Header set/add/unset</code>)</td><td>Declarative response-header manipulation with conditional variants</td></tr>
  <tr><td><a href="#basic-auth"><code>BasicAuthMiddleware</code></a></td><td>Apache <code>AuthType Basic</code>, nginx <code>auth_basic</code></td><td>HTTP Basic Auth: htpasswd file or callback verifier</td></tr>
  <tr><td><a href="#ip-access"><code>IpAccessMiddleware</code></a></td><td>Apache <code>Allow from / Deny from</code></td><td>CIDR allow/deny lists with allow-first or deny-first ordering</td></tr>
  <tr><td><a href="#rate-limit"><code>RateLimitMiddleware</code></a></td><td>nginx <code>limit_req</code></td><td>Sliding-window request rate limiter backed by <code>Store</code> (cross-worker)</td></tr>
  <tr><td><a href="#concurrency-limit"><code>ConcurrencyLimitMiddleware</code></a></td><td>nginx <code>limit_conn</code></td><td>In-flight concurrent-request cap backed by <code>Counter</code></td></tr>
  <tr><td><a href="#block-php-ext"><code>BlockPhpExtMiddleware</code></a></td><td>Apache <code>RewriteRule ^(.+)\.php$ - [F]</code></td><td>Refuses <code>*.php</code> URLs with 404 (for extensionless-only public surfaces)</td></tr>
  <tr><td><a href="#mime-type"><code>MimeTypeMiddleware</code></a></td><td>Apache <code>AddType</code> / <code>ForceType</code></td><td>Sets/overrides <code>Content-Type</code> on non-static responses by extension or pattern</td></tr>
  <tr><td><a href="#body-rewrite"><code>BodyRewriteMiddleware</code></a></td><td>Apache <code>mod_substitute</code> (<code>Substitute s/x/y/</code>)</td><td>Single-line regex substitution on response body</td></tr>
  <tr><td><a href="#host-router"><code>HostRouterMiddleware</code></a></td><td>nginx <code>server_name a.com b.com</code></td><td>Dispatches per-host routes inside one ZealPHP instance</td></tr>
  <tr><td><a href="#content-encoding"><code>ContentEncodingMiddleware</code></a></td><td>Apache <code>mod_mime AddEncoding</code></td><td>Sets <code>Content-Encoding</code> from URL file suffixes (e.g. <code>.gz</code>, <code>.br</code>)</td></tr>
  <tr><td><a href="#content-language"><code>ContentLanguageMiddleware</code></a></td><td>Apache <code>mod_mime AddLanguage</code></td><td>Sets <code>Content-Language</code> from URL file suffixes (e.g. <code>.en</code>, <code>.fr</code>)</td></tr>
  <tr><td><a href="#merge-slashes"><code>MergeSlashesMiddleware</code></a></td><td>Apache <code>MergeSlashes On</code>, nginx <code>merge_slashes</code></td><td>Collapses runs of consecutive slashes in the request path before routing</td></tr>
  <tr><td><a href="#request-header"><code>RequestHeaderMiddleware</code></a></td><td>Apache <code>mod_headers RequestHeader</code></td><td><code>set</code> / <code>append</code> / <code>unset</code> on inbound request headers before handlers run</td></tr>
  <tr><td><a href="#return"><code>ReturnMiddleware</code></a></td><td>nginx <code>return</code> directive</td><td>Unconditionally returns a fixed response — pair with <code>ScopedMiddleware</code></td></tr>
  <tr><td><a href="#scoped"><code>ScopedMiddleware</code></a></td><td>Apache <code>&lt;Location&gt;</code> / <code>&lt;LocationMatch&gt;</code> containers</td><td>Apply another middleware only to matching request paths</td></tr>
  <tr><td><a href="#set-env-if"><code>SetEnvIfMiddleware</code></a></td><td>Apache <code>mod_setenvif</code> / <code>BrowserMatch</code></td><td>Set request "env" vars in <code>$g->server</code> when a request attribute matches a regex</td></tr>
  <tr><td><a href="#body-size-limit"><code>BodySizeLimitMiddleware</code></a></td><td>nginx <code>client_max_body_size</code>, Apache <code>LimitRequestBody</code></td><td>Rejects oversized request bodies with <code>413 Content Too Large</code></td></tr>
  <tr><td><a href="#redirect"><code>RedirectMiddleware</code></a></td><td>Apache <code>mod_alias</code> (<code>Redirect</code> / <code>RedirectMatch</code>)</td><td>Declarative URL redirects — prefix and regex rules, first match short-circuits</td></tr>
  <tr><td><a href="#referer"><code>RefererMiddleware</code></a></td><td>nginx <code>valid_referers</code> / <code>$invalid_referer</code></td><td>Hotlink protection — refuses requests whose <code>Referer</code> is not in the allowed set</td></tr>
  <tr><td><a href="#csrf"><code>CsrfMiddleware</code></a></td><td>n/a (framework-level)</td><td>Double-submit CSRF protection for state-mutating requests</td></tr>
  <tr><td><a href="#health-check"><code>HealthCheckMiddleware</code></a></td><td>n/a (ops concern)</td><td>Short-circuits on health-check paths (default <code>/healthz</code>); returns 200/503 JSON</td></tr>
</table>

<?php
App::render('/components/_code', [
    'label' => 'app.php — middleware registration order',
    'code'  => <<<'PHP'
$app->addMiddleware(new CorsMiddleware());         // outermost — handles preflight
$app->addMiddleware(new ETagMiddleware());         // generates ETag
$app->addMiddleware(new AuthMiddleware());         // your custom middleware
// ResponseMiddleware is always innermost (built-in)
PHP]);
?>

<p class="mw-note">Server-level Apache directives map to <code>App::$*</code> static properties + fluent setters (e.g., <code>App::clientIp()</code>, <code>App::canonicalHost()</code>, <code>App::$trusted_proxies</code>, <code>App::$access_log_format</code>). See <a href="/legacy-apps">legacy-apps</a> for the full server-level configurability matrix.</p>

<h2 class="mw-h2-demos">Live demos</h2>
<?php
$demos = [
  ['mw-cors', 'CORS — Access-Control-Allow-Origin on every response', '/demo/middleware/cors',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Add middleware once in app.php:
$app->addMiddleware(new CorsMiddleware(['*']));

// Hit any endpoint with Origin header:
// curl -H "Origin: http://app.test" __SITE_URL__/demo/middleware/cors
// → Access-Control-Allow-Origin: *
PHP)],
  ['mw-etag', 'ETag / 304 — conditional GET', '/demo/middleware/etag',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// ETagMiddleware auto-generates W/"md5(body)" on GET
// Second request with If-None-Match: <etag> → 304 Not Modified

// First hit:
// curl -D - __SITE_URL__/http/etag-test
// → ETag: W/"abc..."
// Second hit:
// curl -H 'If-None-Match: W/"abc..."' __SITE_URL__/http/etag-test
// → HTTP/1.1 304 Not Modified (empty body)
PHP)],
  ['mw-comp', 'Compression — gzip when Accept-Encoding: gzip', '/demo/middleware/compress',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// OpenSwoole handles runtime compression by default.
// Keep CompressionMiddleware only as a reference if you disable http_compression.
// curl --compressed __SITE_URL__/http/compress-test
// → Content-Encoding: gzip  (body is compressed)
PHP)],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 class="mw-h2-ref">Per-middleware reference</h2>

<h3 id="cors" class="mw-h3"><code>CorsMiddleware</code></h3>
<p>CORS preflight (OPTIONS + <code>Origin</code>) plus <code>Access-Control-*</code> headers on every response. There is no Apache/nginx parity here — CORS is a modern browser concern, not a server-config item.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CorsMiddleware;

$app->addMiddleware(new CorsMiddleware(
    origins:     ['https://app.example.com', 'https://admin.example.com'],
    methods:     ['GET', 'POST', 'PUT', 'DELETE'],
    headers:     ['Content-Type', 'Authorization'],
    credentials: true,
    maxAge:      86400,
));
PHP]); ?>

<h3 id="etag" class="mw-h3"><code>ETagMiddleware</code></h3>
<p>Generates <code>W/"md5(body)"</code> on GET responses; returns <code>304 Not Modified</code> when the client's <code>If-None-Match</code> matches. Apache parity: <code>FileETag</code>. nginx parity: <code>etag on;</code> + <code>if_modified_since</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ETagMiddleware;

$app->addMiddleware(new ETagMiddleware());
PHP]); ?>

<h3 id="compression" class="mw-h3"><code>CompressionMiddleware</code></h3>
<p>Reference gzip/deflate body compression. <strong>OpenSwoole's <code>http_compression</code> is enabled by default</strong> — only register this middleware if you've disabled it. Apache parity: <code>mod_deflate</code>. nginx parity: <code>gzip on;</code> + <code>gzip_types</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CompressionMiddleware;

// Only register if you've turned off OpenSwoole's http_compression.
$app->addMiddleware(new CompressionMiddleware(
    minLength:           1024,   // do not compress small bodies
    level:               6,      // 1..9 — same as zlib
    skipProxiedRequests: true,   // skip when Via header is present
));
PHP]); ?>

<h3 id="range" class="mw-h3"><code>RangeMiddleware</code></h3>
<p>RFC 7233 Range requests. Adds <code>Accept-Ranges: bytes</code>, returns <code>206 Partial Content</code> for single or multi-range requests, <code>416</code> for unsatisfiable ranges, and honors <code>If-Range</code> ETag pinning. Required for video seeking and resumable downloads. nginx serves this automatically; ZealPHP needs the middleware registered.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RangeMiddleware;

$app->addMiddleware(new RangeMiddleware());
// Now: curl -r 0-1023 /video.mp4 → 206 Partial Content (first 1024 bytes)
PHP]); ?>

<h3 id="session-start" class="mw-h3"><code>SessionStartMiddleware</code></h3>
<p>Eagerly starts a session and sends <code>Set-Cookie: PHPSESSID=...</code> for first-time visitors. Without it, <code>CoSessionManager</code> only starts sessions when a <code>PHPSESSID</code> cookie already exists — so first-time visitors see no session cookie and session state resets every request. The <code>secure</code> flag auto-detects HTTPS via <code>X-Forwarded-Proto</code>, <code>HTTPS</code>, or port 443 — works behind Traefik/Nginx and on direct HTTP. Override with <code>ZEALPHP_SESSION_SECURE</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\SessionStartMiddleware;

$app->addMiddleware(new SessionStartMiddleware());
PHP]); ?>

<h3 id="ini-isolation" class="mw-h3"><code>IniIsolationMiddleware</code></h3>
<p>Snapshots <code>ini_set()</code> changes (<code>timezone</code>, <code>error_reporting</code>, <code>display_errors</code>, <code>memory_limit</code>, etc.) at request start and restores them on exit. Opt-in defence against ini-value leakage across requests on long-running workers — see <a href="/coroutines#what-survives">what survives a request</a>. Enable with <code>ZEALPHP_INI_ISOLATE=1</code> or by registering it explicitly.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\IniIsolationMiddleware;

// Default — snapshots a curated key list
$app->addMiddleware(new IniIsolationMiddleware());

// Or pass an explicit list to track
$app->addMiddleware(new IniIsolationMiddleware([
    'date.timezone', 'memory_limit', 'error_reporting',
]));
PHP]); ?>

<h3 id="charset" class="mw-h3"><code>CharsetMiddleware</code></h3>
<p>Auto-appends <code>; charset=utf-8</code> to text-ish response <code>Content-Type</code> values that don't already declare a charset. Reads <code>App::$default_charset</code> (settable via <code>App::defaultCharset('utf-8')</code>). Apache parity: <code>AddDefaultCharset utf-8</code> + <code>AddCharset utf-8 .css .js .html</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CharsetMiddleware;

App::defaultCharset('utf-8');  // optional — utf-8 is the default
$app->addMiddleware(new CharsetMiddleware());
// → text/html → text/html; charset=utf-8 (only if not already set)
PHP]); ?>

<h3 id="cache-control" class="mw-h3"><code>CacheControlMiddleware</code></h3>
<p>Extension-based <code>Cache-Control: max-age=N, public</code> for static-asset responses. Apache parity: <code>&lt;FilesMatch "\.(css|js|jpg|png)$"&gt; Header set Cache-Control "max-age=2628000"</code>. nginx parity: <code>location ~* \.(css|js)$ { expires 30d; }</code> partial.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CacheControlMiddleware;

$app->addMiddleware(new CacheControlMiddleware([
    'css'   => ['max-age' => 2628000, 'public' => true],     // 1 month
    'js'    => ['max-age' => 2628000, 'public' => true],
    'jpg'   => ['max-age' => 31536000, 'public' => true],   // 1 year
    'png'   => ['max-age' => 31536000, 'public' => true],
    'woff2' => ['max-age' => 31536000, 'public' => true, 'immutable' => true],
]));
PHP]); ?>

<h3 id="expires" class="mw-h3"><code>ExpiresMiddleware</code></h3>
<p>Adds the legacy HTTP/1.0 <code>Expires:</code> header by content type. Pairs with <code>CacheControlMiddleware</code> for full Apache <code>mod_expires</code> parity. nginx parity: <code>expires 30d;</code> in a <code>location</code> block.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ExpiresMiddleware;

$app->addMiddleware(new ExpiresMiddleware([
    'image/jpeg'             => '+1 year',
    'image/png'              => '+1 year',
    'text/css'               => '+1 month',
    'application/javascript' => '+1 month',
    '__default'              => '+1 hour',
]));
PHP]); ?>

<h3 id="header" class="mw-h3"><code>HeaderMiddleware</code></h3>
<p>Declarative response-header manipulation: <code>set</code> (overwrite), <code>add</code> (append), <code>unset</code> (remove). Conditional variants run only on specific status codes or content types. Apache parity: <code>Header set / append / unset / add / merge</code> (mod_headers).</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\HeaderMiddleware;

$app->addMiddleware(new HeaderMiddleware([
    'set' => [
        'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains; preload',
        'X-Content-Type-Options'    => 'nosniff',
        'X-Frame-Options'           => 'SAMEORIGIN',
        'Referrer-Policy'           => 'strict-origin-when-cross-origin',
    ],
    'add'   => ['Link' => '</css/zealphp.css>; rel=preload; as=style'],
    'unset' => ['X-Powered-By'],
]));
PHP]); ?>

<h3 id="basic-auth" class="mw-h3"><code>BasicAuthMiddleware</code></h3>
<p>HTTP Basic Auth — htpasswd file or callback verifier. Returns <code>401</code> with <code>WWW-Authenticate: Basic</code> on missing/invalid credentials. Apache parity: <code>AuthType Basic</code> + <code>AuthName</code> + <code>AuthUserFile</code> + <code>Require</code>. nginx parity: <code>auth_basic "Realm"</code> + <code>auth_basic_user_file htpasswd</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BasicAuthMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;

// 1) htpasswd-file backend, scoped to /admin
$app->addMiddleware(ScopedMiddleware::location(
    new BasicAuthMiddleware(
        htpasswdFile: __DIR__ . '/.htpasswd',
        realm:        'Admin Area',
    ),
    '/admin'
));

// 2) callback verifier — lets you check against the DB, scoped to /api/private
$app->addMiddleware(ScopedMiddleware::location(
    new BasicAuthMiddleware(
        verify: fn($user, $pass) => User::checkCredentials($user, $pass),
        realm:  'API',
    ),
    '/api/private'
));
PHP]); ?>

<h3 id="ip-access" class="mw-h3"><code>IpAccessMiddleware</code></h3>
<p>CIDR allow/deny lists. Apache parity: legacy <code>Allow from</code> / <code>Deny from</code> / <code>Order Allow,Deny</code> (mod_access_compat) and modern <code>Require ip</code>. Pairs naturally with <code>App::$trusted_proxies</code> + <code>App::clientIp()</code> for correct client-IP resolution behind a front proxy.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;

// Allow only internal networks to hit /admin.
// The middleware is deny-first: any IP not in 'allow' is refused.
// No 'deny' entry is needed — the allow list already excludes everything else.
$app->addMiddleware(ScopedMiddleware::location(
    new IpAccessMiddleware([
        'allow' => ['10.0.0.0/8', '192.168.0.0/16', '127.0.0.1/32'],
    ]),
    '/admin'
));
PHP]); ?>

<h3 id="rate-limit" class="mw-h3"><code>RateLimitMiddleware</code></h3>
<p>Sliding-window request rate limiter using <code>Store</code> for cross-worker shared state. nginx parity: <code>limit_req zone=one rate=10r/s burst=20;</code>. Returns <code>429 Too Many Requests</code> with <code>Retry-After</code> when the window is full.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RateLimitMiddleware;

// Create the backing Store once (BEFORE $app->run())
Store::make('rate_limit', 100_000, [
    'count' => [Store::TYPE_INT, 4],   // column spec is a positional [type, size] tuple
    'reset' => [Store::TYPE_INT, 8],
]);

// 60 requests per minute per client IP (keyed by client IP internally)
$app->addMiddleware(new RateLimitMiddleware(
    limit:     60,
    window:    60,            // seconds
    tableName: 'rate_limit',
));
PHP]); ?>

<h3 id="concurrency-limit" class="mw-h3"><code>ConcurrencyLimitMiddleware</code></h3>
<p>In-flight concurrent-request cap. nginx parity: <code>limit_conn zone=one 10;</code>. Backed by <code>OpenSwoole\Atomic</code> (<code>Counter</code>) — increments on entry, decrements in a <code>finally</code>. Returns <code>503</code> when the cap is reached.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Counter;
use ZealPHP\Middleware\ConcurrencyLimitMiddleware;

$counter = new Counter(0, 'inflight');   // (initial, name) — create BEFORE $app->run()

$app->addMiddleware(new ConcurrencyLimitMiddleware(
    maxConcurrent: 100,     // max concurrent in-flight requests
    counter:       $counter,
));
PHP]); ?>

<h3 id="block-php-ext" class="mw-h3"><code>BlockPhpExtMiddleware</code></h3>
<p>Refuses any URL ending in <code>.php</code> with a <code>404</code>. Useful for apps that want extensionless URLs as the only public surface (so scrapers can't enumerate raw files by guessing <code>config.php</code> / <code>admin.php</code>). Apache parity: <code>RewriteCond %{THE_REQUEST} \.php; RewriteRule . - [R=404,L]</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BlockPhpExtMiddleware;

$app->addMiddleware(new BlockPhpExtMiddleware());
// /admin.php       → 404
// /config.php?x=1  → 404
// /admin           → handled normally (the .php is implicit)
PHP]); ?>

<h3 id="mime-type" class="mw-h3"><code>MimeTypeMiddleware</code></h3>
<p>Sets or overrides <code>Content-Type</code> on non-static responses by URL extension or pattern. Static files are MIME-typed by OpenSwoole's static handler — this middleware fills the gap for handler-generated responses. Apache parity: <code>AddType font/woff2 .woff2</code> and <code>ForceType image/svg+xml</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\MimeTypeMiddleware;

$app->addMiddleware(new MimeTypeMiddleware([
    'woff2' => 'font/woff2',
    'glb'   => 'model/gltf-binary',
    'wasm'  => 'application/wasm',
]));
PHP]); ?>

<h3 id="body-rewrite" class="mw-h3"><code>BodyRewriteMiddleware</code></h3>
<p>Single-line regex substitution on the response body. Useful for late-stage URL rewriting (e.g., serving a CDN-versioned <code>asset.js?v=abc</code>) or hot-patching templates. Apache parity: <code>Substitute "s/foo/bar/"</code> (mod_substitute). Multi-line and streaming variants are on the roadmap.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BodyRewriteMiddleware;

$app->addMiddleware(new BodyRewriteMiddleware([
    // CDN URL rewrite for HTML responses
    '#https?://old-cdn\.example\.com/#' => 'https://cdn.example.com/',
    // Asset version cache-bust
    '#\.js"#'                            => '.js?v=' . APP_VERSION . '"',
], contentTypes: ['text/html', 'application/xhtml+xml']));
PHP]); ?>

<h3 id="host-router" class="mw-h3"><code>HostRouterMiddleware</code></h3>
<p>Routes by <code>Host</code> header inside one ZealPHP instance. nginx parity: multiple <code>server { server_name a.com; }</code> blocks. For true isolation prefer one ZealPHP process per host behind Caddy/Traefik; use this when ergonomic co-tenancy is the goal.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\HostRouterMiddleware;

$app->addMiddleware(new HostRouterMiddleware([
    'app.example.com'   => fn($req, $next) => $next->handle($req),
    'admin.example.com' => function ($req, $next) {
        // Tighter middleware stack for the admin host
        $req = $req->withAttribute('app.scope', 'admin');
        return $next->handle($req);
    },
    'api.example.com'   => fn($req, $next) =>
        $next->handle($req->withAttribute('app.scope', 'api')),
    '__default'         => fn($req, $next) => $next->handle($req),
]));
PHP]); ?>

<h3 id="content-encoding" class="mw-h3"><code>ContentEncodingMiddleware</code></h3>
<p>Sets the response <code>Content-Encoding</code> header from the request URL's dot-separated file suffixes. Apache's <code>find_ct</code> walks every suffix and accumulates an encoding chain — <code>archive.tar.gz</code> with <code>AddEncoding x-gzip .gz</code> yields <code>Content-Encoding: x-gzip</code>, and a doubly-encoded <code>data.gz.gz</code> yields <code>gzip, gzip</code> (order preserved, duplicates intentionally kept). Additive and opt-in: never overrides a <code>Content-Encoding</code> a handler (or a real compression middleware) already set. Apache parity: <code>mod_mime AddEncoding</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ContentEncodingMiddleware;

$app->addMiddleware(new ContentEncodingMiddleware([
    'gz'  => 'gzip',
    'br'  => 'br',
    'bz2' => 'bzip2',
]));
PHP]); ?>

<h3 id="content-language" class="mw-h3"><code>ContentLanguageMiddleware</code></h3>
<p>Sets the response <code>Content-Language</code> header from the request URL's dot-separated suffixes — <code>page.en.html</code> with <code>AddLanguage en .en</code> yields <code>Content-Language: en</code>. Multiple language suffixes accumulate in order and are emitted comma-joined (RFC 9110 §8.5 allows a list). Additive and opt-in: only sets the header when the response doesn't already declare one. Apache parity: <code>mod_mime AddLanguage</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ContentLanguageMiddleware;

$app->addMiddleware(new ContentLanguageMiddleware([
    'en' => 'en',
    'fr' => 'fr',
    'de' => 'de',
]));
PHP]); ?>

<h3 id="merge-slashes" class="mw-h3"><code>MergeSlashesMiddleware</code></h3>
<p>Collapses runs of consecutive slashes in the request path to a single slash before routing, so <code>/a//b///c</code> matches the same route as <code>/a/b/c</code>. Internal rewrite (no redirect) — mutates <code>$g->server['REQUEST_URI']</code>, which the router reads. The query string is left untouched. Register it ahead of route-dependent middleware. Apache parity: <code>MergeSlashes On</code>. nginx parity: <code>merge_slashes on;</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\MergeSlashesMiddleware;

$app->addMiddleware(new MergeSlashesMiddleware());
// Now: /api//users///42  routes the same as  /api/users/42
PHP]); ?>

<h3 id="request-header" class="mw-h3"><code>RequestHeaderMiddleware</code></h3>
<p>Manipulates the request headers the application sees, before handlers run. Headers are written into <code>$g->server</code> using the mod_php CGI convention (<code>HTTP_&lt;NAME&gt;</code>, uppercased, dashes → underscores), so <code>apache_request_headers()</code>, <code>getallheaders()</code>, and <code>$g->server['HTTP_*']</code> reflect the change. Operations: <code>set</code> (replace/create), <code>append</code> / <code>add</code> (comma-joined append or create), <code>unset</code> (remove). Apache parity: <code>mod_headers RequestHeader</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RequestHeaderMiddleware;

$app->addMiddleware(new RequestHeaderMiddleware([
    ['op' => 'set',    'name' => 'X-Forwarded-Proto', 'value' => 'https'],
    ['op' => 'append', 'name' => 'X-Trace',           'value' => 'edge'],
    ['op' => 'unset',  'name' => 'X-Debug'],
]));
PHP]); ?>

<h3 id="return" class="mw-h3"><code>ReturnMiddleware</code></h3>
<p>Unconditionally returns a fixed response — the route handler never runs. For 3xx statuses the second argument is the redirect target (<code>Location</code>); for any other status it's the response body. Pair with <a href="#scoped"><code>ScopedMiddleware</code></a> to limit it to a path (the nginx <code>location { return ... }</code> shape). nginx parity: <code>return</code> directive.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ReturnMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;

// Outright block a path
$app->addMiddleware(ScopedMiddleware::location(new ReturnMiddleware(403), '/blocked'));

// Permanent redirect from /old → /new
$app->addMiddleware(ScopedMiddleware::match(new ReturnMiddleware(301, '/new'), '#^/old$#'));

// Health-check stub
$app->addMiddleware(ScopedMiddleware::location(new ReturnMiddleware(200, 'pong'), '/ping'));
PHP]); ?>

<h3 id="scoped" class="mw-h3"><code>ScopedMiddleware</code></h3>
<p>Apply another middleware only to matching request paths — the Apache-container equivalent for middleware. Two factory methods: <code>ScopedMiddleware::location($inner, '/admin')</code> is <code>&lt;Location "/admin"&gt;</code> (literal URL-path prefix — matches <code>/admin</code>, <code>/admin/x</code>, and — like Apache — <code>/administrator</code>; use a trailing slash or a regex for segment precision). <code>ScopedMiddleware::match($inner, '#^/api/#')</code> is <code>&lt;LocationMatch&gt;</code> / <code>&lt;FilesMatch&gt;</code> (PCRE against the path). Outside the scope the inner middleware is skipped entirely.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPHP\Middleware\BasicAuthMiddleware;
use ZealPHP\Middleware\BlockPhpExtMiddleware;

// Scope BasicAuth to /admin only
$app->addMiddleware(ScopedMiddleware::location(
    new BasicAuthMiddleware(htpasswdFile: __DIR__ . '/.htpasswd', realm: 'Admin'),
    '/admin'
));

// Refuse *.php URLs anywhere on the host
$app->addMiddleware(ScopedMiddleware::match(new BlockPhpExtMiddleware(), '#\.php$#'));
PHP]); ?>

<h3 id="set-env-if" class="mw-h3"><code>SetEnvIfMiddleware</code></h3>
<p>Sets request "environment" variables (into <code>$g->server</code>, where mod_php code reads them as <code>$_SERVER</code>) when an attribute of the request matches a regex. The classic use is tagging bots, internal IPs, or URL areas so downstream middleware / handlers can branch on a simple flag. Attribute names mirror Apache: the special tokens <code>Remote_Addr</code>, <code>Remote_Host</code>, <code>Server_Addr</code>, <code>Request_Method</code>, <code>Request_Protocol</code>, <code>Request_URI</code>; any other name is treated as a request header (so <code>User-Agent</code> gives <code>BrowserMatch</code> behaviour). Apache parity: <code>mod_setenvif</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\SetEnvIfMiddleware;

$app->addMiddleware(new SetEnvIfMiddleware([
    ['attr' => 'User-Agent',  'regex' => '#bot#i',    'set' => ['IS_BOT' => '1']],
    ['attr' => 'Request_URI', 'regex' => '#^/admin#', 'set' => ['ADMIN_AREA' => '1']],
    ['attr' => 'Remote_Addr', 'regex' => '#^10\.#',   'set' => ['INTERNAL' => '1']],
]));
PHP]); ?>

<h3 id="body-size-limit" class="mw-h3"><code>BodySizeLimitMiddleware</code></h3>
<p>Rejects oversized request bodies with <code>413 Content Too Large</code> before the handler runs. Accepts an integer (bytes) or a shorthand string (<code>'10M'</code>, <code>'512K'</code>). Pass <code>0</code> for unlimited. nginx parity: <code>client_max_body_size 10m;</code>. Apache parity: <code>LimitRequestBody</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BodySizeLimitMiddleware;

// Reject uploads larger than 10 MB
$app->addMiddleware(new BodySizeLimitMiddleware('10M'));
PHP]); ?>

<h3 id="redirect" class="mw-h3"><code>RedirectMiddleware</code></h3>
<p>Declarative URL redirects — first matching rule short-circuits. Each rule is an associative array with either a <code>'from'</code> key (prefix match, like Apache <code>Redirect /old /new</code>) or a <code>'match'</code> key (PCRE regex with capture groups, like <code>RedirectMatch</code>). The per-rule default status is <code>302</code>; pass an explicit <code>'status'</code> per rule to override. A rule without a <code>'to'</code> key is silently skipped. Apache parity: <code>mod_alias</code> (<code>Redirect</code> / <code>RedirectMatch</code>).</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RedirectMiddleware;

$app->addMiddleware(new RedirectMiddleware([
    // Permanent prefix redirect
    ['from' => '/blog',         'to' => '/articles',                'status' => 301],
    // Regex redirect with back-reference (permanent)
    ['match' => '#^/old/(.+)#', 'to' => '/new/$1',                 'status' => 301],
    // Temporary redirect (302 is the per-rule default)
    ['from' => '/beta',         'to' => 'https://beta.example.com', 'status' => 302],
]));
PHP]); ?>

<h3 id="referer" class="mw-h3"><code>RefererMiddleware</code></h3>
<p>Hotlink protection — refuses requests whose <code>Referer</code> header is not in the allowed set with <code>403 Forbidden</code>. Specs can be plain host names, wildcards (<code>*.example.com</code>), or regexes (prefixed with <code>~</code>). A missing <code>Referer</code> is allowed by default (<code>allowNone: true</code>). nginx parity: <code>valid_referers</code> / <code>if ($invalid_referer) { return 403; }</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RefererMiddleware;

$app->addMiddleware(new RefererMiddleware(
    referers:    ['example.com', '*.example.com'],
    serverNames: ['example.com'],   // own hosts always allowed
));
PHP]); ?>

<h3 id="csrf" class="mw-h3"><code>CsrfMiddleware</code></h3>
<p>Double-submit CSRF protection for state-mutating requests (<code>POST</code>, <code>PUT</code>, <code>PATCH</code>, <code>DELETE</code>). Generates a per-session token and validates it on every non-safe request. Pass an array of URL paths to exempt (e.g. API endpoints using their own token scheme).</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CsrfMiddleware;

$app->addMiddleware(new CsrfMiddleware(
    exempt: ['/api/', '/webhooks/'],
));
PHP]); ?>

<h3 id="health-check" class="mw-h3"><code>HealthCheckMiddleware</code></h3>
<p>Short-circuits on health-check paths and returns a JSON response — <code>200 {"status":"ok"}</code> when healthy, <code>503 {"status":"unhealthy",...}</code> when the optional check callback returns an error string. Default path is <code>/healthz</code>; pass additional paths (<code>/readyz</code>, <code>/_health</code>) as needed. Route handlers never run for health-check paths.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\HealthCheckMiddleware;
use ZealPHP\Store;

$app->addMiddleware(new HealthCheckMiddleware(
    paths: ['/healthz', '/readyz'],
    check: function (): ?string {
        // Return null → healthy; non-null string → unhealthy reason
        try {
            Store::get('rate_limit', '__ping');
            return null;
        } catch (\Throwable $e) {
            return 'store unreachable: ' . $e->getMessage();
        }
    },
));
PHP]); ?>

<h2 class="mw-h2-ref">Custom middleware</h2>
<p class="mw-note-custom">Middleware always returns a <code>Psr\Http\Message\ResponseInterface</code> — that's PSR-15's contract, not ZealPHP's. Inside the route handler that the middleware wraps, the handler still uses the <a href="/responses#return-contract">universal return contract</a>; ZealPHP's <code>ResponseMiddleware</code> converts the return into a PSR-7 response before your middleware sees it.</p>

<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start    = microtime(true);
        $response = $handler->handle($request);       // call inner stack
        $elapsed  = round((microtime(true) - $start) * 1000, 2);
        response_add_header('X-Response-Time', "$elapsed ms");
        return $response;
    }
}

// Register:
$app->addMiddleware(new TimingMiddleware());
PHP]); ?>
</div>
</section>
