<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use OpenSwoole\Coroutine;
use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\Session\Handler\TableSessionHandler;

use function ZealPHP\Session\php_session_decode_to_array;
use function ZealPHP\Session\php_session_encode_from_array;
use function ZealPHP\Session\zeal_session_destroy;
use function ZealPHP\Session\zeal_session_gc;
use function ZealPHP\Session\zeal_session_merge_onto_store;
use function ZealPHP\Session\zeal_session_write_close;

/** Handler that counts close() calls. */
final class CloseCountingSessionHandler implements \SessionHandlerInterface
{
    public int $closes = 0;
    public string $stored = '';
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { $this->closes++; return true; }
    public function read(string $id): string|false { return $this->stored; }
    public function write(string $id, string $data): bool { $this->stored = $data; return true; }
    public function destroy(string $id): bool { $this->stored = ''; return true; }
    public function gc(int $maxlifetime): int|false { return 0; }
}

/**
 * Session storage hardening: the decoder, the merge onto the store, the file
 * and Table handlers and session GC.
 */
final class SessionStoreHardeningTest extends TestCase
{
    private string $dir = '';
    private string $savedSavePath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zp-ssh-' . bin2hex(random_bytes(5));
        @mkdir($this->dir, 0700, true);
        $this->savedSavePath = App::$session_save_path;
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->session = [];
        $g->session_params = ['name' => 'PHPSESSID', 'save_path' => $this->dir, 'session_id' => 'ssh-sid'];
        $g->session_loaded_keys = [];
        $g->session_loaded_digest = [];
        $g->_session_persisted_hash = null;
        $g->_session_started = null;
        $g->cookie = ['PHPSESSID' => 'ssh-sid'];
    }

    protected function tearDown(): void
    {
        App::$session_save_path = $this->savedSavePath;
        App::sessionHandler(null);
        $g = RequestContext::instance();
        $g->session_params = [];
        $g->session_loaded_keys = [];
        $g->session_loaded_digest = [];
        $g->cookie = [];
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    // ── decoder ───────────────────────────────────────────────────────────

    public function testStoredStringContainingAKeyIsNotParsedAsKeys(): void
    {
        $this->assertSame(['a' => 'x;r|b:1;y'], php_session_decode_to_array('a|s:9:"x;r|b:1;' . 'y";'));
    }

    public function testBrokenValueAbortsTheDecodeInsteadOfResumingInsideIt(): void
    {
        // The value's length does not match: native session_decode() fails the
        // whole session. Skipping to the next `;` resumed parsing inside the
        // attacker's string and created `r`.
        $this->assertSame([], php_session_decode_to_array('a|s:99:"x;r|b:1;";'));
        $this->assertSame([], php_session_decode_to_array('a|s:3:"x;r|b:1;y";'));
        $this->assertSame([], php_session_decode_to_array('ok|i:1;a|s:3:"x;role|s:5:"admin";";'));
    }

    public function testValueThatDoesNotEndWhereItsReserializationEndsIsCorrupt(): void
    {
        // `d:1.0;` unserializes but re-serializes as `d:1;`: the next key would
        // be read two bytes early.
        $this->assertSame([], php_session_decode_to_array('a|d:1.0;b|i:2;'));
        $this->assertSame(['a' => 1.5, 'b' => 2], php_session_decode_to_array('a|d:1.5;b|i:2;'));
    }

    public function testEncoderRefusesAKeyContainingTheSeparator(): void
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool { $warned = true; return true; }, E_USER_WARNING);
        try {
            $encoded = php_session_encode_from_array(['a|role' => 'x', 'ok' => 1]);
        } finally {
            restore_error_handler();
        }
        $this->assertTrue($warned);
        $this->assertSame('ok|i:1;', $encoded);
        $this->assertSame(['ok' => 1], php_session_decode_to_array($encoded));
    }

    // ── merge onto the current store ──────────────────────────────────────

    public function testMergeWritesOnlyWhatThisRequestChanged(): void
    {
        $loaded = ['user' => 'alice', 'tmp' => 'x'];
        $digest = [];
        foreach ($loaded as $k => $v) {
            $digest[$k] = md5(serialize($v));
        }
        // Another request changed `user` and removed `tmp`; this one only added `seen`.
        $store = ['user' => 'bob'];
        $mine = $loaded + ['seen' => 1];
        $this->assertSame(
            ['user' => 'bob', 'seen' => 1],
            zeal_session_merge_onto_store($store, $mine, $digest, array_keys($loaded))
        );
    }

    public function testMergeSeesInPlaceObjectMutation(): void
    {
        $obj = new \stdClass();
        $obj->n = 1;
        $digest = ['o' => md5(serialize($obj))];
        $obj->n = 2; // same handle, mutated after load
        $merged = zeal_session_merge_onto_store(['o' => (object) ['n' => 1]], ['o' => $obj], $digest, ['o']);
        $this->assertInstanceOf(\stdClass::class, $merged['o']);
        $this->assertSame(2, $merged['o']->n);
    }

    public function testWriteCloseDoesNotRecreateADestroyedHandlerSession(): void
    {
        $h = new CloseCountingSessionHandler(); // store is empty: destroyed by another request
        $g = RequestContext::instance();
        $g->session_params['handler'] = $h;
        $g->session = ['user' => 'alice'];
        $g->session_loaded_keys = ['user'];
        $g->session_loaded_digest = ['user' => md5(serialize('alice'))];
        $g->_session_started = true;

        $this->assertTrue(zeal_session_write_close());
        $this->assertSame('', $h->stored, 'the destroyed session was written back');
    }

    public function testWriteCloseDoesNotRecreateADestroyedFileSession(): void
    {
        $g = RequestContext::instance();
        $g->session = ['user' => 'alice'];
        $g->session_loaded_keys = ['user'];
        $g->session_loaded_digest = ['user' => md5(serialize('alice'))];
        $g->_session_started = true;

        $this->assertTrue(zeal_session_write_close());
        $this->assertFileDoesNotExist($this->dir . '/sess_ssh-sid', 'the destroyed session file was recreated');
    }

    public function testWriteCloseStillCreatesANewSession(): void
    {
        $g = RequestContext::instance();
        $g->session = ['user' => 'alice'];
        $g->_session_started = true;

        $this->assertTrue(zeal_session_write_close());
        $this->assertSame(['user' => 'alice'], php_session_decode_to_array((string) file_get_contents($this->dir . '/sess_ssh-sid')));
    }

    // ── handler close() ───────────────────────────────────────────────────

    public function testWriteCloseAndDestroyCallTheHandlersClose(): void
    {
        $h = new CloseCountingSessionHandler();
        $g = RequestContext::instance();
        $g->session_params['handler'] = $h;
        $g->session_params['handler_opened'] = true;
        $g->session = ['a' => 1];
        $g->_session_started = true;
        zeal_session_write_close();
        $this->assertSame(1, $h->closes, 'session_write_close() must close the handler');

        $g->session_params['handler_opened'] = true;
        zeal_session_destroy();
        $this->assertSame(2, $h->closes, 'session_destroy() must close the handler');
    }

    // ── FileSessionHandler ────────────────────────────────────────────────

    public function testFileHandlerGcBeforeOpenDoesNotThrow(): void
    {
        $this->assertSame(0, (new FileSessionHandler())->gc(60));
    }

    public function testFileHandlerReadWaitsForAWriteInProgress(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        $h = new FileSessionHandler();
        $h->open($this->dir, 'PHPSESSID');
        $full = 'user|s:5:"alice";role|s:4:"user";';
        $h->write('torn', $full);
        $file = $this->dir . '/sess_torn';

        $pid = pcntl_fork();
        if ($pid === 0) {
            // A writer that holds the lock across truncate + two half writes.
            $fp = fopen($file, 'c+');
            if ($fp !== false && flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, substr($full, 0, 10));
                fflush($fp);
                usleep(400000);
                fwrite($fp, substr($full, 10));
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            posix_kill(getmypid(), SIGKILL);
        }
        usleep(100000);
        $read = $h->read('torn');
        pcntl_waitpid($pid, $status);
        $this->assertSame($full, $read, 'read() returned a half-written session');
    }

    // ── session GC ────────────────────────────────────────────────────────

    public function testGcOutsideARequestUsesTheConfiguredSavePath(): void
    {
        App::$session_save_path = $this->dir;
        RequestContext::instance()->session_params = []; // the GC timer has no request
        $old = $this->dir . '/sess_old';
        file_put_contents($old, 'a|i:1;');
        touch($old, time() - 3600);
        $fresh = $this->dir . '/sess_fresh';
        file_put_contents($fresh, 'a|i:1;');

        $this->assertSame(1, zeal_session_gc(60));
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
    }

    public function testGcWithTheFileHandlerOutsideARequest(): void
    {
        App::$session_save_path = $this->dir;
        App::sessionHandler('file');
        RequestContext::instance()->session_params = [];
        $old = $this->dir . '/sess_old';
        file_put_contents($old, 'a|i:1;');
        touch($old, time() - 3600);

        zeal_session_gc(60); // threw "must not be accessed before initialization"
        $this->assertFileDoesNotExist($old);
    }

    // ── TableSessionHandler ───────────────────────────────────────────────

    private function tableHandler(): TableSessionHandler
    {
        return TableSessionHandler::register(ttl: 3600, maxRows: 256, dataSize: 16384, savePath: $this->dir);
    }

    private function rawTable(): Table
    {
        $p = new \ReflectionProperty(TableSessionHandler::class, 'table');
        $t = $p->getValue();
        $this->assertInstanceOf(Table::class, $t);
        return $t;
    }

    public function testTableReadOfAnUnknownIdDoesNotCreateARow(): void
    {
        $h = $this->tableHandler();
        $before = $this->rawTable()->count();
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame('', $h->read('forged_' . bin2hex(random_bytes(6))));
        }
        $this->assertSame($before, $this->rawTable()->count(), 'forged ids filled the Table');
        $h->close();
    }

    public function testTableReadSnapshotIsFreedWhenTheCoroutineEnds(): void
    {
        $h = $this->tableHandler();
        $sid = 'snap_' . bin2hex(random_bytes(4));
        $h->write($sid, 'v|i:1;');
        Coroutine::run(static function () use ($h, $sid): void {
            Coroutine::create(static function () use ($h, $sid): void {
                $h->read($sid); // never closed — as zeal_session_* used to do
            });
        });
        $ctx = (new \ReflectionProperty($h, 'context'))->getValue($h);
        $this->assertIsArray($ctx);
        foreach (array_keys($ctx) as $cid) {
            $this->assertLessThan(1, $cid, 'a finished coroutine kept its read snapshot');
        }
        $h->close();
    }

    public function testTableSessionTooBigForTheRowIsStoredOnDiskAndNotServedStale(): void
    {
        $h = $this->tableHandler();
        $sid = 'big_' . bin2hex(random_bytes(4));
        $big = 'b|' . serialize(str_repeat('x', 70000)); // bigger than any row this suite registers
        $h->write($sid, 'b|s:3:"old";');
        $h->read($sid);
        $this->assertTrue($h->write($sid, $big));
        $this->assertFalse($this->rawTable()->exists($sid), 'the old row is still there');
        $this->assertSame($big, $h->read($sid));
        $h->write($sid, 'b|s:3:"new";');
        $this->assertSame('b|s:3:"new";', $h->read($sid));
        $h->close();
    }

    public function testTableWriteDoesNotRecreateASessionDestroyedSinceItsRead(): void
    {
        $h = $this->tableHandler();
        $sid = 'gone_' . bin2hex(random_bytes(4));
        $h->write($sid, 'user|s:5:"alice";');
        $h->read($sid);
        // Another request destroys it (white-box: what destroy() removes).
        $this->rawTable()->del($sid);
        @unlink($this->dir . '/sess_' . $sid);

        $this->assertFalse($h->write($sid, 'user|s:5:"alice";seen|i:1;'));
        $this->assertSame('', $h->read($sid), 'the destroyed session was recreated');
        $h->close();
    }

    public function testTableKeysLongerThanTheTableAllowsCanBeFoundAgain(): void
    {
        $h = $this->tableHandler();
        $a = str_repeat('f', 63) . '1';
        $b = str_repeat('f', 63) . '2';
        $h->write($a, 'who|s:1:"a";');
        $h->write($b, 'who|s:1:"b";');
        $this->assertSame('who|s:1:"a";', $h->read($a));
        $this->assertSame('who|s:1:"b";', $h->read($b));
        foreach ($this->rawTable() as $key => $_row) {
            $this->assertTrue($this->rawTable()->exists((string) $key), "stored key '$key' cannot be found again");
        }
        $h->destroy($a);
        $h->destroy($b);
        $h->close();
    }
}
