<?php
namespace ZealPHP\HTTP\Factory;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use OpenSwoole\Core\Psr\ServerRequest;

class ServerRequestFactory implements ServerRequestFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($uri, $method, '', [], [], [], $serverParams);
    }
}
