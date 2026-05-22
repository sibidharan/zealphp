<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>
<section class="section">
<div class="container">
<h1 class="section-title">HTTP Protocol Features</h1>
<p class="section-desc">ZealPHP implements the full HTTP/1.1 feature set: conditional requests, content negotiation, proper method handling, and CORS.</p>

<table class="ztable http-table">
  <tr><th>Feature</th><th>Status</th><th>How</th></tr>
  <tr><td>HEAD method</td><td>✅ Auto-mapped</td><td>ResponseMiddleware runs GET handler, strips body, adds Content-Length</td></tr>
  <tr><td>OPTIONS method</td><td>✅ Built-in</td><td>Returns 204 + Allow header with all methods registered for that URI</td></tr>
  <tr><td>ETag / 304</td><td>✅ Middleware</td><td>ETagMiddleware generates W/"md5", returns 304 on If-None-Match hit</td></tr>
  <tr><td>Gzip compression</td><td>✅ OpenSwoole</td><td><code>http_compression</code> handles bodies when Accept-Encoding includes gzip</td></tr>
  <tr><td>CORS</td><td>✅ Middleware</td><td>CorsMiddleware handles preflight + adds headers to every response</td></tr>
  <tr><td>Redirects 301/302/307/308</td><td>✅ Built-in</td><td><code>$response->redirect($url, $status)</code></td></tr>
  <tr><td>Cookie SameSite</td><td>✅ Built-in</td><td><code>setcookie($name, $value, ..., $samesite)</code></td></tr>
  <tr><td>HTTP/2</td><td>⚙️ Configure</td><td>Pass <code>'enable_http2' => true</code> to <code>$app->run()</code> (requires TLS)</td></tr>
  <tr><td>Range requests</td><td>✅ Middleware</td><td>RangeMiddleware handles single + multi-range (RFC 7233); <code>$response-&gt;sendFile()</code> for zero-copy file serving</td></tr>
</table>

<?php
$demos = [
  ['http-head',    'HEAD — headers only, body stripped',     'HEAD',    '/http/head-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Register GET route — HEAD works automatically:
$app->route('/http/head-test', function() {
    header('X-Custom-Header: zealphp');
    echo str_repeat('x', 2048);  // 2KB body
});

// curl -I __SITE_URL__/http/head-test
// → Content-Length: 2048 (no body)
// → X-Custom-Header: zealphp
PHP)],
  ['http-options', 'OPTIONS — Allow header for URI',          'OPTIONS', '/http/options-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
$app->route('/http/options-test', ['methods' => ['GET','POST','PUT']], fn() => '');

// curl -X OPTIONS __SITE_URL__/http/options-test -v
// → HTTP/1.1 204 No Content
// → Allow: OPTIONS, GET, HEAD, POST, PUT
PHP)],
  ['http-redirect','Redirects — 301/302/307/308',             'GET',     '/http/redirect/301',
   <<<'PHP'
// $response->redirect() sets Location + status
$app->route('/http/redirect/{code}', function($code, $response) {
    $response->redirect('/http/redirect-target', (int)$code);
});

// Auto-302 on Location header:
header('Location: https://example.com');
// ZealPHP detects Location: → sets status 302 automatically
PHP],
  ['http-range',   'Range — 206 Partial Content',             'GET',     '/http/range-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Any buffered response supports Range via RangeMiddleware:
$app->route('/http/range-test', function() {
    echo str_repeat('abcdefghij', 100);  // 1000 bytes
});

// curl -H 'Range: bytes=0-9' __SITE_URL__/http/range-test
// → HTTP/1.1 206 Partial Content
// → Content-Range: bytes 0-9/1000
// → abcdefghij
PHP)],
  ['http-sendfile', 'sendFile() — zero-copy file download',   'GET',     '/http/sendfile-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Serve files with zero-copy + automatic Range support:
$app->route('/download/{file}', function($file, $response) {
    $path = "/var/data/{$file}";
    $response->sendFile($path, $file);
});

// curl __SITE_URL__/http/sendfile-test
// → Content-Type: text/css
// → Accept-Ranges: bytes
//
// curl -H 'Range: bytes=0-99' __SITE_URL__/http/sendfile-test
// → HTTP/1.1 206 Partial Content
PHP)],
];
foreach ($demos as [$id, $title, $method, $url, $code]) {
    App::render('/components/_demo', ['id' => $id, 'title' => $title, 'url' => $url, 'code' => $code, 'method' => $method]);
}
?>

