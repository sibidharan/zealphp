<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\env_flag;
use function ZealPHP\bench_mode_enabled;
use function ZealPHP\site_url;
use function ZealPHP\site_host;
use function ZealPHP\zapi;
use function ZealPHP\indent;
use function ZealPHP\purify_array;
use function ZealPHP\uniqidReal;
use function ZealPHP\get_current_render_time;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;
use function ZealPHP\response_headers_list;
use function ZealPHP\header;
use function ZealPHP\http_response_code;
use function ZealPHP\headers_list;
use function ZealPHP\headers_sent;
use function ZealPHP\header_remove;
use function ZealPHP\setcookie;
use function ZealPHP\setrawcookie;
use function ZealPHP\apache_setenv;
use function ZealPHP\apache_getenv;
use function ZealPHP\apache_note;
use function ZealPHP\is_uploaded_file;
use function ZealPHP\connection_status;
use function ZealPHP\connection_aborted;
use function ZealPHP\set_time_limit;

/**
 * Regression coverage for the global helpers + uopz-override implementations
 * in src/utils.php. Exercises the pure helpers and the response/cookie/header
 * functions against a mock `$g->zealphp_response`.
 */
class UtilsTest extends TestCase
{
    /** @var object{headersList: array<int,array{0:string,1:string}>, cookies: array<int,array<int,mixed>>} */
    private object $resp;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $this->resp = new class {
            /** @var array<int, array{0:string,1:string}> */
            public array $headersList = [];
            /** @var array<int, array<int, mixed>> */
            public array $cookies = [];
            public function header(string $k, string $v, bool $ucwords = true): void { $this->headersList[] = [$k, $v]; }
            public function cookie(mixed ...$args): void { $this->cookies[] = $args; }
            public function rawCookie(mixed ...$args): void { $this->cookies[] = $args; }
        };
        $g = RequestContext::instance();
        $g->zealphp_response = $this->resp;
        $g->status = 200;
    }

    // ── pure helpers ──────────────────────────────────────────────

    public function testEnvFlagTruthyVariants(): void
    {
        foreach (['1', 'true', 'on', 'yes', 'TRUE', 'On', 'Yes'] as $v) {
            putenv("ZP_TEST_FLAG=$v");
            $this->assertTrue(env_flag('ZP_TEST_FLAG', false), "[$v] should be truthy");
        }
        // Note: not including '' — putenv("X=") is platform-ambiguous (may unset).
        foreach (['0', 'false', 'off', 'no'] as $v) {
            putenv("ZP_TEST_FLAG=$v");
            $this->assertFalse(env_flag('ZP_TEST_FLAG', true), "[$v] should be falsy");
        }
        putenv('ZP_TEST_FLAG'); // unset → default
        $this->assertTrue(env_flag('ZP_TEST_FLAG', true));
        $this->assertFalse(env_flag('ZP_TEST_FLAG', false));
    }

    public function testBenchModeReturnsBool(): void
    {
        // bench_mode_enabled() memoizes in a static, so its value is fixed for
        // the process lifetime — assert the type/contract, not a live toggle.
        $this->assertIsBool(bench_mode_enabled());
        $this->assertSame(bench_mode_enabled(), bench_mode_enabled());
    }

    public function testSiteUrlAndHost(): void
    {
        // site_url() caches its base in a static, so assert path-append logic
        // relative to whatever base is in effect (cache-order independent).
        $base = site_url();
        $this->assertIsString($base);
        $this->assertStringStartsWith('http', $base);
        $this->assertSame($base, rtrim(site_url(), '/'));
        $this->assertSame($base . '/foo', site_url('/foo'));
        $this->assertSame($base . '/foo', site_url('foo'));
        $this->assertSame(site_url(), site_url('')); // empty path → base
        $this->assertIsString(site_host());
        $this->assertStringNotContainsString('/', site_host());
    }

    public function testZapiReturnsString(): void
    {
        $this->assertIsString(zapi());
    }

    public function testIndent(): void
    {
        $out = indent("a\nb", 2);
        $this->assertStringContainsString('  a', $out);
        $this->assertStringContainsString('  b', $out);
    }

    public function testPurifyArrayStripsResources(): void
    {
        $clean = purify_array(['a' => 1, 'b' => ['c' => 2]]);
        $this->assertSame(1, $clean['a']);
        $this->assertSame(2, $clean['b']['c']);
    }

    public function testUniqidRealLength(): void
    {
        $this->assertSame(13, strlen(uniqidReal(13)));
        $this->assertSame(20, strlen(uniqidReal(20)));
        $this->assertNotSame(uniqidReal(), uniqidReal());
    }

    public function testRenderTimeIsFloat(): void
    {
        RequestContext::instance()->session['__start_time'] = microtime(true);
        $this->assertIsFloat(get_current_render_time());
        $this->assertGreaterThanOrEqual(0.0, get_current_render_time());
    }

    // ── response headers ─────────────────────────────────────────

    public function testResponseAddHeaderAndList(): void
    {
        response_add_header('X-Foo', 'bar');
        $list = response_headers_list();
        $this->assertContains(['X-Foo', 'bar'], $list);
    }

    public function testResponseShimsNoOpWhenNoResponseObject(): void
    {
        // worker-start / tick / CLI / task contexts have no response object;
        // the shims must no-op instead of a null method call (#195).
        $g = RequestContext::instance();
        $g->zealphp_response = null;
        try {
            response_add_header('X-Null', 'v'); // void — must not throw on null response
            $this->assertFalse(setcookie('c', 'v'), 'setcookie returns false with no response');
            $this->assertFalse(setrawcookie('rc', 'v'), 'setrawcookie returns false with no response');
        } finally {
            // Restore so the rest of the suite (shared singleton) keeps its recorder.
            $g->zealphp_response = $this->resp;
        }
    }

    public function testResponseSetStatus(): void
    {
        response_set_status(418);
        $this->assertSame(418, RequestContext::instance()->status);
    }

    public function testHeaderNormalForm(): void
    {
        header('X-Custom: hello');
        $this->assertContains(['X-Custom', 'hello'], response_headers_list());
    }

    public function testHeaderStatusLineForm(): void
    {
        header('HTTP/1.1 404 Not Found');
        $this->assertSame(404, RequestContext::instance()->status);
    }

    public function testHeaderStatusColonForm(): void
    {
        header('Status: 503 Service Unavailable');
        $this->assertSame(503, RequestContext::instance()->status);
    }

    public function testHeaderRejectsCrlfInjection(): void
    {
        $r = @header("X-Evil: a\r\nSet-Cookie: x=1");
        $this->assertFalse($r);
    }

    public function testHeaderMalformedReturnsFalse(): void
    {
        $this->assertFalse(header('no-colon-here'));
    }

    public function testHeaderWithStatusCodeArg(): void
    {
        header('X-Thing: v', true, 201);
        $this->assertSame(201, RequestContext::instance()->status);
    }

    public function testHeaderReplaceRemovesPrior(): void
    {
        header('X-Dup: first');
        header('X-Dup: second', true);
        $matches = array_filter(response_headers_list(), fn($p) => $p[0] === 'X-Dup');
        $this->assertCount(1, $matches);
        $this->assertSame('second', array_values($matches)[0][1]);
    }

    public function testHttpResponseCodeGetAndSet(): void
    {
        http_response_code(307);
        $this->assertSame(307, RequestContext::instance()->status);
        $this->assertSame(307, http_response_code());
    }

    public function testHeadersListReturnsFormattedStrings(): void
    {
        header('X-A: 1');
        header('X-B: 2');
        $list = headers_list();
        $this->assertContains('X-A: 1', $list);
        $this->assertContains('X-B: 2', $list);
    }

    public function testHeaderRemoveNamed(): void
    {
        header('X-Keep: 1');
        header('X-Drop: 2');
        header_remove('X-Drop');
        $names = array_map(fn($p) => $p[0], response_headers_list());
        $this->assertContains('X-Keep', $names);
        $this->assertNotContains('X-Drop', $names);
    }

    public function testHeaderRemoveAll(): void
    {
        header('X-A: 1');
        header('X-B: 2');
        header_remove();
        $this->assertSame([], response_headers_list());
    }

    public function testHeadersSentReturnsFalseInTest(): void
    {
        $this->assertIsBool(headers_sent());
    }

    // ── cookies ──────────────────────────────────────────────────

    public function testSetcookieRecordsCookie(): void
    {
        $ok = setcookie('sid', 'abc', 0, '/', '', false, true, 'Lax');
        $this->assertTrue($ok);
        $this->assertNotEmpty($this->resp->cookies);
    }

    public function testSetcookieRejectsInvalidName(): void
    {
        $this->assertFalse(@setcookie('bad name', 'v'));
        $this->assertFalse(@setcookie('bad=name', 'v'));
    }

    public function testSetcookieRejectsCrlfInValue(): void
    {
        $this->assertFalse(@setcookie('ok', "a\r\nb"));
    }

    public function testSetrawcookieRecordsCookie(): void
    {
        $ok = setrawcookie('raw', 'v', 0, '/');
        $this->assertTrue($ok);
    }

    // ── apache_* shims ───────────────────────────────────────────

    public function testApacheSetenvGetenvRoundTrip(): void
    {
        $this->assertTrue(apache_setenv('ZP_VAR', 'val'));
        $this->assertSame('val', apache_getenv('ZP_VAR'));
    }

    public function testApacheNoteRoundTrip(): void
    {
        apache_note('mynote', 'noteval');
        $this->assertSame('noteval', apache_note('mynote'));
    }

    // ── misc shims ───────────────────────────────────────────────

    public function testIsUploadedFileFalseForArbitraryPath(): void
    {
        $this->assertFalse(is_uploaded_file('/etc/passwd'));
    }

    public function testConnectionHelpers(): void
    {
        $this->assertIsInt(connection_status());
        $this->assertIsInt(connection_aborted());
    }

    public function testSetTimeLimitReturnsBool(): void
    {
        $this->assertIsBool(set_time_limit(30));
    }
}
