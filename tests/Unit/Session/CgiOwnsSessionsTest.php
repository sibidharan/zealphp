<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Pin the gate that issue #108's race fix turns on.
 *
 * `App::cgiOwnsSessions()` decides whether the host's SessionManager runs
 * or steps aside for the CGI subprocess. The four lifecycle combinations
 * MUST resolve as follows:
 *
 *   sg=true  + pi=true  → TRUE   (Legacy CGI — the issue #108 scenario)
 *   sg=true  + pi=false → FALSE  (Mixed-mode / Symfony — host owns sessions)
 *   sg=false + pi=*     → FALSE  (Coroutine mode — CoSessionManager owns them)
 *
 * Any of these flipping breaks either the bug fix (false negatives) or
 * established lifecycles (false positives).
 */
final class CgiOwnsSessionsTest extends TestCase
{
    private static ?bool $origSg = null;
    private static ?bool $origPi = null;

    public static function setUpBeforeClass(): void
    {
        self::$origSg = App::$superglobals;
        self::$origPi = App::$process_isolation;
    }

    protected function setUp(): void
    {
        App::$run_has_started = false;
    }

    protected function tearDown(): void
    {
        App::$run_has_started   = false;
        App::$superglobals      = self::$origSg ?? true;
        App::$process_isolation = self::$origPi;
    }

    public function testSuperglobalsOnAndProcessIsolationOnReturnsTrue(): void
    {
        App::superglobals(true);
        App::processIsolation(true);
        $this->assertTrue(App::cgiOwnsSessions(),
            'Legacy CGI mode (sg=true, pi=true) is where the subprocess owns sessions');
    }

    public function testSuperglobalsOnAndProcessIsolationOffReturnsFalse(): void
    {
        App::superglobals(true);
        App::processIsolation(false);
        $this->assertFalse(App::cgiOwnsSessions(),
            'Mixed-mode (sg=true, pi=false) runs everything in-process — host SessionManager owns sessions');
    }

    public function testSuperglobalsOffReturnsFalseRegardlessOfProcessIsolation(): void
    {
        App::superglobals(false);
        App::processIsolation(true);
        $this->assertFalse(App::cgiOwnsSessions(),
            'Coroutine mode (sg=false) uses CoSessionManager — never the CGI handoff');

        App::processIsolation(false);
        $this->assertFalse(App::cgiOwnsSessions(),
            'Coroutine mode + pi=false also stays with CoSessionManager');
    }

    public function testDefaultsResolveToFalseWhenSuperglobalsExplicitlyFalse(): void
    {
        App::superglobals(false);
        App::processIsolation(null);
        $this->assertFalse(App::cgiOwnsSessions(),
            'pi=null with sg=false resolves to pi=false → cgiOwnsSessions=false');
    }

    public function testDefaultsResolveToTrueWhenSuperglobalsExplicitlyTrue(): void
    {
        App::superglobals(true);
        App::processIsolation(null);
        // pi=null + sg=true resolves to pi=true via processIsolation() getter.
        $this->assertTrue(App::cgiOwnsSessions(),
            'pi=null with sg=true resolves to pi=true → cgiOwnsSessions=true');
    }
}
