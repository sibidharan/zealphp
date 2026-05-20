<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Branch coverage for ZealPHP\HTTP\Response not reached by ResponseTest.php:
 *
 *   - __get / __set proxies (forward to parent, the parent special-case,
 *     unknown-property write lands on $this, unknown-property read throws)
 *   - sendFile() MIME-guess match arms for extensions whose mime_content_type
 *     resolves to octet-stream (woff/woff2/ttf/otf/avif/webm/svg/xml/json…)
 *   - sendFile() request-header guards: non-array header bag, non-string
 *     if-none-match / if-modified-since / range values
 *   - sendFile() open-ended range (bytes=N-) → end defaults to total-1
 */
class ResponseExtraTest extends TestCase
{
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
        $g->server = [];
        $this->setRequestHeaders([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    private function wrap(?FakeOpenSwooleResponse $fake = null): ZResponse
    {
        return new ZResponse($fake ?? new FakeOpenSwooleResponse());
    }

    /** @param array<string, mixed> $headers */
    private function setRequestHeaders(array $headers): void
    {
        $g = RequestContext::instance();
        $or = new \OpenSwoole\Http\Request();
        $or->header = $headers;
        $g->zealphp_request = new ZRequest($or);
    }

    private function makeTempFile(string $contents, string $ext): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zealphp_respx_') . '.' . $ext;
        file_put_contents($file, $contents);
        $this->tmpFiles[] = $file;
        return $file;
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    private function headerCalls(FakeOpenSwooleResponse $fake): array
    {
        $out = [];
        foreach ($fake->log as $entry) {
            if (($entry[0] ?? null) === 'header') {
                /** @var array{0: string, 1: string, 2: string} $entry */
                $out[] = $entry;
            }
        }
        return $out;
    }

    // ---- __get / __set proxies --------------------------------------------

    public function testGetProxiesParentProperty(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $fake->fd = 77;
        $resp = $this->wrap($fake);
        // 'fd' exists on the parent → __get forwards.
        $this->assertSame(77, $resp->fd);
    }

    public function testGetUnknownPropertyThrows(): void
    {
        $resp = $this->wrap();
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore property.notFound */
        $x = $resp->nonexistent_property;
    }

    public function testSetProxiesParentProperty(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->fd = 99; // 'fd' exists on parent → __set forwards.
        $this->assertSame(99, $fake->fd);
    }

    public function testSetParentSpecialCaseReassignsUnderlying(): void
    {
        $resp = $this->wrap();
        $newParent = new FakeOpenSwooleResponse();
        $resp->parent = $newParent;
        $this->assertSame($newParent, $resp->parent);
    }

    public function testSetUnknownPropertyLandsOnWrapper(): void
    {
        $resp = $this->wrap();
        /** @phpstan-ignore property.notFound */
        $resp->custom_attr = 'value';
        /** @phpstan-ignore property.notFound */
        $this->assertSame('value', $resp->custom_attr);
    }

    // ---- sendFile() MIME-guess match arms ---------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mimeGuessProvider(): array
    {
        // ext => expected guessed mime. These extensions resolve to
        // octet-stream via mime_content_type, so the match() block runs.
        return [
            'woff'  => ['woff',  'font/woff'],
            'woff2' => ['woff2', 'font/woff2'],
            'ttf'   => ['ttf',   'font/ttf'],
            'otf'   => ['otf',   'font/otf'],
            'avif'  => ['avif',  'image/avif'],
            'webm'  => ['webm',  'video/webm'],
            'webp'  => ['webp',  'image/webp'],
            'mp4'   => ['mp4',   'video/mp4'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mimeGuessProvider')]
    public function testSendFileGuessesMimeFromExtension(string $ext, string $expectedMime): void
    {
        $path = $this->makeTempFile(str_repeat('z', 64), $ext);
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $headers = $this->headerCalls($fake);
        $cts = array_filter($headers, static fn(array $h): bool => $h[1] === 'Content-Type');
        $ctValues = array_map(static fn(array $h): string => $h[2], $cts);
        $this->assertContains($expectedMime, $ctValues, "Expected $expectedMime for .$ext");
    }

    // ---- sendFile() request-header guards ---------------------------------

    public function testSendFileNonArrayHeaderBag(): void
    {
        // parent->header is a string (not array) → the !is_array guard runs.
        $g = RequestContext::instance();
        $or = new \OpenSwoole\Http\Request();
        $or->header = 'not-an-array';
        $g->zealphp_request = new ZRequest($or);

        $path = $this->makeTempFile(str_repeat('a', 32), 'css');
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);

        // Falls through to a normal full-file send.
        $this->assertContains(['sendfile', $path, 0, 32], $fake->log);
    }

    public function testSendFileNonStringConditionalHeaders(): void
    {
        // Array-valued conditional headers → the !is_string guards coerce to ''.
        $this->setRequestHeaders([
            'if-none-match'     => ['a', 'b'],
            'if-modified-since' => ['x'],
            'range'             => ['bytes=0-1'],
        ]);
        $path = $this->makeTempFile(str_repeat('b', 40), 'css');
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);

        // None of the conditional/range branches taken → full send.
        $this->assertContains(['sendfile', $path, 0, 40], $fake->log);
    }

    public function testSendFileOpenEndedRange(): void
    {
        // bytes=10- → start=10, end defaults to total-1.
        $this->setRequestHeaders(['range' => 'bytes=10-']);
        $path = $this->makeTempFile(str_repeat('c', 100), 'css');
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 10-99/100'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 10, 90], $fake->log);
    }
}
