<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use ZealPHP\HTTP\Client;
use ZealPHP\HTTP\Client\NetworkException;
use ZealPHP\HTTP\Client\RequestException;
use OpenSwoole\Core\Psr\Request;

class HttpClientTest extends TestCase
{
    public function testImplementsClientInterface(): void
    {
        $this->assertInstanceOf(ClientInterface::class, new Client());
    }

    public function testNetworkExceptionImplementsInterface(): void
    {
        $request = new Request('http://example.com', 'GET');
        $exception = new NetworkException($request, 'connection failed');
        $this->assertInstanceOf(NetworkExceptionInterface::class, $exception);
        $this->assertInstanceOf(ClientExceptionInterface::class, $exception);
        $this->assertSame($request, $exception->getRequest());
        $this->assertSame('connection failed', $exception->getMessage());
    }

    public function testRequestExceptionImplementsInterface(): void
    {
        $request = new Request('http://example.com', 'GET');
        $exception = new RequestException($request, 'bad request');
        $this->assertInstanceOf(RequestExceptionInterface::class, $exception);
        $this->assertInstanceOf(ClientExceptionInterface::class, $exception);
        $this->assertSame($request, $exception->getRequest());
    }

    public function testEmptyUriThrowsRequestException(): void
    {
        $client = new Client();
        $request = new Request('', 'GET');
        $this->expectException(RequestExceptionInterface::class);
        $client->sendRequest($request);
    }

    public function testCustomOptions(): void
    {
        $client = new Client(['timeout' => 10, 'verify_ssl' => false, 'max_redirects' => 0]);
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    // -- Exception constructor contract (parent::__construct round-trip) --

    /**
     * Default $code is 0. Kills the Increment (0→1) and Decrement (0→-1)
     * mutants on RequestException's default code argument.
     */
    public function testRequestExceptionDefaultCodeIsZero(): void
    {
        $request = new Request('http://example.com', 'GET');
        $exception = new RequestException($request, 'bad request');
        $this->assertSame(0, $exception->getCode());
    }

    /**
     * parent::__construct() must wire message, code, and previous through to
     * RuntimeException. Removing that call (MethodCallRemoval) would drop all
     * three. Asserting an explicit non-zero code + previous pins the call.
     */
    public function testRequestExceptionForwardsMessageCodeAndPrevious(): void
    {
        $request = new Request('http://example.com', 'GET');
        $previous = new \RuntimeException('root cause');
        $exception = new RequestException($request, 'bad request', 422, $previous);
        $this->assertSame('bad request', $exception->getMessage());
        $this->assertSame(422, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame($request, $exception->getRequest());
    }

    /**
     * Default $code is 0 on NetworkException too. Kills its Increment /
     * Decrement default-code mutants.
     */
    public function testNetworkExceptionDefaultCodeIsZero(): void
    {
        $request = new Request('http://example.com', 'GET');
        $exception = new NetworkException($request, 'connection failed');
        $this->assertSame(0, $exception->getCode());
    }

    public function testNetworkExceptionForwardsMessageCodeAndPrevious(): void
    {
        $request = new Request('http://example.com', 'GET');
        $previous = new \RuntimeException('socket error');
        $exception = new NetworkException($request, 'connection failed', 503, $previous);
        $this->assertSame('connection failed', $exception->getMessage());
        $this->assertSame(503, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame($request, $exception->getRequest());
    }

    // -- HEAD requests (RFC 9110 §9.3.2) --

    /**
     * A HEAD response carries the Content-Length a GET would return but no
     * body. Without CURLOPT_NOBODY, libcurl waits for those bytes on a
     * keep-alive connection until CURLOPT_TIMEOUT, then the client throws
     * NetworkException (cURL error 28) instead of returning the response.
     */
    public function testHeadRequestReturnsHeadersWithoutWaitingForBody(): void
    {
        [$proc, $port] = $this->startKeepAliveServer();
        try {
            $client = new Client(['timeout' => 2]);
            $start = microtime(true);
            $response = $client->sendRequest(new Request("http://127.0.0.1:{$port}/", 'HEAD'));
            $elapsed = microtime(true) - $start;

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('5', $response->getHeaderLine('Content-Length'));
            $this->assertSame('HEAD', $response->getHeaderLine('X-Method'));
            $this->assertSame('', (string) $response->getBody());
            $this->assertLessThan(1.5, $elapsed);
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
    }

    /**
     * GET against the same server still reads the body (the HEAD guard must
     * not leak to other methods).
     */
    public function testGetRequestStillReadsBody(): void
    {
        [$proc, $port] = $this->startKeepAliveServer();
        try {
            $client = new Client(['timeout' => 2]);
            $response = $client->sendRequest(new Request("http://127.0.0.1:{$port}/", 'GET'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('GET', $response->getHeaderLine('X-Method'));
            $this->assertSame('hello', (string) $response->getBody());
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
    }

    /**
     * Start a loopback HTTP/1.1 server that keeps the connection open and
     * answers HEAD per RFC 9110 (Content-Length of the GET body, no body).
     *
     * @return array{0: resource, 1: int}
     */
    private function startKeepAliveServer(): array
    {
        $code = <<<'PHP'
            $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $name = stream_socket_get_name($s, false);
            echo substr($name, strrpos($name, ':') + 1), "\n";
            while ($c = @stream_socket_accept($s, 10)) {
                while (($line = fgets($c)) !== false) {
                    $method = strtok($line, ' ');
                    while (($h = fgets($c)) !== false && rtrim($h) !== '') {
                    }
                    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nX-Method: {$method}\r\n"
                        . "Connection: keep-alive\r\n\r\n" . ($method === 'HEAD' ? '' : 'hello'));
                }
                fclose($c);
            }
            PHP;
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $port = (int) fgets($pipes[1]);
        if ($port <= 0) {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
        $this->assertGreaterThan(0, $port);

        return [$proc, $port];
    }
}
