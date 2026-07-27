<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Session\Handler\RedisSessionHandler;

/**
 * Unit tests for ZealPHP\Session\Handler\RedisSessionHandler.
 *
 * The class implements PHP's \SessionHandlerInterface against a Redis backend
 * using the `PHPREDIS_SESSION:{id}` key format (phpredis-compatible). Tests
 * split into two sets:
 *
 *  1. Class-shape contract tests — verify the type satisfies the
 *     SessionHandlerInterface API even without ext-redis loaded. These
 *     guard against accidental signature drift.
 *
 *  2. Connected behaviour tests — skipped automatically when ext-redis is
 *     absent OR when no Redis is reachable at 127.0.0.1:6379. These cover
 *     read/write/destroy round-trips against a live instance.
 *
 * Production deployments should run the connected set as part of an
 * integration job that boots Redis as a docker service.
 */
class RedisSessionHandlerTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Class-shape contract — no ext-redis required
    // ──────────────────────────────────────────────────────────────

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(RedisSessionHandler::class));
    }

    public function testImplementsSessionHandlerInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(RedisSessionHandler::class, \SessionHandlerInterface::class)
        );
    }

    public function testConstructorDoesNotConnectEagerly(): void
    {
        // #271 — the constructor must NOT call connect(). Under HOOK_ALL,
        // \Redis->connect() is a coroutine API, so connecting at construction
        // (a non-coroutine point — boot / middleware registration with
        // sessionLifecycle(false)) fataled "API must be called in the coroutine".
        // Construction with an unreachable host must succeed without touching the
        // socket; the connection is established lazily on first redis() use.
        $handler = new RedisSessionHandler('192.0.2.1', 6379); // TEST-NET-1 (non-routable)
        $ref = new \ReflectionProperty($handler, 'fallback');
        $ref->setAccessible(true);
        $this->assertNull(
            $ref->getValue($handler),
            'constructor must not establish the fallback connection (#271)'
        );
    }

    public function testExposesAllSessionHandlerInterfaceMethods(): void
    {
        $required = ['open', 'close', 'read', 'write', 'destroy', 'gc'];
        foreach ($required as $method) {
            $this->assertTrue(
                method_exists(RedisSessionHandler::class, $method),
                "RedisSessionHandler is missing required method: $method"
            );
        }
    }

    public function testHasGetRedisAccessor(): void
    {
        $this->assertTrue(
            method_exists(RedisSessionHandler::class, 'getRedis'),
            'getRedis() accessor is part of the documented surface'
        );
    }

    public function testConstructorSignatureAcceptsDefaults(): void
    {
        // Verify the constructor's parameter list — pure reflection, no
        // instantiation needed (which would trigger Redis connect()).
        $ref = new \ReflectionMethod(RedisSessionHandler::class, '__construct');
        $params = $ref->getParameters();
        $this->assertCount(5, $params, 'constructor should take host, port, prefix, ttl, auth');
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $params);
        // #494: `auth` is appended LAST and defaulted, so the pre-existing
        // positional order is untouched and 4-argument call sites keep working.
        $this->assertSame(['host', 'port', 'prefix', 'ttl', 'auth'], $names);
        foreach ($params as $p) {
            $this->assertTrue($p->isDefaultValueAvailable(), "{$p->getName()} should have a default");
        }
        $this->assertNull(
            $params[4]->getDefaultValue(),
            'auth must default to null — sending AUTH to a passwordless server is an error'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Connected behaviour — skipped unless Redis is reachable
    // ──────────────────────────────────────────────────────────────

    private function skipIfNoRedis(): RedisSessionHandler
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded — connected tests skipped.');
        }
        // #271 — the handler no longer connects in its constructor (the eager
        // connect would fatal under HOOK_ALL outside a coroutine), so the old
        // "construct → catch" probe can't detect an unreachable Redis: the
        // constructor now always succeeds. Probe explicitly with a raw client.
        $ok = false;
        try {
            $probe = new \Redis();
            if (@$probe->connect('127.0.0.1', 6379, 0.5)) {
                $probe->ping();
                $probe->close();
                $ok = true;
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        if ($ok !== true) {
            $this->markTestSkipped('Redis not reachable at 127.0.0.1:6379 — connected tests skipped.');
        }
        return new RedisSessionHandler('127.0.0.1', 6379, 'PHPREDIS_SESSION_TEST:', 60);
    }

    public function testOpenReturnsTrueOnConnected(): void
    {
        $h = $this->skipIfNoRedis();
        $this->assertTrue($h->open('', 'PHPSESSID'));
        $h->close();
    }

    public function testReadOnMissingKeyReturnsEmptyString(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid = 'test_' . bin2hex(random_bytes(8));
        $this->assertSame('', $h->read($sid));
        $h->close();
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid     = 'test_' . bin2hex(random_bytes(8));
        $payload = 'user_id|i:42;name|s:5:"alice";';
        $this->assertTrue($h->write($sid, $payload));
        $this->assertSame($payload, $h->read($sid));
        $h->destroy($sid);
        $h->close();
    }

    public function testBaseDataMapDoesNotGrowUnbounded(): void
    {
        // Regression: the per-instance read→write merge baseline ($baseData) used
        // to be inserted on every read() and never cleared, so on a long-lived
        // singleton handler it grew with every distinct session id forever (a
        // worker-lifetime memory leak). write()/destroy() now drop the entry.
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $prop = new \ReflectionProperty(RedisSessionHandler::class, 'baseData');
        $prop->setAccessible(true);

        for ($i = 0; $i < 25; $i++) {
            $sid = 'leak_' . bin2hex(random_bytes(6));
            $h->read($sid);                       // inserts baseData[$sid]
            $h->write($sid, "k|i:$i;");           // must clear it
            $h->destroy($sid);
        }
        $this->assertSame([], $prop->getValue($h), 'baseData must be empty after read→write→destroy of each session');
        $h->close();
    }

    public function testDestroyRemovesKey(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid = 'test_' . bin2hex(random_bytes(8));
        $h->write($sid, 'tmp|i:1;');
        $this->assertSame('tmp|i:1;', $h->read($sid));
        $this->assertTrue($h->destroy($sid));
        $this->assertSame('', $h->read($sid));
        $h->close();
    }

    public function testKeyPrefixIsApplied(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid = 'test_' . bin2hex(random_bytes(8));
        $h->write($sid, 'x|i:1;');
        // Verify the underlying key includes the prefix — the whole point
        // of the configurable prefix is phpredis compatibility.
        $raw = $h->getRedis()->get('PHPREDIS_SESSION_TEST:' . $sid);
        $this->assertSame('x|i:1;', $raw);
        $h->destroy($sid);
        $h->close();
    }

    public function testGcReturnsZero(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        // Redis handles expiry via TTL — gc() is documented as a no-op.
        $this->assertSame(0, $h->gc(0));
        $h->close();
    }

    public function testPerCoroutineConnectionIsClosedWhenCoroutineEnds(): void
    {
        // #438 — the per-coroutine socket must be closed deterministically via
        // Coroutine::defer() when the coroutine ends, NOT left for context GC.
        // Relying on GC leaked one FD per request under HOOK_ALL (CLOSE-WAIT
        // sockets) until Redis/Valkey's maxclients was exhausted. Drive one
        // coroutine that opens a per-coroutine connection, capture it, and assert
        // it is disconnected once the coroutine has ended.
        $h = $this->skipIfNoRedis();
        $captured = null;
        \OpenSwoole\Coroutine::run(function () use ($h, &$captured): void {
            // io() resolves and stores the per-coroutine \Redis socket; the
            // deferred close is registered alongside it.
            $captured = $h->getRedis();
            $this->assertTrue($captured->isConnected(), 'connection should be live inside the coroutine');
        });
        // After the coroutine ends, the deferred close has run.
        $this->assertInstanceOf(\Redis::class, $captured);
        $this->assertFalse(
            $captured->isConnected(),
            '#438: per-coroutine \Redis socket must be closed when the coroutine ends, not leaked to context GC'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // #494 — AUTH support. The credential decision is a pure protected
    // seam, so these run WITHOUT ext-redis or a live server.
    // ──────────────────────────────────────────────────────────────

    /** Expose the protected seam — subclassing also proves the visibility fix. */
    private function credentialOf(RedisSessionHandler $h): string|array|null
    {
        $probe = new class ('127.0.0.1', 6379) extends RedisSessionHandler {
            public static function credential(RedisSessionHandler $h): string|array|null
            {
                // Same-class scope: a subclass can reach the protected seam.
                return $h->authCredential();
            }
        };
        return $probe::credential($h);
    }

    public function testNoAuthByDefaultSoPasswordlessServersKeepWorking(): void
    {
        // Redis errors on an AUTH sent to a server with no requirepass, so the
        // default MUST stay "send nothing" — otherwise every existing
        // passwordless deployment breaks on upgrade.
        $this->assertNull($this->credentialOf(new RedisSessionHandler()));
    }

    public function testFourArgumentConstructionStillWorks(): void
    {
        // BC: $auth is trailing + defaulted; pre-#494 call sites are unaffected.
        $h = new RedisSessionHandler('127.0.0.1', 6379, 'PHPREDIS_SESSION:', 1440);
        $this->assertNull($this->credentialOf($h));
    }

    public function testPasswordIsUsedAsRequirepassCredential(): void
    {
        $h = new RedisSessionHandler('127.0.0.1', 6379, 'PHPREDIS_SESSION:', 1440, 's3cret');
        $this->assertSame('s3cret', $this->credentialOf($h));
    }

    public function testUserPasswordPairIsPreservedForRedis6AclUsers(): void
    {
        // phpredis takes [user, pass] for an ACL user — the scoped-account case.
        $h = new RedisSessionHandler('127.0.0.1', 6379, 'PHPREDIS_SESSION:', 1440, ['sessions', 's3cret']);
        $this->assertSame(['sessions', 's3cret'], $this->credentialOf($h));
    }

    /**
     * Blank/empty configurations must normalise to null (no AUTH sent) rather
     * than sending an empty credential a server would reject.
     *
     * @return array<string, array{0: string|array<int,mixed>|null}>
     */
    public static function blankCredentialProvider(): array
    {
        return [
            'null'            => [null],
            'empty string'    => [''],
            'empty array'     => [[]],
            'array of blanks' => [['', '']],
        ];
    }

    /**
     * @param string|array<int,mixed>|null $auth
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blankCredentialProvider')]
    public function testBlankCredentialsSendNoAuth(string|array|null $auth): void
    {
        /** @var string|list<string>|null $auth */
        $h = new RedisSessionHandler('127.0.0.1', 6379, 'PHPREDIS_SESSION:', 1440, $auth);
        $this->assertNull($this->credentialOf($h));
    }

    public function testConstructorStillDoesNotConnectEagerlyWithAuth(): void
    {
        // #271 must continue to hold with a credential configured — construction
        // at a non-coroutine point (boot / middleware registration) must not dial.
        $h = new RedisSessionHandler('192.0.2.1', 6379, 'PHPREDIS_SESSION:', 1440, 's3cret');
        $ref = new \ReflectionProperty($h, 'fallback');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($h));
    }

    public function testConnectionSurfaceIsExtendable(): void
    {
        // #494's secondary ask: the connection surface must be overridable so a
        // consumer can add TLS options / timeouts / select() WITHOUT
        // reimplementing SessionHandlerInterface and duplicating the 3-way merge.
        $sub = new class ('10.0.0.9', 6380, 'X:', 60, 'pw') extends RedisSessionHandler {
            public function describe(): string
            {
                // Reaches the protected connection fields from a subclass.
                return "{$this->host}:{$this->port}:{$this->prefix}:{$this->ttl}";
            }
        };
        $this->assertSame('10.0.0.9:6380:X::60', $sub->describe());

        foreach (['connect', 'conn', 'authCredential'] as $method) {
            $this->assertTrue(
                (new \ReflectionMethod(RedisSessionHandler::class, $method))->isProtected(),
                "$method() must be protected so the connection can be extended (#494)"
            );
        }
    }
}
