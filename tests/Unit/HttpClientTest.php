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
}
