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
}
