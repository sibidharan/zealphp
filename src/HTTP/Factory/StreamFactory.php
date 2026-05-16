<?php
namespace ZealPHP\HTTP\Factory;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use OpenSwoole\Core\Psr\Stream;

class StreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        $stream = Stream::streamFor($content);
        assert($stream instanceof StreamInterface);
        return $stream;
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $stream = Stream::createStreamFromFile($filename, $mode);
        assert($stream instanceof StreamInterface);
        return $stream;
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }
}
