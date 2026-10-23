<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Response-level guarantees added in the security pass: the session is in the
 * store before a response's last byte goes out, sendFile() never lets a
 * browser sniff, and a multi-range request past the buffer cap is served as a
 * plain 200 instead of a whole-file in-memory multipart body.
 */
final class ResponseSecurityPassTest extends TestCase
{
    private const SID = 'abcdef0123456789abcdef0123456789';

    private string $dir = '';
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $g = RequestContext::instance();
        $g->status = null;
        $g->_streaming = false;
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $this->requestHeaders([]);
        $this->dir = sys_get_temp_dir() . '/zp_resp_sec_' . getmypid();
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->_session_started = false;
        $g->_session_persisted_hash = null;
        $g->session_params = [];
        $g->session_loaded_keys = [];
        $g->session_loaded_digest = [];
        unset($GLOBALS['_SESSION']);
        foreach (array_merge(glob($this->dir . '/*') ?: [], $this->tmpFiles) as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string, string> $headers */
    private function requestHeaders(array $headers): void
    {
        $or = new \OpenSwoole\Http\Request();
        $or->header = $headers;
        RequestContext::instance()->zealphp_request = new ZRequest($or);
    }

    private function startSession(): string
    {
        $g = RequestContext::instance();
        $g->_session_started = true;
        $g->_session_persisted_hash = null;
        $g->session_loaded_keys = [];
        $g->session_loaded_digest = [];
        $g->session_params = ['session_id' => self::SID, 'save_path' => $this->dir];
        $GLOBALS['_SESSION'] = ['user' => 'alice'];
        return $this->dir . '/sess_' . self::SID;
    }

    /**
     * A fake that records, at the moment end()/sendfile() runs, whether the
     * session file is already on disk.
     */
    private function recordingFake(string $sessionFile): FakeOpenSwooleResponse
    {
        return new class ($sessionFile) extends FakeOpenSwooleResponse {
            /** @var list<bool> */
            public array $storedAtSend = [];

            public function __construct(private string $sessionFile)
            {
            }

            public function end(?string $data = null): bool
            {
                $this->storedAtSend[] = is_file($this->sessionFile);
                return parent::end($data);
            }

            public function sendfile(string $fileName, int $offset = 0, int $length = 0): bool
            {
                $this->storedAtSend[] = is_file($this->sessionFile);
                return parent::sendfile($fileName, $offset, $length);
            }
        };
    }

    private function tempFile(string $contents, string $ext): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zp_resp_sec_') . '.' . $ext;
        file_put_contents($file, $contents);
        $this->tmpFiles[] = $file;
        return $file;
    }

    public function testEndStoresTheSessionFirst(): void
    {
        $sess = $this->startSession();
        $fake = $this->recordingFake($sess);
        (new ZResponse($fake))->end('bye');
        $this->assertSame([true], $fake->storedAtSend);
        $this->assertStringContainsString('alice', (string) file_get_contents($sess));
    }

    public function testRedirectStoresTheSessionFirst(): void
    {
        $sess = $this->startSession();
        $fake = $this->recordingFake($sess);
        (new ZResponse($fake))->redirect('/next');
        $this->assertSame([true], $fake->storedAtSend);
    }

    public function testSendFileStoresTheSessionFirst(): void
    {
        $sess = $this->startSession();
        $fake = $this->recordingFake($sess);
        (new ZResponse($fake))->sendFile($this->tempFile('hello', 'txt'));
        $this->assertSame([true], $fake->storedAtSend);
    }

    public function testStreamStoresTheSessionBeforeTheFinalEnd(): void
    {
        $sess = $this->startSession();
        $fake = $this->recordingFake($sess);
        (new ZResponse($fake))->stream(static function (callable $write): void {
            $write('chunk');
        });
        $this->assertSame([true], $fake->storedAtSend);
    }

    public function testSendFileSendsNosniff(): void
    {
        $fake = new FakeOpenSwooleResponse();
        (new ZResponse($fake))->sendFile($this->tempFile('hello', 'txt'));
        $this->assertContains(['header', 'X-Content-Type-Options', 'nosniff'],
            array_map(static fn (array $e): array => array_slice($e, 0, 3), $fake->log));
    }

    public function testMultiRangeAtTheBufferCapIsStillMultipart(): void
    {
        $limit = ZResponse::MULTIPART_BUFFER_LIMIT;
        $file = $this->tempFile(str_repeat('x', $limit + 5), 'bin');
        // 0-0 and 2-$limit: one byte apart (not coalesced), exactly $limit bytes.
        $this->requestHeaders(['range' => 'bytes=0-0,2-' . $limit]);
        $fake = new FakeOpenSwooleResponse();
        (new ZResponse($fake))->sendFile($file);
        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertSame([], array_values(array_filter($fake->log, static fn (array $e): bool => $e[0] === 'sendfile')));
    }

    public function testMultiRangePastTheBufferCapIsAPlain200ViaSendfile(): void
    {
        $limit = ZResponse::MULTIPART_BUFFER_LIMIT;
        $size = $limit + 5;
        $file = $this->tempFile(str_repeat('x', $size), 'bin');
        $this->requestHeaders(['range' => 'bytes=0-0,2-' . ($limit + 1)]);
        $fake = new FakeOpenSwooleResponse();
        (new ZResponse($fake))->sendFile($file);
        $this->assertNotContains(['status', 206, ''], $fake->log);
        $this->assertContains(['sendfile', $file, 0, $size], $fake->log, 'whole file, zero-copy');
    }

    public function testUppercaseHostlessSchemeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Redirect URL with a scheme but no readable host blocked: HTTPS:///evil.com (pass $allowExternal=true to permit)');
        (new ZResponse(new FakeOpenSwooleResponse()))->redirect('HTTPS:///evil.com');
    }
}
