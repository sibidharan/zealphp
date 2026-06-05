<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * #260 — multiple same-name headers must survive on the wire.
 *
 * PHP's `header($value, $replace = false)` appends; calling it twice with the
 * same field name emits TWO headers (multiple Link preload hints, multiple
 * WWW-Authenticate challenges, CSP + CSP-Report-Only). The wrapper queues each
 * append as a separate headersList entry, but the OLD flush()/redirect emit
 * loop called OpenSwoole's scalar `header($name, $scalar)` once per entry —
 * which OVERWRITES by name, collapsing everything to the LAST value.
 *
 * The fix groups queued headers by name and hands OpenSwoole an ARRAY value for
 * any name with >1 value (the ext emits one wire line per array element — the
 * same mechanism multiple Set-Cookie uses). These tests drive flush() (and the
 * redirect inline-emit path) against the recording FakeOpenSwooleResponse and
 * assert BOTH values reach the parent, single-value headers stay scalar, and
 * first-seen name order is preserved.
 */
class ResponseMultiHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = RequestContext::instance();
        $g->status = null;
        $g->_streaming = false;
        $g->server = [];
    }

    protected function tearDown(): void
    {
        // redirect()/flush() mutate shared RequestContext state (_streaming,
        // status, zealphp_response). Reset it so this class can't leak into the
        // next test class in the same process (e.g. a left-on _streaming makes
        // RangeMiddleware early-return).
        $g = RequestContext::instance();
        $g->status = null;
        $g->_streaming = false;
        $g->zealphp_response = null;
        parent::tearDown();
    }

    private function fake(bool $writable = true): FakeOpenSwooleResponse
    {
        $f = new FakeOpenSwooleResponse();
        $f->writable = $writable;
        return $f;
    }

    private function wrap(FakeOpenSwooleResponse $fake): ZResponse
    {
        return new ZResponse($fake);
    }

    /** Pull every ['header', name, value] entry for $name out of the call log. */
    private function headerCalls(FakeOpenSwooleResponse $fake, string $name): array
    {
        $out = [];
        foreach ($fake->log as $entry) {
            if (($entry[0] ?? null) === 'header' && ($entry[1] ?? null) === $name) {
                $out[] = $entry[2];
            }
        }
        return $out;
    }

    public function testTwoSameNameAppendsBothReachTheWireViaFlush(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        // PHP append form (`$replace = false`): two Link values, SAME name.
        $resp->header('Link', '</style.css>; rel=preload', false);
        $resp->header('Link', '</app.js>; rel=preload', false);

        $this->assertTrue($resp->flush());

        // Both values must survive. The fix emits them as one header() call with
        // an ARRAY value, so the recorded value is the array of both.
        $linkCalls = $this->headerCalls($fake, 'Link');
        $this->assertCount(1, $linkCalls, 'multi-value name emitted as one array call');
        $this->assertSame(
            ['</style.css>; rel=preload', '</app.js>; rel=preload'],
            $linkCalls[0]
        );
    }

    public function testSingleValueHeaderStaysScalar(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->header('Content-Type', 'text/html');
        $this->assertTrue($resp->flush());

        $calls = $this->headerCalls($fake, 'Content-Type');
        $this->assertSame(['text/html'], $calls, 'single value emitted as a scalar string');
    }

    public function testReplaceByDefaultLastWinsAsScalar(): void
    {
        // The DEFAULT (replace=true) must NOT array-up — setting Content-Type
        // twice keeps only the last value, emitted as a single scalar (what
        // sendFile()/middleware rely on, e.g. file-mime then multipart override).
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->header('Content-Type', 'text/plain');
        $resp->header('Content-Type', 'application/json'); // replace default
        $this->assertTrue($resp->flush());

        $calls = $this->headerCalls($fake, 'Content-Type');
        $this->assertSame(['application/json'], $calls, 'replace=true → last value only, scalar');
    }

    public function testThreeWayMixIsGroupedAndOrderPreserved(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        // Interleave a repeated (appended) name with single-value names.
        $resp->header('X-Demo', 'alpha', false);
        $resp->header('Content-Type', 'application/json');
        $resp->header('X-Demo', 'beta', false);

        $this->assertTrue($resp->flush());

        // X-Demo appears once, as an array of both values, first-seen order kept.
        $demo = $this->headerCalls($fake, 'X-Demo');
        $this->assertCount(1, $demo);
        $this->assertSame(['alpha', 'beta'], $demo[0]);

        // Content-Type stays a scalar.
        $this->assertSame(['application/json'], $this->headerCalls($fake, 'Content-Type'));

        // First-seen NAME order is X-Demo then Content-Type.
        $names = [];
        foreach ($fake->log as $entry) {
            if (($entry[0] ?? null) === 'header') {
                $names[] = $entry[1];
            }
        }
        $this->assertSame(['X-Demo', 'Content-Type'], $names);
    }

    public function testRedirectInlinePathPreservesMultiHeaders(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        // Queue two same-name appends, THEN redirect — the redirect inline-emit
        // path must group them too, not just flush().
        $resp->header('Link', '</a.css>; rel=preload', false);
        $resp->header('Link', '</b.js>; rel=preload', false);
        $resp->redirect('/login', 302);

        $link = $this->headerCalls($fake, 'Link');
        $this->assertCount(1, $link, 'redirect path emits multi-value name as one array call');
        $this->assertSame(['</a.css>; rel=preload', '</b.js>; rel=preload'], $link[0]);

        // The Location header (single value) is still scalar on the redirect.
        $this->assertSame(['/login'], $this->headerCalls($fake, 'Location'));
        $this->assertContains(['status', 302, 'Found'], $fake->log);
    }
}
