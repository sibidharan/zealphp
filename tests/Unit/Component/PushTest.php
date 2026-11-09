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
        $this->assertSame(['a' => ['x', 'y']], Push::verify(Push::token($subs)));
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
            'a' => ['n' => 'l', 't' => ['ok', 'not ok', 7]],
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
        Push::invalidate('a');
        $this->assertSame([], $this->sent);
        Push::invalidate('b');
        $this->assertSame([['ws:7', '["new"]']], $this->sent);
    }

    public function testTopicsOfAVanishedConnectionAreSkipped(): void
    {
        $this->connect('c1', ['a' => ['x']]);
        $state = (new \ReflectionProperty(Push::class, 'state'))->getValue();
        $this->assertInstanceOf(\ZealPHP\Component\PushState::class, $state);
        unset($state->connections['c1']); // as if it vanished without a disconnect()
        Push::invalidate('x');
        $this->assertSame([], $this->sent);
        $this->assertSame([], $state->byTopic, 'the dangling subscription is cleaned up');
        $this->assertSame(0, Push::connectionCount(), 'no phantom connection is created');
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
        $origin = (new \ReflectionMethod(Push::class, 'origin'))->invoke(null);

        Push::receive((string) json_encode(['topics' => ['x', 'bad topic', 3], 'origin' => $origin]));
        $this->assertSame([['c1', '["a"]']], $this->sent, 'pipe from a sibling worker');

        $this->sent = [];
        Push::receive((string) json_encode(['topics' => ['x'], 'origin' => $origin, 'via' => 'redis']));
        $this->assertSame([], $this->sent, 'own node, already delivered by pipe');

        Push::receive((string) json_encode(['topics' => ['x'], 'origin' => 'other:1', 'via' => 'redis']));
        $this->assertSame([['c1', '["a"]']], $this->sent, 'another node');

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
