<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;

/**
 * C2: HMAC-signed invalidation messages.
 *
 * The publishInvalidation()/invalidationHandler() pair must:
 *   - default to NO HMAC (BC; same as pre-C2 wire format)
 *   - sign every outbound message when $invalidationSecret is non-null
 *   - drop inbound messages whose HMAC doesn't match (forgery defense)
 *
 * These tests use reflection to drive the private invalidation handler
 * directly — the actual Redis pub/sub channel isn't required to verify
 * the verification logic; the runner is just a transport.
 */
final class TieredBackendHmacTest extends TestCase
{
    private function makeTiered(?string $secret = null, string $origin = 'me'): TieredBackend
    {
        $l2 = new RedisBackend(new RedisConnectionPool('redis://127.0.0.1:9'));
        $t = new TieredBackend(new TableBackend(), $l2, l1Ttl: 60, originId: $origin, invalidationSecret: $secret);
        $t->make('t', 16, ['name' => [Table::TYPE_STRING, 32]]);
        return $t;
    }

    /** @return callable(string): void */
    private function handlerOf(TieredBackend $t): callable
    {
        $m = new \ReflectionMethod(TieredBackend::class, 'invalidationHandler');
        $m->setAccessible(true);
        /** @var callable(string): void $cb */
        $cb = $m->invoke($t);
        return $cb;
    }

    private function hmacOf(TieredBackend $t, string $table, string $key, string $origin): string
    {
        $m = new \ReflectionMethod(TieredBackend::class, 'computeHmac');
        $m->setAccessible(true);
        /** @var string $r */
        $r = $m->invoke($t, $table, $key, $origin);
        return $r;
    }

    public function testNoSecretAcceptsAnyPayloadFromPeer(): void
    {
        $t = $this->makeTiered(secret: null, origin: 'me');
        self::assertFalse($t->isInvalidationAuthenticated());

        // Seed L1
        $t->l1()->set('t', 'k', ['name' => 'v', '__cached_at' => time()]);
        self::assertIsArray($t->l1()->get('t', 'k'));

        // Peer publishes an unsigned invalidation
        ($this->handlerOf($t))((string) json_encode(['table' => 't', 'key' => 'k', 'origin' => 'peer']));
        self::assertFalse($t->l1()->exists('t', 'k'), 'no-secret mode evicts on any peer message');
    }

    public function testValidHmacAccepted(): void
    {
        $t = $this->makeTiered(secret: 'shared-key', origin: 'me');
        self::assertTrue($t->isInvalidationAuthenticated());

        $t->l1()->set('t', 'k', ['name' => 'v', '__cached_at' => time()]);

        $msg = [
            'table' => 't',
            'key' => 'k',
            'origin' => 'peer',
            'hmac' => $this->hmacOf($t, 't', 'k', 'peer'),
        ];
        ($this->handlerOf($t))((string) json_encode($msg));
        self::assertFalse($t->l1()->exists('t', 'k'), 'authenticated peer message must evict');
    }

    public function testForgedHmacIsRejected(): void
    {
        $t = $this->makeTiered(secret: 'shared-key', origin: 'me');
        $t->l1()->set('t', 'k', ['name' => 'v', '__cached_at' => time()]);

        // Bad hex — wrong length / wrong content
        $msg = ['table' => 't', 'key' => 'k', 'origin' => 'peer', 'hmac' => 'deadbeef'];
        ($this->handlerOf($t))((string) json_encode($msg));
        self::assertTrue($t->l1()->exists('t', 'k'), 'forged HMAC must not evict');
    }

    public function testMissingHmacRejected(): void
    {
        $t = $this->makeTiered(secret: 'shared-key', origin: 'me');
        $t->l1()->set('t', 'k', ['name' => 'v', '__cached_at' => time()]);

        // No hmac field — caught as missing.
        $msg = ['table' => 't', 'key' => 'k', 'origin' => 'peer'];
        ($this->handlerOf($t))((string) json_encode($msg));
        self::assertTrue($t->l1()->exists('t', 'k'), 'unsigned peer message must not evict in secret mode');
    }

    public function testHmacMismatchAcrossSecretsRejected(): void
    {
        // Peer signed with a different secret than the receiver expects.
        $sender   = $this->makeTiered(secret: 'sender-secret',   origin: 'sender');
        $receiver = $this->makeTiered(secret: 'receiver-secret', origin: 'me');
        $receiver->l1()->set('t', 'k', ['name' => 'v', '__cached_at' => time()]);

        $forgedHmac = $this->hmacOf($sender, 't', 'k', 'sender'); // signed with wrong key
        $msg = ['table' => 't', 'key' => 'k', 'origin' => 'sender', 'hmac' => $forgedHmac];
        ($this->handlerOf($receiver))((string) json_encode($msg));
        self::assertTrue($receiver->l1()->exists('t', 'k'), 'mismatched-secret message must not evict');
    }

    public function testSelfPublishAlwaysSkipped(): void
    {
        // The origin check fires BEFORE HMAC verification — writers don't
        // evict their own freshly-written L1 row.
        $t = $this->makeTiered(secret: 'k', origin: 'me');
        $t->l1()->set('t', 'k', ['name' => 'v', '__cached_at' => time()]);

        $msg = [
            'table' => 't', 'key' => 'k', 'origin' => 'me',
            'hmac' => $this->hmacOf($t, 't', 'k', 'me'),
        ];
        ($this->handlerOf($t))((string) json_encode($msg));
        self::assertTrue($t->l1()->exists('t', 'k'), 'self-publishes are skipped regardless of HMAC');
    }

    public function testEnvVarReadsSecret(): void
    {
        putenv('ZEALPHP_TIERED_INVALIDATION_SECRET=from-env');
        try {
            $l2 = new RedisBackend(new RedisConnectionPool('redis://127.0.0.1:9'));
            $t = new TieredBackend(new TableBackend(), $l2);
            self::assertTrue($t->isInvalidationAuthenticated());
        } finally {
            putenv('ZEALPHP_TIERED_INVALIDATION_SECRET');
        }
    }

    public function testExplicitConstructorArgOverridesEnv(): void
    {
        putenv('ZEALPHP_TIERED_INVALIDATION_SECRET=ignored');
        try {
            $l2 = new RedisBackend(new RedisConnectionPool('redis://127.0.0.1:9'));
            $t = new TieredBackend(new TableBackend(), $l2, invalidationSecret: 'explicit');
            self::assertTrue($t->isInvalidationAuthenticated());
        } finally {
            putenv('ZEALPHP_TIERED_INVALIDATION_SECRET');
        }
    }
}
