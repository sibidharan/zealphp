<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use OpenSwoole\Coroutine;
use ZealPHP\RequestContext;
use ZealPHP\Session\Handler\TableSessionHandler;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_valid_session_id;

/** Low-severity session-store fixes from the independent review. */
final class SessionLowsTest extends TestCase
{
    private static string $saveDir = '';
    private static TableSessionHandler $handler;

    public static function setUpBeforeClass(): void
    {
        self::$saveDir = sys_get_temp_dir() . '/zp-lows-' . bin2hex(random_bytes(5));
        @mkdir(self::$saveDir, 0700, true);
        self::$handler = TableSessionHandler::register(ttl: 3600, maxRows: 256, dataSize: 16384, savePath: self::$saveDir);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$saveDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$saveDir);
    }

    public function testSessionIdsFollowPhpsAlphabetAndLength(): void
    {
        $this->assertTrue(zeal_valid_session_id('aZ09,-_'));
        $this->assertTrue(zeal_valid_session_id(str_repeat('a', 128)));
        $this->assertFalse(zeal_valid_session_id(str_repeat('a', 129)), 'sess_ + 256 bytes exceeded NAME_MAX');
        foreach (['a b', "a\tb", "a\nb", 'a.b', 'a/b', "a\0b", '../x', ''] as $bad) {
            $this->assertFalse(zeal_valid_session_id($bad), json_encode($bad));
        }
    }

    /** An expired session must not come back from the Table's file backing with a fresh TTL. */
    public function testAnExpiredBackingFileIsNotPromoted(): void
    {
        $sid = 'expiredbacking' . bin2hex(random_bytes(4));
        $file = self::$saveDir . '/sess_' . $sid;
        file_put_contents($file, 'user|s:5:"alice";');
        touch($file, time() - 7200);   // older than the 3600 s TTL
        $this->assertSame('', self::$handler->read($sid));
        $this->assertFileDoesNotExist($file);
    }

    public function testAFreshBackingFileIsStillPromoted(): void
    {
        $sid = 'freshbacking' . bin2hex(random_bytes(4));
        file_put_contents(self::$saveDir . '/sess_' . $sid, 'user|s:5:"alice";');
        $this->assertSame('user|s:5:"alice";', self::$handler->read($sid));
    }

    /** Integer session keys keep their key through the Table's file-backing merge. */
    public function testIntegerSessionKeysAreNotRenumbered(): void
    {
        $sid = 'intkeys' . bin2hex(random_bytes(4));
        $enc = static fn (array $d): string => \ZealPHP\Session\php_session_encode_from_array($d);
        self::$handler->read($sid);
        self::$handler->write($sid, $enc([5 => 'five', 'k' => 'v']));
        self::$handler->read($sid);
        self::$handler->write($sid, $enc([5 => 'five', 7 => 'seven', 'k' => 'v']));
        $stored = (string) file_get_contents(self::$saveDir . '/sess_' . $sid);
        $decoded = \ZealPHP\Session\php_session_decode_to_array($stored);
        $this->assertSame('five', $decoded[5] ?? null, $stored);
        $this->assertSame('seven', $decoded[7] ?? null, $stored);
        $this->assertArrayNotHasKey(0, $decoded, $stored);
    }

    /** A shard lock left by a dead worker is reclaimed; one held by a live process fails the request. */
    public function testShardLockFromADeadHolderIsReclaimedAndALiveOneTimesOut(): void
    {
        $prev = TableSessionHandler::$lockTimeoutSeconds;
        TableSessionHandler::$lockTimeoutSeconds = 0.2;
        $lockFor = new \ReflectionMethod(TableSessionHandler::class, 'lockFor');
        $acquire = new \ReflectionMethod(TableSessionHandler::class, 'acquire');
        $lock = $lockFor->invoke(self::$handler, 'lockprobe');
        $this->assertInstanceOf(\OpenSwoole\Atomic::class, $lock);
        try {
            $dead = 4194304 - 1;   // above pid_max on a default kernel: no such process
            $lock->set($dead);
            $acquire->invoke(self::$handler, $lock);
            $this->assertSame(getmypid(), $lock->get(), 'a dead holder\'s lock is reclaimed');

            $lock->set(1);   // pid 1 is always alive
            $this->expectException(\RuntimeException::class);
            $acquire->invoke(self::$handler, $lock);
        } finally {
            $lock->set(0);
            TableSessionHandler::$lockTimeoutSeconds = $prev;
        }
    }

    /** A go() child reports its request's cid, so per-request session snapshots are shared with it. */
    public function testChildCoroutineReportsTheOwningRequestCid(): void
    {
        $prev = \ZealPHP\App::$superglobals;
        \ZealPHP\App::superglobals(false);
        $seen = [];
        try {
            Coroutine::run(static function () use (&$seen): void {
                go(static function () use (&$seen): void {
                    RequestContext::instance();   // the request creates its context
                    $seen['owner'] = Coroutine::getCid();
                    $seen['parent'] = RequestContext::ownerCid();
                    go(static function () use (&$seen): void {
                        $seen['child'] = RequestContext::ownerCid();
                        $seen['childCid'] = Coroutine::getCid();
                    });
                });
            });
        } finally {
            \ZealPHP\App::superglobals($prev);
        }
        $this->assertSame($seen['owner'], $seen['parent']);
        $this->assertSame($seen['owner'], $seen['child']);
        $this->assertNotSame($seen['childCid'], $seen['child']);
    }
}
