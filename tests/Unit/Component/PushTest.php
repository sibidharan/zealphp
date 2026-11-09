<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\ComponentException;
use ZealPHP\Component\Push;
use ZealPHP\Component\Snapshot;
use ZealPHP\RequestContext;

/** Push tokens, per-worker connections, invalidation delivery and the page root. */
class PushTest extends TestCase
{
    private const KEY = 'push-test';

    /** @var list<array{string, string}> connection id → message */
    private array $sent = [];

    protected function setUp(): void
    {
        App::superglobals(false);
        AppKey::set(self::key(self::KEY));
        Push::reset();
        Push::configure(coalesce: 0);
        Snapshot::maxAge(0);
        $this->asSession('sess-a');
        $this->sent = [];
    }

    protected function tearDown(): void
    {
        Push::reset();
        AppKey::reset();
        Snapshot::maxAge(0);
        App::devReload(false);
        putenv('ZEALPHP_COMPONENT_PUSH');
    }

    private static function key(string $seed): string
    {
        return 'base64:' . base64_encode(hash('sha256', $seed, true));
    }

    private function asSession(string $sid): void
    {
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => $sid];
        $g->session_params = ['session_id' => $sid];
    }

    /** A connection whose sends are recorded; `$alive` false simulates a closed client. */
    private function connect(string $connId, array $subs, bool $alive = true): void
    {
        Push::connect($connId, $subs, function (string $msg) use ($connId, $alive): bool {
            $this->sent[] = [$connId, $msg];
            return $alive;
        });
    }

    private static function state(): \ZealPHP\Component\PushState
    {
        $state = (new \ReflectionProperty(Push::class, 'state'))->getValue();
        self::assertInstanceOf(\ZealPHP\Component\PushState::class, $state);
        return $state;
    }

    /** @return array<string, mixed> the signed JSON inside a token */
    private static function payload(string $token): array
    {
        $json = base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true);
        $data = json_decode((string) $json, true);
        self::assertIsArray($data);
        return $data;
    }

    private static function signRaw(string $json): string
    {
        $mac = hash_hmac('sha256', $json, AppKey::derive('component-push'), true);
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64($json) . '.' . $b64($mac);
    }

    /** Sign arbitrary token data the way Push::token() does. */
    private static function sign(array $data, ?string $seed = null): string
    {
        $json = (string) json_encode($data);
        if ($seed !== null) {
            AppKey::set(self::key($seed));
        }
        $mac = hash_hmac('sha256', $json, AppKey::derive('component-push'), true);
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64($json) . '.' . $b64($mac);
    }

    private function assertRefused(int $code, string $token): void
    {
        try {
            Push::verify($token);
            $this->fail("expected a {$code}");
        } catch (ComponentException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    // ── page root ─────────────────────────────────────────────────

    public function testRootIsEmptyWithoutTopics(): void
    {
        Push::recordRendered('c1', 'counter', []);
        $this->assertSame('', (string) Push::root());
    }

    public function testRootCarriesTransportIdsAndAVerifiableToken(): void
    {
        Push::recordRendered('likes-1', 'likes', ['demo:likes', 'demo:likes']);
        Push::recordRendered('feed-1', 'feed', ['feed']);
        $html = (string) Push::root();

        $this->assertMatchesRegularExpression('#src="/_zeal/c/client\.js\?v=[0-9a-f]{12}"#', $html);
        $this->assertStringContainsString('data-zc-push="ws"', $html, 'outside a coroutine SSE cannot stream');
        $this->assertStringContainsString('data-zc-ids="likes-1 feed-1"', $html);
        $this->assertSame(1, preg_match('/data-zc-token="([^"]+)"/', $html, $m));
        $this->assertSame(['likes-1' => ['demo:likes'], 'feed-1' => ['feed']], Push::verify($m[1]));
    }

    public function testRootMarkupAndTokenAreExact(): void
    {
        Push::recordRendered('likes-1', 'likes', ['x', 'x', 'y']);
        $html = (string) Push::root();
        $version = substr((string) hash_file('sha256', ZEALPHP_ROOT . '/src/Component/client.js'), 0, 12);
        $this->assertSame(1, preg_match(
            '#^<script src="/_zeal/c/client\.js\?v=' . $version . '" defer data-zc-push="ws" data-zc-token="([^"]+)" data-zc-ids="likes-1"></script>$#',
            $html,
            $m
        ), $html);
        $data = self::payload($m[1]);
        $this->assertSame(['likes-1' => ['n' => 'likes', 't' => ['x', 'y']]], $data['c']);
        $this->assertSame(Snapshot::currentBinding(), $data['s']);
    }

    public function testRootUsesTheConfiguredTransportInACoroutine(): void
    {
        Push::configure('sse');
        $html = '';
        \OpenSwoole\Coroutine::run(function () use (&$html) {
            $this->asSession('sess-a');
            Push::recordRendered('c1', 'n', ['x']);
            $html = (string) Push::root();
        });
        $this->assertStringContainsString('data-zc-push="sse"', $html);
    }

    public function testTransportFollowsEnvUnlessConfigured(): void
    {
        $this->assertSame('ws', Push::transport());
        putenv('ZEALPHP_COMPONENT_PUSH=sse');
        $this->assertSame('sse', Push::transport());
        putenv('ZEALPHP_COMPONENT_PUSH=bogus');
        $this->assertSame('ws', Push::transport());
        putenv('ZEALPHP_COMPONENT_PUSH=sse');
        Push::configure('ws');
        $this->assertSame('ws', Push::transport());
    }

    public function testConnectionLimitBoundsAndDefault(): void
    {
        Push::configure(maxConnections: 1);
        $this->connect('only', []);
        $this->assertSame(1, Push::connectionCount());

        Push::reset();
        for ($i = 0; $i < 1000; $i++) {
            Push::connect("c{$i}", [], static fn(string $m): bool => true);
        }
        $this->expectException(ComponentException::class);
        Push::connect('one-too-many', [], static fn(string $m): bool => true);
    }

    public function testConfigureValidates(): void
    {
        foreach ([['transport' => 'poll'], ['maxConnections' => 0], ['coalesce' => -1.0]] as $args) {
            try {
                Push::configure(...$args);
                $this->fail('expected InvalidArgumentException for ' . json_encode($args));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidTopicsAreRejected(): void
    {
        App::devReload(true);
        foreach (['', 'has space', str_repeat('a', 129), 'a/b', "x\n"] as $topic) {
            try {
                Push::recordRendered('c', 'n', [$topic]);
                $this->fail("topic accepted: '{$topic}'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Push::invalidate('ok', 'not ok');
    }

    public function testInvalidTopicsAreDroppedOutsideDevMode(): void
    {
        Push::recordRendered('c1', 'n', ['bad topic', 'ok']);
        Push::recordRendered('c2', 'n', ['also bad']);
        $html = (string) Push::root();
        $this->assertStringContainsString('data-zc-ids="c1"', $html);
        preg_match('/data-zc-token="([^"]+)"/', $html, $m);
        $this->assertSame(['c1' => ['ok']], Push::verify($m[1]));
    }

    // ── tokens ────────────────────────────────────────────────────

    public function testTokenRoundTrips(): void
    {
        $subs = ['a' => ['n' => 'likes', 't' => ['x', 'y']]];
        $token = Push::token($subs);
        $this->assertSame(['a' => ['x', 'y']], Push::verify($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token, 'URL-safe, no padding');
    }

    public function testMalformedTokensAre400(): void
    {
        $good = Push::token(['a' => ['n' => 'likes', 't' => ['x']]]);
        [$body, $mac] = explode('.', $good);
        $this->assertRefused(400, 'no-dot');
        $this->assertRefused(400, 'a.b.c');
        $this->assertRefused(400, '!!!.' . $mac);
        $this->assertRefused(400, $body . '.' . $mac . 'A' . str_repeat('A', 16384));
        $this->assertRefused(400, $body . 'x.' . $mac);
        $this->assertRefused(400, self::sign(['s' => Snapshot::currentBinding(), 't' => 'now', 'c' => []]));
        $this->assertRefused(400, self::sign(['s' => Snapshot::currentBinding(), 't' => time(), 'c' => ['a' => 'x']]));
    }

    public function testTokenSizeLimit(): void
    {
        $build = static function (int $pad): string {
            return self::sign(['s' => Snapshot::currentBinding(), 't' => time(), 'c' => ['a' => ['n' => str_repeat('n', $pad), 't' => ['x']]]]);
        };
        $pad = 11000;
        while (strlen($build($pad)) < 16384) {
            $pad++;
        }
        $this->assertSame(16384, strlen($build($pad)), 'a token of exactly the limit');
        $this->assertSame(['a' => ['x']], Push::verify($build($pad)));
        $this->assertRefused(400, $build($pad + 3));
    }

    public function testMalformedTokenShapesAre400(): void
    {
        $s = Snapshot::currentBinding();
        foreach ([
            5,
            ['s' => 1, 't' => time(), 'c' => []],
            ['s' => $s, 't' => time()],
            ['s' => $s, 't' => time(), 'c' => 'x'],
            ['s' => $s, 't' => time(), 'c' => [['n' => 'l', 't' => ['x']]]], // a list: integer ids
            ['s' => $s, 't' => time(), 'c' => ['a' => ['n' => 'l']]],
        ] as $data) {
            $this->assertRefused(400, self::sign(is_array($data) ? $data : [$data]));
        }
        $this->assertRefused(400, self::signRaw('5'));
    }

    public function testTokenFromAnotherSessionIs409(): void
    {
        $token = Push::token(['a' => ['n' => 'likes', 't' => ['x']]]);
        $this->asSession('sess-b');
        $this->assertRefused(409, $token);
    }

    public function testTokensDoNotExpireOnTheirOwn(): void
    {
        // A push carries ids only; the refresh re-checks the snapshot's age.
        Snapshot::maxAge(60);
        $data = ['s' => Snapshot::currentBinding(), 'c' => ['a' => ['n' => 'l', 't' => ['x']]]];
        $this->assertSame(['a' => ['x']], Push::verify(self::sign($data + ['t' => time() - 86400 * 30])));
    }

    public function testPreviousKeyStillVerifies(): void
    {
        $token = Push::token(['a' => ['n' => 'likes', 't' => ['x']]]);
        AppKey::set(self::key('rotated'), self::key(self::KEY));
        $this->assertSame(['a' => ['x']], Push::verify($token));
        AppKey::set(self::key('rotated'));
        $this->assertRefused(400, $token);
    }

    public function testTokenFromBeforeAnEphemeralRestartIs409(): void
    {
        AppKey::useEphemeral();
        $token = Push::token(['a' => ['n' => 'likes', 't' => ['x']]]);
        AppKey::reset();
        AppKey::useEphemeral();
        $this->assertRefused(409, $token);
    }

    public function testVerifyDropsInvalidTopicsAndEmptyComponents(): void
    {
        $token = self::sign(['s' => Snapshot::currentBinding(), 't' => time(), 'c' => [
            'a' => ['n' => 'l', 't' => ['not ok', 'ok', 7]],
            'b' => ['n' => 'l', 't' => ['bad topic']],
        ]]);
        $this->assertSame(['a' => ['ok']], Push::verify($token));
    }

    // ── delivery ──────────────────────────────────────────────────

    public function testInvalidateDeliversSubscribedIdsOnly(): void
    {
        $this->connect('c1', ['likes-1' => ['likes'], 'feed-1' => ['feed']]);
        $this->connect('c2', ['feed-2' => ['feed']]);

        Push::invalidate('likes');
        $this->assertSame([['c1', '["likes-1"]']], $this->sent);

        $this->sent = [];
        Push::invalidate('feed', 'feed', 'unknown');
        $this->assertSame([['c1', '["feed-1"]'], ['c2', '["feed-2"]']], $this->sent);
    }

    public function testDeliveryCoalescesUntilFlushed(): void
    {
        $this->connect('c1', ['a' => ['x'], 'b' => ['y']]);
        Push::configure(coalesce: 1.0);
        // Outside a coroutine there is no timer, so delivery is immediate;
        // queue directly and flush by hand to observe coalescing.
        $state = (new \ReflectionProperty(Push::class, 'state'))->getValue();
        $this->assertInstanceOf(\ZealPHP\Component\PushState::class, $state);
        $state->connections['c1']['pending'] = ['a' => true, 'b' => true];
        $state->connections['c1']['scheduled'] = true;
        Push::deliverLocal(['x']);
        $this->assertSame([], $this->sent, 'already scheduled: queued, not sent');

        Push::flush('c1');
        $this->assertSame([['c1', '["a","b"]']], $this->sent);
        Push::flush('c1');
        Push::flush('missing');
        $this->assertCount(1, $this->sent, 'nothing pending: no empty message');
    }

    public function testClosedClientIsDisconnected(): void
    {
        $this->connect('gone', ['a' => ['x']], alive: false);
        $this->assertSame(1, Push::connectionCount());
        Push::invalidate('x');
        $this->assertSame(0, Push::connectionCount());
        Push::invalidate('x');
        $this->assertCount(1, $this->sent);
    }

    public function testDisconnectUnsubscribes(): void
    {
        $this->connect('c1', ['a' => ['x', 'y']]);
        $this->connect('c2', ['b' => ['x']]);
        Push::disconnect('c1');
        Push::disconnect('c1');
        $this->assertSame(['x' => ['c2' => true]], self::state()->byTopic);
        Push::invalidate('x', 'y');
        $this->assertSame([['c2', '["b"]']], $this->sent);
        $state = (new \ReflectionProperty(Push::class, 'state'))->getValue();
        $this->assertInstanceOf(\ZealPHP\Component\PushState::class, $state);
        $this->assertSame(['x' => ['c2' => true]], $state->byTopic);
    }

    public function testReusedConnectionIdReplacesTheOldOne(): void
    {
        // A WebSocket fd can come back for a new connection: the old topics must go.
        $this->connect('ws:7', ['old' => ['a']]);
        $this->connect('ws:7', ['new' => ['b']]);
        $this->assertSame(1, Push::connectionCount());
        $this->assertSame(['b' => ['ws:7' => true]], self::state()->byTopic);
        Push::invalidate('a');
        $this->assertSame([], $this->sent);
        Push::invalidate('b');
        $this->assertSame([['ws:7', '["new"]']], $this->sent);
    }

    public function testTopicsOfAVanishedConnectionAreSkipped(): void
    {
        $this->connect('7', ['gone' => ['x']]); // a numeric id becomes an integer array key
        $this->connect('c2', ['kept' => ['x']]);
        unset(self::state()->connections['7']);
        Push::invalidate('x');
        $this->assertSame([['c2', '["kept"]']], $this->sent, 'the live connection after it still gets it');
        $this->assertSame(['x' => ['c2' => true]], self::state()->byTopic);
        Push::disconnect('c2');
        $this->sent = [];

        $this->connect('c1', ['a' => ['x']]);
        $state = (new \ReflectionProperty(Push::class, 'state'))->getValue();
        $this->assertInstanceOf(\ZealPHP\Component\PushState::class, $state);
        unset($state->connections['c1']); // as if it vanished without a disconnect()
        Push::invalidate('x');
        $this->assertSame([], $this->sent);
        $this->assertSame([], $state->byTopic, 'the dangling subscription is cleaned up');
        $this->assertSame(0, Push::connectionCount(), 'no phantom connection is created');
    }

    public function testCoroutineDeliveryIsCoalescedAndFanOutBatched(): void
    {
        Push::configure(coalesce: 0.02);
        $seen = [];
        \OpenSwoole\Coroutine::run(function () use (&$seen) {
            $this->connect('c1', ['a' => ['x'], 'b' => ['y']]);
            Push::invalidate('x');
            Push::invalidate('y', 'x');
            $seen['sentNow'] = $this->sent;
            $seen['outbox'] = self::state()->outbox;
            $seen['scheduled'] = self::state()->outboxScheduled;
            \OpenSwoole\Coroutine::usleep(60_000);
            $seen['sentLater'] = $this->sent;
            $seen['outboxLater'] = self::state()->outbox;
        });
        $this->assertSame([], $seen['sentNow'], 'nothing sent before the coalesce window');
        $this->assertSame(['x' => true, 'y' => true], $seen['outbox'], 'one batch for the other workers');
        $this->assertTrue($seen['scheduled']);
        $this->assertSame([['c1', '["a","b"]']], $seen['sentLater'], 'one message with both ids');
        $this->assertSame([], $seen['outboxLater']);
    }

    public function testCoroutineDeliveryWithoutCoalescingIsImmediate(): void
    {
        $sent = [];
        \OpenSwoole\Coroutine::run(function () use (&$sent) {
            $this->connect('c1', ['a' => ['x']]);
            Push::invalidate('x');
            $sent = $this->sent;
        });
        $this->assertSame([['c1', '["a"]']], $sent);
    }

    public function testDelayMs(): void
    {
        $this->assertSame(50, Push::delayMs(0.05));
        $this->assertSame(1500, Push::delayMs(1.5));
        $this->assertSame(1, Push::delayMs(0.0004), 'never 0: Timer::after() needs >= 1');
        $this->assertSame(3, Push::delayMs(0.0026), 'rounded, not floored');
        $this->assertSame(2, Push::delayMs(0.0024), 'rounded, not ceiled');
    }

    public function testDrainOutboxBuildsOneMessage(): void
    {
        $this->assertNull(Push::drainOutbox());
        $state = self::state();
        $state->outbox = ['123' => true, 'b' => true];
        $state->outboxScheduled = true;
        $payload = Push::drainOutbox();
        $this->assertIsString($payload);
        $this->assertSame(
            ['topics' => ['123', 'b'], 'origin' => gethostname() . ':' . getmypid()],
            json_decode($payload, true)
        );
        $this->assertSame([], $state->outbox);
        $this->assertFalse($state->outboxScheduled);
    }

    public function testOutboxIsSentImmediatelyOutsideACoroutine(): void
    {
        Push::invalidate('x', 'y');
        $state = (new \ReflectionProperty(Push::class, 'state'))->getValue();
        $this->assertInstanceOf(\ZealPHP\Component\PushState::class, $state);
        $this->assertSame([], $state->outbox);
        $this->assertFalse($state->outboxScheduled);
        Push::sendOutbox(); // empty: nothing to send, no error
        $this->assertSame([], $state->outbox);
    }

    public function testConnectionLimitIs503(): void
    {
        Push::configure(maxConnections: 2);
        $this->connect('c1', []);
        $this->connect('c2', []);
        try {
            $this->connect('c3', []);
            $this->fail('expected 503');
        } catch (ComponentException $e) {
            $this->assertSame(503, $e->getCode());
        }
        Push::disconnect('c1');
        $this->connect('c3', []);
        $this->assertSame(2, Push::connectionCount());
    }

    public function testReceiveDeliversPipedTopicsAndSkipsOwnRedisEcho(): void
    {
        $this->connect('c1', ['a' => ['x']]);
        $origin = gethostname() . ':' . getmypid(); // no server: host + this process

        Push::receive((string) json_encode(['topics' => ['x', 'bad topic', 3], 'origin' => $origin]));
        $this->assertSame([['c1', '["a"]']], $this->sent, 'pipe from a sibling worker');

        $this->sent = [];
        Push::receive((string) json_encode(['topics' => ['x'], 'origin' => $origin, 'via' => 'redis']));
        $this->assertSame([], $this->sent, 'own node, already delivered by pipe');

        Push::receive((string) json_encode(['topics' => ['x'], 'origin' => 'other:1', 'via' => 'redis']));
        $this->assertSame([['c1', '["a"]']], $this->sent, 'another node');

        $this->sent = [];
        Push::receive((string) json_encode(['topics' => [['x'], 'x'], 'origin' => 'other:2']));
        $this->assertSame([['c1', '["a"]']], $this->sent, 'non-string topics are ignored');
        $this->sent = [];
        Push::receive('not json');
        Push::receive('{"topics":"x"}');
        $this->assertSame([], $this->sent);
    }

    public function testNoServerAndTableBackendMeanLocalDeliveryOnly(): void
    {
        $this->assertFalse(Push::nodesShareRedis());
        $this->connect('c1', ['a' => ['x']]);
        Push::invalidate();
        Push::invalidate('x');
        $this->assertSame([['c1', '["a"]']], $this->sent);
    }
}