<h2 id="parity" class="section-subtitle">Apache + mod_php Parity</h2>
<p class="section-desc">
  The goal is simple: <strong>where Apache + mod_php works, ZealPHP works natively.</strong>
  Classic PHP runs under a web SAPI (mod_php / php-fpm) where the server populates
  the superglobals, fires the <code>session_*</code> and <code>header()</code> machinery,
  and honours <code>.htaccess</code> directives per request. ZealPHP runs under the CLI
  SAPI inside OpenSwoole and rebuilds that contract: it overrides the relevant PHP
  built-ins via <code>uopz</code>, populates request state per coroutine, and ships
  middleware mirroring the common Apache/nginx directives. Every remaining gap is
  listed below with its workaround — nothing is left undocumented.
</p>

<h3 class="parity-group">PHP built-in functions</h3>
<table class="ztable parity-table">
  <tr><th>Function</th><th>Status</th><th>Notes</th></tr>
  <tr><td><code>header()</code> / <code>header_remove()</code> / <code>headers_list()</code> / <code>headers_sent()</code></td><td>✅ Native</td><td>Write to the per-request response; <code>Location:</code> auto-sets 302</td></tr>
  <tr><td><code>http_response_code()</code></td><td>✅ Native</td><td>Last code wins, like mod_php</td></tr>
  <tr><td><code>setcookie()</code> / <code>setrawcookie()</code></td><td>✅ Native</td><td>Full 7-arg form incl. <code>SameSite</code></td></tr>
  <tr><td><code>session_*()</code> (whole family)</td><td>✅ Native</td><td>File-backed, coroutine-safe</td></tr>
  <tr><td><code>apache_request_headers()</code> / <code>getallheaders()</code> / <code>apache_response_headers()</code> / <code>apache_setenv/getenv()</code> / <code>apache_note()</code></td><td>✅ Native</td><td>Apache-only shims registered globally</td></tr>
  <tr><td><code>phpinfo()</code></td><td>✅ Native</td><td>Renders styled HTML (not the CLI text dump) — since v0.2.31</td></tr>
  <tr><td><code>filter_input()</code> / <code>filter_input_array()</code></td><td>✅ Native</td><td>Read <code>INPUT_GET/POST/COOKIE/SERVER/ENV</code> from request state (CLI returns null)</td></tr>
  <tr><td><code>header_register_callback()</code></td><td>✅ Native</td><td>Fires once before buffered headers flush (streaming paths excluded)</td></tr>
  <tr><td><code>is_uploaded_file()</code> / <code>move_uploaded_file()</code></td><td>✅ Native</td><td>Validate against the request's uploaded set</td></tr>
  <tr><td><code>error_log()</code></td><td>✅ Native</td><td>Routes type 0/4 into the framework log (debug.log → stderr); honors type 3 file append</td></tr>
  <tr><td><code>php_sapi_name()</code></td><td>⚙️ Opt-in</td><td>Default returns real <code>"cli"</code>; set <code>App::sapiName('apache2handler')</code> for legacy parity</td></tr>
  <tr><td><code>PHP_SAPI</code> constant</td><td>⚠️ Gap</td><td>Constants can't be redefined (<code>uopz</code> refuses). Use <code>php_sapi_name()</code> instead</td></tr>
  <tr><td><code>getenv()</code> / <code>putenv()</code> for CGI request vars</td><td>⚠️ Gap</td><td>Not request-scoped. Read request vars from <code>$g-&gt;server</code> / <code>$_SERVER</code></td></tr>
  <tr><td><code>mail()</code></td><td>⚠️ Gap</td><td>Relies on system <code>sendmail</code>; configurable transport planned</td></tr>
  <tr><td><code>get_browser()</code></td><td>⚠️ Gap</td><td>Needs <code>browscap.ini</code> configured; the no-arg form can't read the UA in coroutine mode. Workaround: pass it — <code>get_browser($g-&gt;server['HTTP_USER_AGENT'])</code></td></tr>
  <tr><td><code>virtual()</code> (Apache subrequest)</td><td>🚫 By design</td><td>Internal subrequest, not an HTTP call — use <code>App::include()</code> or call the route handler inline (same effect, no socket)</td></tr>
