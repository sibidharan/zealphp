<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\ComponentException;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\FormBinder;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Registry;
use ZealPHP\Component\Snapshot;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;

/** Origin checks, the no-JS back-link, argument limits, flash, and snapshot structure. */
class EndpointGuardsTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'guards', true)));
        Registry::reset();
        App::components([Counter::class]);
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'guards'];
        $g->session_params = [];
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::componentBinder(new HtmxBinder());
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function origins(): iterable
    {
        yield 'no headers' => [[], true];
        yield 'same-origin fetch site' => [['HTTP_SEC_FETCH_SITE' => 'same-origin'], true];
        yield 'same-site is not enough' => [['HTTP_SEC_FETCH_SITE' => 'same-site'], false];
        yield 'none' => [['HTTP_SEC_FETCH_SITE' => 'none'], false];
        yield 'fetch site wins over origin' => [['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://app.test', 'HTTP_HOST' => 'app.test'], false];
        yield 'origin matches' => [['HTTP_ORIGIN' => 'http://app.test', 'HTTP_HOST' => 'app.test'], true];
        yield 'origin case-insensitive' => [['HTTP_ORIGIN' => 'http://APP.test', 'HTTP_HOST' => 'app.TEST'], true];
        yield 'origin with port matches' => [['HTTP_ORIGIN' => 'http://app.test:8080', 'HTTP_HOST' => 'app.test:8080'], true];
        yield 'origin port differs' => [['HTTP_ORIGIN' => 'http://app.test:9090', 'HTTP_HOST' => 'app.test:8080'], false];
        yield 'origin port vs bare host' => [['HTTP_ORIGIN' => 'http://app.test:8080', 'HTTP_HOST' => 'app.test'], false];
        yield 'host has default https port' => [['HTTP_ORIGIN' => 'https://app.test', 'HTTP_HOST' => 'app.test:443'], true];
        yield 'origin has default http port' => [['HTTP_ORIGIN' => 'http://app.test:80', 'HTTP_HOST' => 'app.test'], true];
        yield 'https origin vs :80 host' => [['HTTP_ORIGIN' => 'https://app.test', 'HTTP_HOST' => 'app.test:80'], false];
        yield 'other host' => [['HTTP_ORIGIN' => 'http://evil.test', 'HTTP_HOST' => 'app.test'], false];
        yield 'suffix host' => [['HTTP_ORIGIN' => 'http://app.test.evil', 'HTTP_HOST' => 'app.test'], false];
        yield 'null origin' => [['HTTP_ORIGIN' => 'null', 'HTTP_HOST' => 'app.test'], false];
        yield 'origin but no host' => [['HTTP_ORIGIN' => 'http://app.test'], false];
        yield 'ftp scheme default port' => [['HTTP_ORIGIN' => 'ftp://app.test', 'HTTP_HOST' => 'app.test:21'], false];
    }

    /** @param array<string, string> $server */
    #[DataProvider('origins')]
    public function testSameOrigin(array $server, bool $ok): void
    {
        $this->assertSame($ok, Endpoint::sameOrigin($server));
    }

    private function wire(): string
    {
        $html = (string) App::component(Counter::class, [], 'g');
        preg_match('/name="_zc" value="([^"]+)"/', $html, $m);
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** @return iterable<string, array{string, string}> */
    public static function referers(): iterable
    {
        yield 'same host path+query' => ['http://app.test/a/b?x=1', '/a/b?x=1'];
        yield 'same host no path' => ['http://app.test', '/'];
        yield 'same host case' => ['http://APP.test/p', '/p'];
        yield 'port must match' => ['http://app.test:9/p', '/'];
        yield 'other host' => ['http://evil.test/p', '/'];
        yield 'backslash path' => ['http://app.test/\\evil.test', '/'];
        yield 'double slash path' => ['http://app.test//evil.test', '/'];
        yield 'no host' => ['/relative', '/'];
        yield 'empty' => ['', '/'];
    }

    #[DataProvider('referers')]
    public function testNoJsBackLink(string $referer, string $location): void
    {
        App::componentBinder(new FormBinder());
        $r = Endpoint::handle('counter', 'increment', ['_zc' => $this->wire()], ['HTTP_HOST' => 'app.test', 'HTTP_REFERER' => $referer]);
        $this->assertSame(303, $r['status']);
        $this->assertSame($location, $r['headers']['Location']);
    }

    public function testNoJsWithoutHostGoesHome(): void
    {
        App::componentBinder(new FormBinder());
        $r = Endpoint::handle('counter', 'increment', ['_zc' => $this->wire()], ['HTTP_REFERER' => 'http://app.test/p']);
        $this->assertSame('/', $r['headers']['Location']);
    }

    public function testNoJsStaleSnapshotRedirectsBack(): void
    {
        App::componentBinder(new FormBinder());
        $wire = (new Snapshot('counter', 'counter-x', ['count' => 0, 'mounted' => 0], 'old', Snapshot::currentBinding()))->encode();
        $r = Endpoint::handle('counter', 'increment', ['_zc' => $wire], ['HTTP_HOST' => 'app.test', 'HTTP_REFERER' => 'http://app.test/back']);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/back', $r['headers']['Location']);
    }

    public function testErrorResponsesArePlainTextAndNotCached(): void
    {
        $r = Endpoint::handle('counter', 'increment', ['_zc' => 'x.y'], ['HTTP_HX_REQUEST' => 'true']);
        $this->assertSame(400, $r['status']);
        $this->assertSame('text/plain; charset=utf-8', $r['headers']['Content-Type']);
        $this->assertSame('no-store', $r['headers']['Cache-Control']);
        $this->assertSame('nosniff', $r['headers']['X-Content-Type-Options']);
        $this->assertStringContainsString('snapshot', $r['body']);
    }

    public function testArgumentsSizeLimit(): void
    {
        $wire = $this->wire();
        $ok = '{"by":1,"pad":"' . str_repeat('a', 16384 - 17) . '"}';
        $this->assertSame(16384, strlen($ok));
        $this->assertSame(200, Endpoint::handle('counter', 'increment', ['_zc' => $wire, '_zc_args' => $ok], ['HTTP_HX_REQUEST' => 'true'])['status']);
        $tooBig = '{"by":1,"pad":"' . str_repeat('a', 16384 - 16) . '"}';
        $this->assertSame(400, Endpoint::handle('counter', 'increment', ['_zc' => $wire, '_zc_args' => $tooBig], ['HTTP_HX_REQUEST' => 'true'])['status']);
        $this->assertSame(400, Endpoint::handle('counter', 'increment', ['_zc' => $wire, '_zc_args' => ['by' => 1]], ['HTTP_HX_REQUEST' => 'true'])['status']);
        $this->assertSame(200, Endpoint::handle('counter', 'increment', ['_zc' => $wire, '_zc_args' => '{}'], ['HTTP_HX_REQUEST' => 'true'])['status']);
        $this->assertSame(200, Endpoint::handle('counter', 'increment', ['_zc' => $wire, '_zc_args' => '[]'], ['HTTP_HX_REQUEST' => 'true'])['status'], 'an empty JSON array means no arguments');
    }

    public function testNonStringSnapshotFieldIs400(): void
    {
        $this->assertSame(400, Endpoint::handle('counter', 'increment', ['_zc' => ['x']], ['HTTP_HX_REQUEST' => 'true'])['status']);
    }

    // ── flash ────────────────────────────────────────────────────

    public function testFlashIsBoundedAndPerInstance(): void
    {
        App::componentBinder(new FormBinder());
        $g = RequestContext::instance();
        for ($i = 0; $i < 25; $i++) {
            $g->memo = [];
            $html = (string) App::component(Counter::class, [], "k{$i}");
            preg_match('/name="_zc" value="([^"]+)"/', $html, $m);
            Endpoint::handle('counter', 'increment', ['_zc' => html_entity_decode($m[1])], ['HTTP_HOST' => 'app.test']);
        }
        $flash = $g->session[Endpoint::FLASH_KEY];
        $this->assertIsArray($flash);
        $this->assertCount(20, $flash);
        $this->assertArrayNotHasKey('counter-k0', $flash, 'oldest dropped');
        $this->assertArrayHasKey('counter-k24', $flash);
        $this->assertNotNull(Endpoint::takeFlash('counter-k24'));
        $this->assertNull(Endpoint::takeFlash('counter-k24'), 'consumed');
        $this->assertCount(19, $g->session[Endpoint::FLASH_KEY]);
        foreach (array_keys($g->session[Endpoint::FLASH_KEY]) as $id) {
            Endpoint::takeFlash((string) $id);
        }
        $this->assertArrayNotHasKey(Endpoint::FLASH_KEY, $g->session, 'empty flash removed');
    }

    public function testFlashIgnoresGarbageAndNoSession(): void
    {
        $g = RequestContext::instance();
        $g->session = [Endpoint::FLASH_KEY => 'garbage'];
        $this->assertNull(Endpoint::takeFlash('counter-g'));
        $g->session = [Endpoint::FLASH_KEY => ['counter-g' => 42]];
        $this->assertNull(Endpoint::takeFlash('counter-g'));
        unset($g->session);
        $this->assertNull(Endpoint::takeFlash('counter-g'));
        $g->session = [];
    }

    public function testFlashedSnapshotFromAnotherInstanceIsIgnored(): void
    {
        $g = RequestContext::instance();
        $wire = (new Snapshot('counter', 'counter-other', ['count' => 77, 'mounted' => 0], (string) Registry::byName('counter')?->version, Snapshot::currentBinding()))->encode();
        $g->session = [Endpoint::FLASH_KEY => ['counter-mine' => $wire]];
        $this->assertStringNotContainsString('>77<', (string) App::component(Counter::class, [], 'mine'));
    }

    // ── snapshot structure ───────────────────────────────────────

    private static function signed(string $json): string
    {
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64($json) . '.' . $b64(hash_hmac('sha256', $json, AppKey::derive('component-snapshot'), true));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPayloads(): iterable
    {
        $base = ['c' => 'counter', 'id' => 'counter-1', 'p' => ['count' => 0, 'mounted' => 0], 'v' => 'V', 's' => 'S', 'f' => [], 'e' => [], 't' => 1];
        yield 'not json' => ['nope'];
        yield 'json scalar' => ['5'];
        foreach (['c', 'id', 'p', 'v', 's', 'f', 'e', 't'] as $k) {
            $missing = $base;
            unset($missing[$k]);
            yield "missing {$k}" => [(string) json_encode($missing)];
            $wrong = $base;
            $wrong[$k] = in_array($k, ['p', 'f', 'e'], true) ? 'x' : ['x'];
            yield "wrong type {$k}" => [(string) json_encode($wrong)];
        }
        yield 't as string' => [(string) json_encode(['t' => '1'] + $base)];
    }

    #[DataProvider('malformedPayloads')]
    public function testSignedButMalformedPayloadsAre400(string $json): void
    {
        $meta = Registry::byName('counter');
        $this->assertNotNull($meta);
        try {
            Snapshot::decode(self::signed($json), $meta);
            $this->fail('accepted');
        } catch (ComponentException $e) {
            $this->assertSame(400, $e->getCode());
        }
    }

    public function testValidPayloadWithBrokenMacIs400(): void
    {
        $meta = Registry::byName('counter');
        $this->assertNotNull($meta);
        [$payload] = explode('.', self::signed('{}'));
        foreach ([$payload . '.!!', '!!.' . $payload, $payload . '.'] as $wire) {
            try {
                Snapshot::decode($wire, $meta);
                $this->fail("accepted {$wire}");
            } catch (ComponentException $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
    }

    public function testMaxAgeBoundary(): void
    {
        $meta = Registry::byName('counter');
        $this->assertNotNull($meta);
        $make = fn(int $t) => (new Snapshot('counter', 'counter-1', ['count' => 0, 'mounted' => 0], $meta->version, Snapshot::currentBinding(), [], [], $t))->encode();
        App::componentMaxAge(100);
        try {
            $this->assertSame(time() - 50, Snapshot::decode($make(time() - 50), $meta)->issuedAt);
            try {
                Snapshot::decode($make(time() - 102), $meta);
                $this->fail('expired accepted');
            } catch (ComponentException $e) {
                $this->assertSame(409, $e->getCode());
            }
        } finally {
            App::componentMaxAge(86400);
        }
    }

    public function testDecodeFiltersFieldsAndErrorsToStrings(): void
    {
        $meta = Registry::byName('counter');
        $this->assertNotNull($meta);
        $json = (string) json_encode([
            'c' => 'counter', 'id' => 'counter-1', 'p' => ['count' => 0, 'mounted' => 0], 'v' => $meta->version,
            's' => Snapshot::currentBinding(), 'f' => ['a', 5, 'b'], 'e' => ['x' => 'msg', 'y' => 3, 0 => 'n'], 't' => time(),
        ]);
        $snap = Snapshot::decode(self::signed($json), $meta);
        $this->assertSame(['a', 'b'], $snap->fields);
        $this->assertSame(['x' => 'msg'], $snap->errors);
    }

    public function testSnapshotSizeLimitIsExact(): void
    {
        $meta = Registry::byName('counter');
        $this->assertNotNull($meta);
        $this->assertSame(400, (function () use ($meta) {
            try {
                Snapshot::decode(str_repeat('a', 65537), $meta);
            } catch (ComponentException $e) {
                return $e->getCode();
            }
            return 0;
        })());
        try {
            Snapshot::decode(str_repeat('a', 65536), $meta);
            $this->fail('accepted');
        } catch (ComponentException $e) {
            $this->assertSame('Malformed component snapshot', $e->getMessage(), '64 KB exactly is checked for format, not size');
        }
    }

    public function testBindingIsAShortHmacNotTheSessionId(): void
    {
        $binding = Snapshot::currentBinding();
        $this->assertSame(22, strlen($binding), '16 bytes, base64url without padding');
        $this->assertStringNotContainsString('guards', $binding);
        $g = RequestContext::instance();
        $g->session_params = ['name' => 'SID'];
        $g->cookie = ['SID' => 'guards'];
        $this->assertSame($binding, Snapshot::currentBinding(), 'reads the configured session cookie name');
        $g->session_params = [];
        $g->cookie = ['PHPSESSID' => 'guards'];
        unset($g->session);
        $this->assertSame('', Snapshot::currentBinding());
        $g->session = [];
    }

    public function testMaxAgeRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::componentMaxAge(-1);
    }
}
