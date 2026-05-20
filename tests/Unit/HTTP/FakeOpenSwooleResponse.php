<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

/**
 * Capturing test double for OpenSwoole\Http\Response.
 *
 * ZealPHP\HTTP\Response forwards to an underlying OpenSwoole response. In a
 * unit test there is no live socket, so we subclass the real OpenSwoole class
 * (it is non-final and has no constructor) and record every outbound call into
 * {@see $log} for assertion. Set {@see $writable} to false to simulate a
 * disconnected client.
 *
 * Each log entry is a list whose first element is the method name, e.g.
 *   ['status', 201, '']
 *   ['header', 'X-Foo', 'bar']
 *   ['write', 'chunk']
 *   ['end', null]
 *   ['sendfile', '/path', 0, 100]
 */
class FakeOpenSwooleResponse extends \OpenSwoole\Http\Response
{
    /** @var array<int, array<int, mixed>> */
    public array $log = [];

    public bool $writable = true;

    public function status(int $statusCode, string $reason = ''): bool
    {
        $this->log[] = ['status', $statusCode, $reason];
        return true;
    }

    public function header(string $key, string $value, bool $format = true): bool
    {
        $this->log[] = ['header', $key, $value];
        return true;
    }

    public function cookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool
    {
        $this->log[] = ['cookie', $key, $value];
        return true;
    }

    public function rawCookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool
    {
        $this->log[] = ['rawCookie', $key, $value];
        return true;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $data): bool
    {
        $this->log[] = ['write', $data];
        return true;
    }

    public function end(?string $data = null): bool
    {
        $this->log[] = ['end', $data];
        return true;
    }

    public function sendfile(string $fileName, int $offset = 0, int $length = 0): bool
    {
        $this->log[] = ['sendfile', $fileName, $offset, $length];
        return true;
    }

    public function detach(): bool
    {
        $this->log[] = ['detach'];
        return true;
    }
}