</table>

<h3 class="parity-group">$_SERVER superglobal</h3>
<table class="ztable parity-table">
  <tr><th>Key(s)</th><th>Status</th><th>Source</th></tr>
  <tr><td><code>REQUEST_METHOD</code> / <code>REQUEST_URI</code> / <code>SERVER_PROTOCOL</code> / <code>QUERY_STRING</code></td><td>✅ Native</td><td>OpenSwoole request</td></tr>
  <tr><td><code>REMOTE_ADDR</code> / <code>REMOTE_PORT</code> / <code>SERVER_PORT</code></td><td>✅ Native</td><td>OpenSwoole request</td></tr>
  <tr><td><code>REQUEST_TIME</code> / <code>REQUEST_TIME_FLOAT</code></td><td>✅ Native</td><td>OpenSwoole request</td></tr>
  <tr><td><code>HTTP_*</code> headers</td><td>✅ Native</td><td>Transcribed from request headers</td></tr>
  <tr><td><code>DOCUMENT_ROOT</code> / <code>SCRIPT_NAME</code> / <code>SCRIPT_FILENAME</code> / <code>PHP_SELF</code> / <code>SERVER_SOFTWARE</code> / <code>SERVER_NAME</code></td><td>✅ Native</td><td>Built per request (mod_php convention)</td></tr>
  <tr><td><code>GATEWAY_INTERFACE</code> / <code>REQUEST_SCHEME</code> / <code>HTTPS</code></td><td>✅ Native</td><td>Added by ZealPHP; scheme derived from <code>HTTPS</code>/<code>X-Forwarded-Proto</code>/port 443</td></tr>
</table>

