<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Component push over real HTTP, against the shared-likes demo on
 * /components: a page's SSE stream receives the id of its component when
 * another visitor's action invalidates the topic it depends on.
 */
class ComponentPushTest extends TestCase
{
    /** @return array{cookie: string, token: string, snapshot: string, transport: string} */
    private function page(): array
    {
        $r = $this->get('/components');
        $this->assertStatus(200, $r);
        if (str_contains($r['body'], 'Demo disabled.')) {
            $this->markTestSkipped('Server runs without ZEALPHP_APP_KEY; the reactive components demo is disabled');
        }
        $this->assertSame(1, preg_match('/<script src="\/_zeal\/c\/client\.js\?v=[0-9a-f]+"[^>]*>/', $r['body'], $tag), 'push root on the page');
        preg_match('/data-zc-push="([a-z]+)"/', $tag[0], $mode);
        preg_match('/data-zc-token="([^"]+)"/', $tag[0], $token);
        $this->assertStringContainsString('data-zc-ids="shared-likes-demo"', $tag[0]);
        preg_match('/id="shared-likes-demo".*?name="_zc" value="([^"]+)"/s', $r['body'], $snap);
        preg_match('/PHPSESSID=([^;,\s]+)/', $r['headers']['set-cookie'] ?? '', $sid);
        return [
            'cookie' => isset($sid[1]) ? 'PHPSESSID=' . $sid[1] : '',
            'token' => html_entity_decode($token[1], ENT_QUOTES),
            'snapshot' => html_entity_decode($snap[1], ENT_QUOTES),
            'transport' => $mode[1],
        ];
    }

    public function testClientScriptIsServed(): void
    {
        $r = $this->get('/_zeal/c/client.js');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('javascript', $r['headers']['content-type'] ?? '');
        $this->assertStringContainsString('EventSource', $r['body']);
    }

    public function testStreamRefusesBadTokens(): void
    {
        $this->assertStatus(400, $this->get('/_zeal/c/stream?t=nope'));
        $this->assertStatus(400, $this->get('/_zeal/c/stream'));
    }

    public function testStreamFromAnotherSessionIsStale(): void
    {
        $page = $this->page();
        if ($page['cookie'] === '') {
            $this->markTestSkipped('No session cookie issued; the token is not session-bound');
        }
        // EventSource can't read a status, so "stale" comes as an event.
        $r = $this->get('/_zeal/c/stream?t=' . rawurlencode($page['token']), ['Cookie' => 'PHPSESSID=' . str_repeat('b', 26)]);
        $this->assertStatus(200, $r);
        $this->assertStringContainsString("event: zc-stale\n", $r['body']);
        $this->assertStringNotContainsString('zc-ready', $r['body']);
    }

    public function testAnotherVisitorsActionReachesTheStream(): void
    {
        // Both endpoints are always served; the setting only picks the client's.
        $viewer = $this->page();
        $actor = $this->page();

        $mh = curl_multi_init();
        $body = '';
        $stream = curl_init(self::$baseUrl . '/_zeal/c/stream?t=' . rawurlencode($viewer['token']));
        curl_setopt_array($stream, [
            CURLOPT_HTTPHEADER => ['Accept: text/event-stream', 'Cookie: ' . $viewer['cookie']],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($mh, $stream);

        $run = function (callable $until) use ($mh): void {
            $deadline = microtime(true) + 5;
            do {
                curl_multi_exec($mh, $active);
                curl_multi_select($mh, 0.05);
            } while (!$until() && $active > 0 && microtime(true) < $deadline);
        };
        $run(static function () use (&$body): bool { return str_contains($body, 'zc-ready'); });
        $this->assertStringContainsString('event: zc-ready', $body);
        $this->assertMatchesRegularExpression('/^retry: \d+$/m', $body, 'a jittered reconnect delay');

        // A different visitor likes: the viewer's stream gets the component id.
        $like = $this->post('/_zeal/c/shared-likes/like', [
            'HX-Request' => 'true',
            'Cookie' => $actor['cookie'],
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query(['_zc' => $actor['snapshot'], '_zc_args' => '{}']));
        $this->assertStatus(200, $like);

        $run(static function () use (&$body): bool { return str_contains($body, 'event: zc' . "\n"); });
        curl_multi_remove_handle($mh, $stream);
        curl_close($stream);
        curl_multi_close($mh);

        $this->assertMatchesRegularExpression('/event: zc\ndata: \["shared-likes-demo"\]/', $body);
    }
    /**
     * Run $fn with a WebSocket client upgraded to the push socket.
     *
     * @return mixed
     */
    private function socket(string $token, string $cookie, \Closure $fn): mixed
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('openswoole extension required for the WebSocket client');
        }
        $ret = null;
        $err = null;
        \OpenSwoole\Coroutine::run(function () use ($token, $cookie, $fn, &$ret, &$err) {
            $cli = new \OpenSwoole\Coroutine\Http\Client(TEST_SERVER_HOST, TEST_SERVER_PORT);
            $cli->set(['timeout' => 8.0]);
            if ($cookie !== '') {
                $cli->setHeaders(['Cookie' => $cookie]);
            }
            try {
                $this->assertTrue($cli->upgrade('/_zeal/c/ws?t=' . rawurlencode($token)), 'upgrade');
                $ret = $fn($cli);
            } catch (\Throwable $e) {
                $err = $e;
            } finally {
                $cli->close();
            }
        });
        if ($err !== null) {
            throw $err;
        }
        return $ret;
    }

    public function testSocketRefusesABadToken(): void
    {
        $close = $this->socket('nope', '', static fn($cli) => $cli->recv(2.0));
        $this->assertSame(\OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_CLOSE, $close->opcode ?? null);
        $this->assertSame(4400, $close->code ?? null);
    }

    public function testAnotherVisitorsActionReachesTheSocket(): void
    {
        $viewer = $this->page();
        $actor = $this->page();
        [$accepted, $frame] = $this->socket($viewer['token'], $viewer['cookie'], function ($cli) use ($actor) {
            $accepted = $cli->recv(3.0); // '[]' once the token is verified and the connection registered
            $like = new \OpenSwoole\Coroutine\Http\Client(TEST_SERVER_HOST, TEST_SERVER_PORT);
            $like->setHeaders([
                'HX-Request' => 'true',
                'Cookie' => $actor['cookie'],
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]);
            $like->post('/_zeal/c/shared-likes/like', http_build_query(['_zc' => $actor['snapshot'], '_zc_args' => '{}']));
            $this->assertSame(200, $like->statusCode);
            $like->close();
            return [$accepted, $cli->recv(3.0)];
        });
        $this->assertSame('[]', $accepted->data ?? null);
        $this->assertSame('["shared-likes-demo"]', $frame->data ?? null);
    }
}
