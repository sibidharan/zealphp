<?php

declare(strict_types=1);

/**
 * Phase 3 cross-HOST spike — publisher side. Fires N PUBLISHes with the
 * send-time embedded in the payload, sleeps a tiny gap between, exits.
 *
 *   php spike-crosshost-publish.php <redis-url> <channel> <count> [gap-ms]
 */

require __DIR__ . '/../vendor/autoload.php';

use Predis\Client as PredisClient;

[$_, $url, $channel, $countStr, $gapStr] = array_pad($argv, 5, null);
$count = (int) $countStr;
$gapMs = (int) ($gapStr ?? 50);
if (!$url || !$channel || $count < 1) {
    fwrite(STDERR, "usage: $argv[0] <redis-url> <channel> <count> [gap-ms]\n");
    exit(2);
}

$client = new PredisClient($url);
$client->connect();

for ($i = 0; $i < $count; $i++) {
    $sentAt = microtime(true);
    $payload = sprintf('%.6f|seq=%d|from=%s', $sentAt, $i + 1, gethostname());
    $client->publish($channel, $payload);
    fwrite(STDOUT, sprintf("[publish] sent=%.6f seq=%d\n", $sentAt, $i + 1));
    usleep($gapMs * 1000);
}

$client->disconnect();
