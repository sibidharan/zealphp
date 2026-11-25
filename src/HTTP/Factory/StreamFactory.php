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
        // Not Stream::createStreamFromFile(): openswoole/core throws an unqualified
        // RuntimeException / InvalidArgumentException inside its namespace, so
        // PSR-17's error cases end in Error: Class "OpenSwoole\Core\Psr\..." not found.
        // Same mode check and messages as that method.
        if ($mode === '' || !preg_match('/^[rwaxce]{1}[bt]{0,1}[+]{0,1}+$/', $mode)) {
            throw new \InvalidArgumentException(sprintf('Invalid file opening mode "%s"', $mode));
        }

        $resource = @fopen($filename, $mode);

        if (!is_resource($resource)) {
            throw new \RuntimeException(sprintf('Unable to open file at "%s"', $filename));
        }

        return new Stream($resource);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }
}
