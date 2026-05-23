<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store\PhpredisDriver;
use ZealPHP\Store\StoreException;

/**
 * C3: TLS-scheme handling in URL parsing.
 *
 * PhpredisDriver::parseUrl() must distinguish `redis://`, `rediss://`,
 * `tls://`. Anything else is rejected. The `tls` flag drives the
 * stream-context branch in buildClient().
 *
 * We can't unit-test the actual TLS handshake without a TLS-enabled
 * Redis daemon; that's covered by tests/Integration/StoreBackendIntegrationTest.php
 * when ZEALPHP_REDIS_TLS_URL is set.
 *
 * Reflection peeks the private static parseUrl so we can pin the
 * contract without exposing a public method.
 */
final class RedisUrlParsingTest extends TestCase
{
    /**
     * @return array{scheme:string,host:string,port:int,pass:?string,db:int,tls:bool}
     */
    private function parse(string $url): array
    {
        $m = new \ReflectionMethod(PhpredisDriver::class, 'parseUrl');
        $m->setAccessible(true);
        /** @var array{scheme:string,host:string,port:int,pass:?string,db:int,tls:bool} $r */
        $r = $m->invoke(null, $url);
        return $r;
    }

    public function testPlainRedisScheme(): void
    {
        $p = $this->parse('redis://127.0.0.1:6379/0');
        self::assertSame('redis', $p['scheme']);
        self::assertFalse($p['tls']);
        self::assertSame(6379, $p['port']);
        self::assertSame(0, $p['db']);
    }

    public function testRedissSchemeEnablesTls(): void
    {
        $p = $this->parse('rediss://prod-cache:6380');
        self::assertSame('rediss', $p['scheme']);
        self::assertTrue($p['tls']);
        self::assertSame('prod-cache', $p['host']);
        self::assertSame(6380, $p['port']);
    }

    public function testTlsSchemeAliasEnablesTls(): void
    {
        $p = $this->parse('tls://secure.redis:6380/3');
        self::assertSame('tls', $p['scheme']);
        self::assertTrue($p['tls']);
        self::assertSame(3, $p['db']);
    }

    public function testAuthPasswordExtractedFromUserinfo(): void
    {
        $p = $this->parse('redis://:supersecret@host:6379/0');
        self::assertSame('supersecret', $p['pass']);
    }

    public function testUnknownSchemeIsRejected(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/unsupported redis scheme/');
        $this->parse('mysql://oops:3306');
    }

    public function testMalformedUrlIsRejected(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/invalid redis url/');
        // PHP's parse_url accepts this as a `path`-only result — no scheme + no host.
        $this->parse('://broken');
    }

    public function testBareSchemeRejected(): void
    {
        // 'redis://' returns false from parse_url. Strict parser refuses it
        // so misconfiguration surfaces at boot, not after an unexpected
        // 127.0.0.1 connect attempt.
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/invalid redis url/');
        $this->parse('redis://');
    }
}
