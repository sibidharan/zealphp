<?php
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;

Store::make('convert_ratelimit', 256, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);

Store::make('convert_cache', 128, [
    'hash'   => [\OpenSwoole\Table::TYPE_STRING, 32],
    'result' => [\OpenSwoole\Table::TYPE_STRING, 8192],
    'time'   => [\OpenSwoole\Table::TYPE_INT, 4],
]);

$app = App::instance();

$app->route('/api/convert', ['methods' => ['POST']], function($request, $response) {
    $g = G::instance();

    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    $window = 600;
    $limit = 5;

    $body = $g->zealphp_request->parent->getContent();
    $input = json_decode($body, true);
    $config = trim($input['config'] ?? '');

    if (empty($config) || strlen($config) > 10000) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "data: // Error: Input empty or too large (max 10KB)\n\n";
        echo "data: [DONE]\n\n";
        return;
    }

    $hash = md5($config);

    // Check cache first (no rate limit hit for cached results)
    $cached = Store::get('convert_cache', $hash);
    if ($cached && ($now - $cached['time']) < 3600) {
        $response->sse(function($emit) use ($cached) {
            foreach (explode("\n", $cached['result']) as $line) {
                $emit($line, 'chunk');
            }
        });
        return;
    }

    // Rate limit (only for uncached/AI calls)
    $existing = Store::get('convert_ratelimit', $ip);
    if ($existing) {
        if ($now < $existing['reset']) {
            if ($existing['count'] >= $limit) {
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                echo "data: // Rate limit exceeded. Try again in " . ($existing['reset'] - $now) . " seconds.\n\n";
                echo "data: [DONE]\n\n";
                return;
            }
            Store::incr('convert_ratelimit', $ip, 'count', 1);
        } else {
            Store::set('convert_ratelimit', $ip, [
                'ip' => $ip, 'count' => 1, 'reset' => $now + $window,
            ]);
        }
    } else {
        Store::set('convert_ratelimit', $ip, [
            'ip' => $ip, 'count' => 1, 'reset' => $now + $window,
        ]);
    }

    $response->sse(function($emit) use ($config, $hash) {
        $b64 = base64_encode($config);
        $converter = App::$cwd . '/examples/agents/convert_sse.py';
        $cmd = 'uv run ' . escapeshellarg($converter) . ' ' . escapeshellarg($b64);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            $emit('// Error: Failed to start converter', 'error');
            return;
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        $buffer = '';
        $fullOutput = '';
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk === false || $chunk === '') {
                usleep(50000);
                continue;
            }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if ($line === '__DONE__') {
                    // Cache the result (up to 8KB)
                    if (strlen($fullOutput) < 8192) {
                        Store::set('convert_cache', $hash, [
                            'hash' => $hash,
                            'result' => $fullOutput,
                            'time' => time(),
                        ]);
                    }
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    return;
                }
                $emit($line, 'chunk');
                $fullOutput .= $line . "\n";
            }
        }
        if (!empty(trim($buffer)) && $buffer !== '__DONE__') {
            $emit($buffer, 'chunk');
            $fullOutput .= $buffer;
        }
        if (strlen($fullOutput) < 8192) {
            Store::set('convert_cache', $hash, [
                'hash' => $hash,
                'result' => $fullOutput,
                'time' => time(),
            ]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    });
});
