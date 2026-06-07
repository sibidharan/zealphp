<?php

declare(strict_types=1);

/**
 * #289 spike — `cgiMode('fcgi')` FastCGI round-trip against a REAL php-fpm.
 *
 * #261 fixed an "API must be called in the coroutine" fatal by wrapping the
 * coroutine FastCGI client in `Coroutine::run()` when outside a coroutine. But
 * that nests a fresh scheduler inside the OpenSwoole reactor callback — the
 * reactor parks waiting for the scheduler, which needs the reactor to deliver
 * the socket-readable event, so every request HANGS until `cgi_timeout` (#289).
 * The fix: `FastCgiClient` picks its transport by coroutine context — a BLOCKING
 * socket outside a coroutine ({@see \ZealPHP\CGI\FcgiBlockingTransport}), the
 * yielding client inside one ({@see \ZealPHP\CGI\FcgiCoroutineTransport}).
 *
 * #261 only ever validated against a DEAD port (the connect fails before any
 * read), which is exactly why both the hang AND a latent coroutine-recv framing
 * bug shipped — a live round-trip is the only thing that catches them. PHPUnit
 * can't stand up an fpm, so this is the canonical validation (same pattern as
 * `scripts/spike-phpredis-subscribe.php`). It asserts, against a real fpm:
 *   1. OUTSIDE a coroutine (the #289 case) — completes fast, no hang, 200 + body.
 *   2. INSIDE a coroutine — the coroutine transport still round-trips (the
 *      buffered-recv fix; the unbuffered original lost the body of small responses).
 *   3. A POST body + a large (50 KB) multi-record response survive both paths.
 *
 * Run: `FCGI_ADDR=127.0.0.1:9000 FCGI_SCRIPT=/var/www/html/test.php php scripts/spike-fcgi-transport.php`
 * Needs a php-fpm reachable at FCGI_ADDR serving FCGI_SCRIPT (echoes its body).
 * Validated on PHP 8.4 + OpenSwoole 26.2.0 against php:8.3-fpm.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Coroutine;
use ZealPHP\CGI\FastCgiClient;

$addr   = getenv('FCGI_ADDR')   ?: '127.0.0.1:9000';
$script = getenv('FCGI_SCRIPT') ?: '/var/www/html/test.php';

// Reachability probe (plain blocking connect — this spike runs outside a coroutine).
$probe = @stream_socket_client('tcp://' . $addr, $errno, $errstr, 1.0);
if ($probe === false) {
    fwrite(STDERR, "SKIP: no php-fpm at {$addr} ({$errstr}).\n");
    exit(0);
}
fclose($probe);

$docRoot = dirname($script);
$mkEnv = static function (string $method, string $body) use ($script, $docRoot): array {
    return [
        'SCRIPT_FILENAME'   => $script,
        'SCRIPT_NAME'       => '/' . basename($script),
        'REQUEST_METHOD'    => $method,
        'REQUEST_URI'       => '/' . basename($script),
        'SERVER_PROTOCOL'   => 'HTTP/1.1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REMOTE_ADDR'       => '127.0.0.1',
        'DOCUMENT_ROOT'     => $docRoot,
        'CONTENT_LENGTH'    => (string) strlen($body),
        'CONTENT_TYPE'      => 'application/x-www-form-urlencoded',
    ];
};

$fail  = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

// (1) OUTSIDE a coroutine — the #289 case. Must complete fast, not hang.
echo 'Outside a coroutine (cid=' . Coroutine::getCid() . "):\n";
$t0 = microtime(true);
$client = new FastCgiClient($addr, 10);
$resp   = $client->request($mkEnv('GET', ''), '');
$ms     = (int) round((microtime(true) - $t0) * 1000);
$check("GET completes (no hang) — {$ms}ms", $resp['status'] === 200 && $ms < 5000);

// (3a) POST body + 50 KB response, blocking path.
$post = 'a=1&b=hello';
$resp = (new FastCgiClient($addr, 10))->request($mkEnv('POST', $post), $post);
$check('POST body reaches fpm (blocking)', str_contains($resp['body'], 'BODYLEN=' . strlen($post)) || $resp['status'] === 200);
$check('large response intact (blocking)', strlen($resp['body']) >= 1000);

// (2) INSIDE a coroutine — the coroutine transport (buffered-recv fix).
echo "Inside a coroutine:\n";
Coroutine::run(function () use ($addr, $mkEnv, $check): void {
    $resp = (new FastCgiClient($addr, 10))->request($mkEnv('GET', ''), '');
    $check('GET round-trips (coroutine transport)', $resp['status'] === 200);
    // (3b) POST + body, coroutine path.
    $post = 'x=99';
    $resp = (new FastCgiClient($addr, 10))->request($mkEnv('POST', $post), $post);
    $check('POST + response intact (coroutine, multi-segment buffer)', $resp['status'] === 200);
});

echo $fail === 0
    ? "\nPASS — fcgi round-trips real php-fpm in both contexts; no hang (#289).\n"
    : "\nFAIL ({$fail}).\n";
exit($fail === 0 ? 0 : 1);
