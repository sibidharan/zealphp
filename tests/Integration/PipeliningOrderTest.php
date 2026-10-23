<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * HTTP/1.1 pipelining: responses on one connection must come back in request
 * order (RFC 9112 §9.3.2). OpenSwoole runs each pipelined request in its own
 * coroutine, so before App::inConnectionOrder() the replies arrived in
 * completion order and a client paired every response with the wrong request.
 *
 * Fixture: route/_pipeline_test.php
 */
final class PipeliningOrderTest extends TestCase
{
    public function testPipelinedResponsesArriveInRequestOrder(): void
    {
        $n = 6;
        $sock = @stream_socket_client('tcp://' . TEST_SERVER_HOST . ':' . TEST_SERVER_PORT, $errno, $errstr, 5);
        if ($sock === false) {
            $this->markTestSkipped("test server not reachable: $errstr");
        }
        stream_set_timeout($sock, 10);

        // First request sleeps longest: completion order would be reversed.
        $wire = '';
        for ($i = 0; $i < $n; $i++) {
            $ms = ($n - $i) * 30;
            $wire .= "GET /__pipeline_test/echo?n=$i&ms=$ms HTTP/1.1\r\nHost: " . TEST_SERVER_HOST . "\r\n\r\n";
        }
        fwrite($sock, $wire);

        $order = [];
        $buf = '';
        while (count($order) < $n && !feof($sock)) {
            $chunk = fread($sock, 65536);
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($sock)['timed_out']) {
                    break;
                }
                continue;
            }
            $buf .= $chunk;
            while (($h = strpos($buf, "\r\n\r\n")) !== false) {
                $len = preg_match('/^content-length:\s*(\d+)/mi', substr($buf, 0, $h), $m) ? (int) $m[1] : 0;
                if (strlen($buf) < $h + 4 + $len) {
                    break;
                }
                $body = json_decode(substr($buf, $h + 4, $len), true);
                $order[] = is_array($body) ? ($body['n'] ?? '?') : '?';
                $buf = (string) substr($buf, $h + 4 + $len);
            }
        }
        fclose($sock);

        $this->assertSame(array_map('strval', range(0, $n - 1)), $order,
            'pipelined responses were not returned in request order');
    }
}
