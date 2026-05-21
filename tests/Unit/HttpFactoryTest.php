<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use ZealPHP\HTTP\Factory\RequestFactory;
use ZealPHP\HTTP\Factory\ResponseFactory;
use ZealPHP\HTTP\Factory\ServerRequestFactory;
use ZealPHP\HTTP\Factory\StreamFactory;
use ZealPHP\HTTP\Factory\UploadedFileFactory;
use ZealPHP\HTTP\Factory\UriFactory;

class HttpFactoryTest extends TestCase
{
    public function testRequestFactory(): void
    {
        $factory = new RequestFactory();
        $request = $factory->createRequest('GET', 'http://example.com/path');
        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('GET', $request->getMethod());
    }

    public function testResponseFactory(): void
    {
        $factory = new ResponseFactory();
        $response = $factory->createResponse(404, 'Not Found');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getReasonPhrase());
    }

    public function testResponseFactoryDefaults(): void
    {
        $response = (new ResponseFactory())->createResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStreamFactory(): void
    {
        $stream = (new StreamFactory())->createStream('hello');
        $this->assertInstanceOf(StreamInterface::class, $stream);
        $this->assertSame('hello', (string) $stream);
    }

    public function testStreamFromFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zealphp_test_');
        file_put_contents($tmp, 'file content');
        $stream = (new StreamFactory())->createStreamFromFile($tmp);
        $this->assertSame('file content', (string) $stream);
        @unlink($tmp);
    }

    public function testStreamFromResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, 'resource content');
        fseek($resource, 0);
        $stream = (new StreamFactory())->createStreamFromResource($resource);
        $this->assertInstanceOf(StreamInterface::class, $stream);
        $this->assertSame('resource content', (string) $stream);
    }

    public function testUriFactory(): void
    {
        $uri = (new UriFactory())->createUri('http://example.com:8080/path?q=1');
        $this->assertInstanceOf(UriInterface::class, $uri);
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame(8080, $uri->getPort());
        $this->assertSame('/path', $uri->getPath());
        $this->assertSame('q=1', $uri->getQuery());
    }

    public function testServerRequestFactory(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://example.com/api', ['SERVER_NAME' => 'example.com']);
        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(['SERVER_NAME' => 'example.com'], $request->getServerParams());
    }

    public function testUploadedFileFactory(): void
    {
        $stream = (new StreamFactory())->createStream('uploaded data');
        $file = (new UploadedFileFactory())->createUploadedFile($stream, 13, \UPLOAD_ERR_OK, 'test.txt', 'text/plain');
        $this->assertInstanceOf(UploadedFileInterface::class, $file);
        $this->assertSame(13, $file->getSize());
        $this->assertSame(\UPLOAD_ERR_OK, $file->getError());
        $this->assertSame('test.txt', $file->getClientFilename());
        $this->assertSame('text/plain', $file->getClientMediaType());
    }

    /**
     * Build a StreamInterface stub returning a fixed getSize() value (or null).
     * Used to exercise UploadedFileFactory's `$size ?? $stream->getSize() ?? 0`
     * coalesce chain at its edges.
     */
    private function sizedStream(?int $size): StreamInterface
    {
        return new class ($size) implements StreamInterface {
            public function __construct(private ?int $size)
            {
            }
            public function getSize(): ?int
            {
                return $this->size;
            }
            public function __toString(): string
            {
                return '';
            }
            public function close(): void
            {
            }
            public function detach()
            {
                return null;
            }
            public function tell(): int
            {
                return 0;
            }
            public function eof(): bool
            {
                return true;
            }
            public function isSeekable(): bool
            {
                return false;
            }
            public function seek(int $offset, int $whence = SEEK_SET): void
            {
            }
            public function rewind(): void
            {
            }
            public function isWritable(): bool
            {
                return false;
            }
            public function write(string $string): int
            {
                return 0;
            }
            public function isReadable(): bool
            {
                return false;
            }
            public function read(int $length): string
            {
                return '';
            }
            public function getContents(): string
            {
                return '';
            }
            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };
    }

    /**
     * Explicit $size wins over the stream's reported size. Kills the
     * `getSize() ?? $size ?? 0` reordering mutant — with that order the
     * stream's 99 would be used instead of the explicit 13.
     */
    public function testUploadedFileExplicitSizeWinsOverStreamSize(): void
    {
        $stream = $this->sizedStream(99);
        $file = (new UploadedFileFactory())->createUploadedFile($stream, 13);
        $this->assertSame(13, $file->getSize());
    }

    /**
     * When $size is null, the stream's getSize() is used. Kills the
     * `$size ?? 0 ?? getSize()` reordering mutant — that order would yield 0
     * instead of the stream's 77.
     */
    public function testUploadedFileFallsBackToStreamSize(): void
    {
        $stream = $this->sizedStream(77);
        $file = (new UploadedFileFactory())->createUploadedFile($stream, null);
        $this->assertSame(77, $file->getSize());
    }

    /**
     * Both $size and the stream's getSize() are null → the literal 0 fallback.
     * Kills the Increment (0→1) and Decrement (0→-1) integer mutants.
     */
    public function testUploadedFileZeroFallbackWhenNoSize(): void
    {
        $stream = $this->sizedStream(null);
        $file = (new UploadedFileFactory())->createUploadedFile($stream, null);
        $this->assertSame(0, $file->getSize());
    }
}
