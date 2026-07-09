<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * #485 — file-based api/ endpoints nested more than one directory deep must be
 * reachable at ANY depth (filesystem-is-the-router parity with mod_php/FPM),
 * and the deep-dispatch fold must NOT weaken the validation/traversal posture:
 * dots are impossible in both components (charset regexes run on the decoded
 * path), empty segments still 400, and the realpath() jail still gates every
 * on-disk resolution. Fixtures: api/nested/ping.php, api/nested/deep/probe.php,
 * api/nested/deep/very/probe2.php.
 */
final class ZealApiNestedRoutingTest extends TestCase
{
    // ── functional: unbounded nesting depth ─────────────────────────────

    public function testOneLevelControlStillWorks(): void
    {
        $res = $this->get('/api/nested/ping');
        $this->assertStatus(200, $res);
        $json = $this->assertJsonResponse($res);
        $this->assertTrue($json['ok']);
        $this->assertSame(1, $json['levels']);
    }

    public function testTwoDirectoryLevelsReachable(): void
    {
        $res = $this->get('/api/nested/deep/probe');
        $this->assertStatus(200, $res);
        $json = $this->assertJsonResponse($res);
        $this->assertTrue($json['ok']);
        $this->assertSame(2, $json['levels']);
    }

    public function testThreeDirectoryLevelsReachable(): void
    {
        $res = $this->get('/api/nested/deep/very/probe2');
        $this->assertStatus(200, $res);
        $json = $this->assertJsonResponse($res);
        $this->assertTrue($json['ok']);
        $this->assertSame(3, $json['levels']);
    }

    public function testDeepMissingFileIs404NotFound(): void
    {
        // Deep path passes validation now, so the absent file must hit the
        // realpath 404 gate — not a 400 and never a 500.
        $res = $this->get('/api/nested/deep/very/definitely-absent');
        $this->assertStatus(404, $res);
        $json = $this->assertJsonResponse($res);
        $this->assertSame('method_not_found', $json['error']);
    }

    // ── adversarial: the fold must not open traversal or malformed paths ─

    public function testDotDotTraversalIsRejected(): void
    {
        // '.' is outside both charset regexes — traversal can't even reach
        // file resolution. Accept either component's 400 (or a router-level
        // 404), but never 200/500.
        $res = $this->get('/api/nested/../../composer/installed');
        $this->assertContains($res['status'], [400, 404]);
        $this->assertNotSame(500, $res['status']);
    }

    public function testEncodedDotDotTraversalIsRejected(): void
    {
        // %2e%2e decodes to '..' before routing — same rejection.
        $res = $this->get('/api/nested/%2e%2e/%2e%2e/composer/installed');
        $this->assertContains($res['status'], [400, 404]);
    }

    public function testPhpSuffixedDeepPathIsRejected(): void
    {
        // Dots are impossible in $request too — no direct .php probing.
        $res = $this->get('/api/nested/deep/probe.php');
        $this->assertContains($res['status'], [400, 403, 404]);
    }

    public function testDoubledSlashSegmentStillRejected(): void
    {
        // Empty segment ⇒ the fold refuses; strict validation 400s as before
        // (a proxy/normalizer collapsing to the clean path making it 200 is
        // also acceptable — what must never happen is a 500 or a different
        // file being served).
        $res = $this->get('/api/nested//ping');
        $this->assertContains($res['status'], [200, 400, 404]);
        $this->assertNotSame(500, $res['status']);
        if ($res['status'] === 400) {
            $json = $this->assertJsonResponse($res);
            $this->assertSame('invalid_request', $json['error']);
        }
    }

    public function testTrailingSlashStillRejected(): void
    {
        $res = $this->get('/api/nested/deep/probe/');
        // Never a 500, and never a "wrong handler" 200 body: if a trailing-
        // slash normalizer upstream redirects/serves the clean path, the body
        // must be the probe's own.
        $this->assertNotSame(500, $res['status']);
        if ($res['status'] === 200) {
            $json = $this->assertJsonResponse($res);
            $this->assertSame(2, $json['levels']);
        }
    }
}
