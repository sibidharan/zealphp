<?php

declare(strict_types=1);

/**
 * Phase 3 cross-HOST spike — subscriber side. Runs on a remote machine
 * (no OpenSwoole / no ext-redis required — pure-PHP predis is enough),
 * subscribes to the shared valkey over the network, records each
 * received frame's arrival timestamp + the publish-side timestamp
 * (encoded in the payload).
 *
 *   php spike-crosshost-subscribe.php <redis-url> <channel> <count> <log-path>
 */

require __DIR__ . '/../vendor/autoload.php';

use Predis\Client as PredisClient;

[$_, $url, $channel, $countStr, $logPath] = array_pad($argv, 5, null);
$count = (int) $countStr;
if (!$url || !$channel || $count < 1 || !$logPath) {
    fwrite(STDERR, "usage: $argv[0] <redis-url> <channel> <count> <log-path>\n");
    exit(2);
}

file_put_contents($logPath, "[subscribe] " . gethostname() . " → $url $channel (expecting $count)\n", LOCK_EX);

$client = new PredisClient($url, ['read_write_timeout' => -1]);
$loop = $client->pubSubLoop();
$loop->subscribe($channel);

$received = 0;
foreach ($loop as $msg) {
    if ($msg->kind === 'subscribe') {
        file_put_contents($logPath, "[subscribe] subscribed: {$msg->channel}\n", FILE_APPEND | LOCK_EX);
        continue;
    }
    if ($msg->kind !== 'message') { continue; }
    $arrival = microtime(true);
    file_put_contents(
        $logPath,
        sprintf("[recv] arrival=%.6f payload=%s\n", $arrival, $msg->payload),
        FILE_APPEND | LOCK_EX,
    );
    $received++;
    if ($received >= $count) { $loop->unsubscribe(); break; }
}
file_put_contents($logPath, "[done] received $received / $count\n", FILE_APPEND | LOCK_EX);
