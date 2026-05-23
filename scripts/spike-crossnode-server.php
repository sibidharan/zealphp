<?php

declare(strict_types=1);

/**
 * Phase 3 cross-host spike — server runtime.
 *
 * Two instances of this script run on different ports against the same
 * valkey. Each subscribes to its identity channel (ws:server:{ID}); a
 * /publish?to=X&msg=Y route on either server PUBLISHes to ws:server:{X},
 * the other server's worker receives via SUBSCRIBE and appends to a log
 * file the runner can grep.
 *
 * env in:
 *   SERVER_ID         A | B
 *   PORT              8090 / 8091 / ...
 *   ZEALPHP_REDIS_URL pinned to the shared valkey
 *   SPIKE_LOG_DIR     where per-server log files land (the runner reads them)
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Coroutine;
use ZealPHP\App;
use ZealPHP\Store\RedisClient;

$serverId = (string) (getenv('SERVER_ID') ?: 'A');
$port     = (int)    (getenv('PORT')      ?: 8090);
$redisUrl = (string) (getenv('ZEALPHP_REDIS_URL') ?: 'redis://127.0.0.1:16379/0');
$logDir   = (string) (getenv('SPIKE_LOG_DIR') ?: '/tmp/zealphp-spike-crossnode');
@mkdir($logDir, 0777, true);
$logPath  = $logDir . '/' . $serverId . '.log';
file_put_contents($logPath, "[boot] server $serverId on :$port → $redisUrl\n", LOCK_EX);

App::superglobals(false);
App::init('0.0.0.0', $port);
$app = App::instance();

// Wire the dedicated subscriber coroutine on each worker. SUBSCRIBE
// monopolises a connection, so this client is built outside the
// RedisConnectionPool used for normal ops.
App::onWorkerStart(function ($server, int $workerId) use ($serverId, $redisUrl, $logPath): void {
    go(function () use ($serverId, $workerId, $redisUrl, $logPath): void {
        $sub = new RedisClient($redisUrl);
        $channel = "ws:server:$serverId";

        // The RedisClient adapter doesn't expose subscribe yet (Phase 3
        // territory), so use the underlying predis client directly here —
        // exactly the user-land pattern documented for "validate the
        // design before Phase 3 lands."
        $reflect = new ReflectionClass($sub);
        $driverProp = $reflect->getProperty('driver');
        $driverProp->setAccessible(true);
        $driver = $driverProp->getValue($sub);
        $clientProp = (new ReflectionClass($driver))->getProperty('c');
        $clientProp->setAccessible(true);
        /** @var \Predis\Client $client */
        $client = $clientProp->getValue($driver);

        file_put_contents($logPath, "[w$workerId] subscriber starting on $channel\n", FILE_APPEND | LOCK_EX);
        $loop = $client->pubSubLoop();
        $loop->subscribe($channel);

        foreach ($loop as $msg) {
            if ($msg->kind === 'message') {
                $now = number_format(microtime(true), 6, '.', '');
                file_put_contents(
                    $logPath,
                    "[w$workerId] @$now received on {$msg->channel}: {$msg->payload}\n",
                    FILE_APPEND | LOCK_EX,
                );
            } elseif ($msg->kind === 'subscribe') {
                file_put_contents($logPath, "[w$workerId] subscribed to {$msg->channel}\n", FILE_APPEND | LOCK_EX);
            }
        }
    });
});

// GET /id  — sanity probe
$app->route('/id', ['methods' => ['GET']], fn() => [
    'server'    => $serverId,
    'port'      => $port,
    'pid'       => getmypid(),
    'redis_url' => $redisUrl,
]);

// GET /publish?to=B&msg=hello  — PUBLISH ws:server:{to} with the payload.
$app->route('/publish', ['methods' => ['GET']], function (\ZealPHP\HTTP\Request $request) use ($redisUrl, $serverId, $logPath) {
    $q  = $request->get ?? [];
    $to = is_string($q['to']  ?? null) ? $q['to']  : '';
    $m  = is_string($q['msg'] ?? null) ? $q['msg'] : '';
    if ($to === '' || $m === '') {
        return ['ok' => false, 'error' => 'need ?to=&msg='];
    }
    $client  = new RedisClient($redisUrl);
    $channel = "ws:server:$to";
    $sent    = number_format(microtime(true), 6, '.', '');
    $payload = "$sent|from=$serverId|$m";
    $count   = $client->evalScript("return redis.call('PUBLISH', KEYS[1], ARGV[1])", [$channel], [$payload]);
    $client->close();
    file_put_contents(
        $logPath,
        "[publish] @$sent → $channel ('$m'); receivers=$count\n",
        FILE_APPEND | LOCK_EX,
    );
    return ['ok' => true, 'channel' => $channel, 'receivers' => $count, 'payload' => $payload];
});

$app->run([
    'worker_num'      => 1,
    'enable_coroutine'=> true,
    'log_level'       => SWOOLE_LOG_WARNING,
    'log_file'        => $logDir . "/openswoole-$serverId.log",
]);