<h3 class="parity-group">Apache directives → middleware &amp; config</h3>
<table class="ztable parity-table">
  <tr><th>Apache / nginx</th><th>Status</th><th>ZealPHP</th></tr>
  <tr><td><code>RewriteRule . /index.php [L]</code></td><td>✅ Native</td><td><code>App::setFallback()</code></td></tr>
  <tr><td><code>mod_headers</code> / <code>mod_expires</code> / <code>AddCharset</code> / <code>AddType</code> / <code>ForceType</code></td><td>✅ Middleware</td><td>Header / Expires / Charset / MimeType middleware</td></tr>
  <tr><td><code>&lt;FilesMatch&gt; Cache-Control</code> / <code>mod_substitute</code></td><td>✅ Middleware</td><td>CacheControl / BodyRewrite middleware</td></tr>
  <tr><td><code>AuthType Basic</code> + <code>AuthUserFile</code> / <code>Allow,Deny</code> / <code>Require ip</code></td><td>✅ Middleware</td><td>BasicAuth / IpAccess middleware</td></tr>
  <tr><td><code>limit_req</code> / <code>limit_conn</code> (nginx) / <code>server_name</code> vhosts</td><td>✅ Middleware</td><td>RateLimit / ConcurrencyLimit / HostRouter middleware</td></tr>
  <tr><td><code>Redirect</code> / <code>RedirectMatch</code> (mod_alias)</td><td>✅ Middleware</td><td>RedirectMiddleware — declarative prefix + regex redirects</td></tr>
  <tr><td><code>SetEnvIf</code> / <code>BrowserMatch</code> (mod_setenvif)</td><td>✅ Middleware</td><td>SetEnvIfMiddleware — set request env vars on attribute/regex match</td></tr>
  <tr><td><code>RequestHeader</code> (mod_headers)</td><td>✅ Middleware</td><td>RequestHeaderMiddleware — set/append/unset inbound headers (<code>$g-&gt;server</code>)</td></tr>
  <tr><td><code>&lt;Location&gt;</code> / <code>&lt;LocationMatch&gt;</code> / <code>&lt;FilesMatch&gt;</code></td><td>✅ Middleware</td><td>ScopedMiddleware — apply any middleware only to matching paths</td></tr>
  <tr><td><code>MergeSlashes</code> (core / nginx)</td><td>✅ Middleware</td><td>MergeSlashesMiddleware — collapse <code>//</code> in the path before routing</td></tr>
  <tr><td><code>client_max_body_size</code> (nginx) / <code>LimitRequestBody</code></td><td>✅ Middleware</td><td>BodySizeLimitMiddleware — 413 when <code>Content-Length</code> exceeds the cap</td></tr>
  <tr><td><code>valid_referers</code> (nginx)</td><td>✅ Middleware</td><td>RefererMiddleware — 403 hotlink protection (none/blocked/host/wildcard/regex)</td></tr>
  <tr><td><code>return</code> (nginx)</td><td>✅ Middleware</td><td>ReturnMiddleware — fixed status / redirect / body; pair with ScopedMiddleware</td></tr>
  <tr><td><code>ServerTokens</code> / <code>ServerSignature</code></td><td>✅ Config</td><td><code>App::serverTokens()</code> controls/omits the <code>X-Powered-By</code> header</td></tr>
  <tr><td><code>FileETag</code></td><td>✅ Config</td><td><code>App::fileETag(false)</code> disables ETag/304 (<code>FileETag None</code>)</td></tr>
  <tr><td><code>DocumentRoot</code> / <code>TraceEnable</code> / <code>ServerAdmin</code> / <code>ServerName</code> / <code>LimitRequest*</code> / <code>CustomLog</code></td><td>✅ Config</td><td><code>App::*</code> fluent setters (set before <code>App::init()</code>)</td></tr>
  <tr><td>Trusted proxies / <code>X-Forwarded-For</code></td><td>✅ Config</td><td><code>App::$trusted_proxies</code> + <code>App::clientIp()</code></td></tr>
</table>

<h3 class="parity-group">php.ini directives</h3>
<table class="ztable parity-table">
  <tr><th>Directive</th><th>Status</th><th>Notes</th></tr>
  <tr><td><code>max_execution_time</code></td><td>🚫 By design</td><td><code>set_time_limit()</code> is a no-op — use OpenSwoole coroutine timeouts</td></tr>
  <tr><td><code>auto_prepend_file</code> / <code>auto_append_file</code></td><td>🚫 By design</td><td>No per-request boot hook — include at your entry point / fallback handler</td></tr>
  <tr><td><code>post_max_size</code> / <code>upload_max_filesize</code></td><td>⚠️ Gap</td><td>Not enforced per directive — OpenSwoole's <code>package_max_length</code> is the real cap; middleware planned</td></tr>
  <tr><td><code>default_mimetype</code></td><td>✅ Middleware</td><td>CharsetMiddleware applies <code>App::$default_mimetype</code> (default <code>text/html</code>) to untyped responses</td></tr>
  <tr><td><code>max_input_vars</code></td><td>⚠️ Gap</td><td>Not enforced (default 1000 rarely hit)</td></tr>
</table>

</div>
</section>
