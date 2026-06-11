<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\Tests\TestCase;

/**
 * HIGH-severity session-security fixes (@Guruprasanth-M):
 *   #343 — FileSessionHandler::open() overwrote the resolved save path back to
 *          the raw empty string → wrote sess_* to the filesystem root.
 *   #371 — session-fixation: a manager-rotated id was clobbered back to the
 *          forged value by the OnRequest cookie re-parse, so the session
 *          persisted under the attacker's id. App::reassertRotatedSessionId()
 *          re-asserts the rotated id over the freshly-parsed cookie map.
 */
final class SessionSecurityFixesTest extends TestCase
{
    // ── #343: empty save_path falls back to a WRITABLE temp dir ──────
    public function testFileHandlerEmptySavePathFallsBackToTemp(): void
    {
        $h = new FileSessionHandler();
        $this->assertTrue($h->open('', 'PHPSESSID'));
        // write must succeed (NOT hit /sess_… at the root)
        $this->assertNotFalse($h->write('sec343', 'k|s:1:"v";'));
        $this->assertSame('k|s:1:"v";', $h->read('sec343'));
        $h->destroy('sec343');

        $rp = new \ReflectionProperty(FileSessionHandler::class, 'savePath');
        $sp = $rp->getValue($h);
        $this->assertNotSame('', $sp, '#343: resolved path must not be the empty string');
        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '/'), (string)$sp,
            '#343: empty save_path must fall back to the system temp dir, not /');
    }

    public function testFileHandlerHonoursExplicitSavePath(): void
    {
        $dir = sys_get_temp_dir() . '/zealphp_343_' . bin2hex(random_bytes(5));
        $h = new FileSessionHandler();
        $this->assertTrue($h->open($dir, 'PHPSESSID'));
        $h->write('x', 'a|s:1:"b";');
        $this->assertFileExists($dir . '/sess_x', 'a configured path must be used verbatim');
        @unlink($dir . '/sess_x');
        @rmdir($dir);
    }

    // ── #371: rotated id wins over the forged request cookie ─────────
    public function testRotatedIdReassertedOverForgedCookie(): void
    {
        $g = RequestContext::instance();
        $saved = $g->session_params;
        try {
            $g->session_params = ['name' => 'PHPSESSID', 'session_id' => 'ROTATED_server_id'];
            // The raw request cookie carried the attacker's forged id.
            $cookie = ['PHPSESSID' => 'FORGED_attacker_id', 'other' => 'x'];

            $out = App::reassertRotatedSessionId($cookie);

            $this->assertSame('ROTATED_server_id', $out['PHPSESSID'],
                '#371: the manager-rotated id must win over the forged request cookie');
            $this->assertSame('x', $out['other'], 'unrelated cookies are preserved');
        } finally {
            $g->session_params = $saved;
        }
    }

    public function testFirstVisitMintLandsInCookie(): void
    {
        $g = RequestContext::instance();
        $saved = $g->session_params;
        try {
            $g->session_params = ['name' => 'PHPSESSID', 'session_id' => 'minted_fresh'];
            $out = App::reassertRotatedSessionId([]); // no cookie sent (first visit)
            $this->assertSame('minted_fresh', $out['PHPSESSID'] ?? null,
                '#371: a first-visit mint must be asserted into the cookie map');
        } finally {
            $g->session_params = $saved;
        }
    }

    public function testNoRotationLeavesCookieUntouched(): void
    {
        $g = RequestContext::instance();
        $saved = $g->session_params;
        try {
            // Normal request: manager's session_id == the cookie's id → no-op.
            $g->session_params = ['name' => 'PHPSESSID', 'session_id' => 'same_id'];
            $out = App::reassertRotatedSessionId(['PHPSESSID' => 'same_id']);
            $this->assertSame('same_id', $out['PHPSESSID']);
            // No session at all → untouched.
            $g->session_params = [];
            $out2 = App::reassertRotatedSessionId(['PHPSESSID' => 'whatever']);
            $this->assertSame('whatever', $out2['PHPSESSID'],
                'no rotation recorded → the cookie map is returned unchanged');
        } finally {
            $g->session_params = $saved;
        }
    }

    public function testCustomSessionNameRespected(): void
    {
        $g = RequestContext::instance();
        $saved = $g->session_params;
        try {
            $g->session_params = ['name' => 'MYSESSID', 'session_id' => 'rot'];
            $out = App::reassertRotatedSessionId(['MYSESSID' => 'forged']);
            $this->assertSame('rot', $out['MYSESSID'], 'the configured session name is used');
        } finally {
            $g->session_params = $saved;
        }
    }
}
