<?php

declare(strict_types=1);

/**
 * SPIKE — Persistent subprocess entry for ZealPHP's native FCGI-style worker pool.
 *
 * Loops on stdin: read request frame -> execute the PHP file -> write response
 * frame to stdout -> reset state -> next iteration. Exits cleanly on EOF (parent
 * closed pipe) or after ZEALPHP_POOL_MAX_REQUESTS requests (recycle).
 *
 * Each iteration is approximately what `cgi_worker.php` does for ONE request,
 * but without the boot cost — composer autoloader + IPC class stay loaded
 * across requests. THAT is the cost-savings the pool exists for.
 *
 * Caveat for unmodified WordPress / Drupal: PHP's global namespace (defined
 * classes, define() constants, ini_set) PERSISTS across iterations of the
 * same worker. Setting `ZEALPHP_POOL_MAX_REQUESTS=1` recycles after each
 * request to get true fresh-process semantics — same as proc-mode, with
 * the framework managing the spawn/dispatch queue. K > 1 is for apps with
 * idempotent boot (modernised legacy / framework code that guards
 * `defined() ? : define()`).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use ZealPHP\CGI\IPC;

$maxRequests = (int) (getenv('ZEALPHP_POOL_MAX_REQUESTS') ?: '500');
$count       = 0;

// Disable any inherited output buffering so PHP errors and notices can't
// pollute the response-frame stream on stdout.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Signal ready to parent — single line on stderr so the framing channel
// (stdout) stays pure. Parent can choose to read this for boot synchronisation.
fwrite(STDERR, "ZEALPHP_POOL_WORKER_READY\n");

while ($count < $maxRequests) {
    $req = IPC::readFrame(STDIN);
    if ($req === null) {
        break; // parent closed pipe — clean exit
    }

    $resp = handle_request($req);
    IPC::writeFrame(STDOUT, $resp);

    reset_request_state();
    $count++;
}

exit(0);

/**
 * @param array<mixed,mixed> $req
 * @return array<string,mixed>
 */
function handle_request(array $req): array
{
    $file = isset($req['file']) && is_string($req['file']) ? $req['file'] : '';
    if ($file === '' || !is_file($file)) {
        return [
            'status'  => 404,
            'body'    => "pool_worker: file not found: $file",
            'headers' => [],
            'cookies' => [],
        ];
    }

    // Re-populate request-input superglobals. PHP's `$_SERVER` keeps a base
    // populated from the subprocess's own env; merging on top preserves any
    // worker-side keys that downstream code may read.
    $_SERVER  = array_merge($_SERVER, is_array($req['server'] ?? null) ? $req['server'] : []);
    $_GET     = is_array($req['get']     ?? null) ? $req['get']     : [];
    $_POST    = is_array($req['post']    ?? null) ? $req['post']    : [];
    $_COOKIE  = is_array($req['cookies'] ?? null) ? $req['cookies'] : [];
    $_FILES   = is_array($req['files']   ?? null) ? $req['files']   : [];
    $_REQUEST = array_merge($_GET, $_POST);

    ob_start();
    $result = null;
    try {
        /** @psalm-suppress UnresolvableInclude */
        $result = include $file;
    } catch (\Throwable $e) {
        ob_end_clean();

        return [
            'status'  => 500,
            'body'    => 'pool_worker fatal: ' . $e->getMessage(),
            'headers' => [],
            'cookies' => [],
            'stderr'  => $e->getTraceAsString(),
        ];
    }
    $body = (string) ob_get_clean();

    // Universal return contract (mirror src/cgi_worker.php).
    if ($result instanceof \Closure) {
        $result = $result();
    }
    if ($result instanceof \Generator) {
        foreach ($result as $chunk) {
            if (is_scalar($chunk)) {
                $body .= (string) $chunk;
            }
        }
        $result = null;
    }

    return [
        'status'       => http_response_code() ?: 200,
        'headers'      => headers_list(),
        'cookies'      => [], // cookie capture needs uopz; spike skips it
        'body'         => $body,
        'return_value' => is_scalar($result) || is_array($result) || $result === null ? $result : null,
    ];
}

function reset_request_state(): void
{
    $_SERVER  = [];
    $_GET     = [];
    $_POST    = [];
    $_COOKIE  = [];
    $_FILES   = [];
    $_REQUEST = [];
    $_SESSION = null;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}
