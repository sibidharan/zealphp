<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\Tests\TestCase;

/**
 * Characterization tests for ZealPHP\HTTP\Request.
 *
 * The wrapper extends OpenSwoole\Http\Request and binds its public arrays
 * (header/server/cookie/get/post/files/tmpfiles) by reference to the
 * underlying request, plus __call/__get/__set proxies. We construct a real
 * (instantiable, constructor-less) OpenSwoole request, populate its public
 * arrays, and verify the wrapper exposes them.
 */
class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    private function makeUnderlying(): \OpenSwoole\Http\Request
    {
        $r = new \OpenSwoole\Http\Request();
        $r->header = ['host' => 'example.test', 'x-foo' => 'bar'];
        $r->server = ['request_method' => 'GET', 'request_uri' => '/path'];
        $r->cookie = ['PHPSESSID' => 'abc123'];
        $r->get = ['q' => 'search', 'page' => '2'];
        $r->post = ['name' => 'Alice'];
        $r->files = ['upload' => ['name' => 'a.txt']];
        $r->tmpfiles = ['tmp' => '/tmp/x'];
        return $r;
    }

    public function testConstructorBindsHeaderArray(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->assertSame(['host' => 'example.test', 'x-foo' => 'bar'], $req->header);
    }

    public function testConstructorBindsServerArray(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->assertSame(['request_method' => 'GET', 'request_uri' => '/path'], $req->server);
    }

    public function testConstructorBindsCookieArray(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->assertSame(['PHPSESSID' => 'abc123'], $req->cookie);
    }

    public function testConstructorBindsGetArray(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->assertSame(['q' => 'search', 'page' => '2'], $req->get);
    }

    public function testConstructorBindsPostArray(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->assertSame(['name' => 'Alice'], $req->post);
    }

    public function testConstructorBindsFilesArray(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->assertSame(['upload' => ['name' => 'a.txt']], $req->files);
    }

    public function testConstructorBindsTmpfilesArray(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->assertSame(['tmp' => '/tmp/x'], $req->tmpfiles);
    }

    public function testHeaderArrayIsBoundByReference(): void
    {
        $underlying = $this->makeUnderlying();
        $req = new ZRequest($underlying);

        // Mutating the underlying header array is visible through the wrapper
        // because the wrapper binds it by reference.
        $newHeaders = ['host' => 'example.test', 'x-new' => 'added'];
        $underlying->header = $newHeaders;
        $this->assertSame($newHeaders, $req->header);
    }

    public function testGetParentReturnsUnderlyingRequest(): void
    {
        $underlying = $this->makeUnderlying();
        $req = new ZRequest($underlying);
        $this->assertSame($underlying, $req->parent);
    }

    public function testCallThrowsForUnknownMethod(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->expectException(\BadMethodCallException::class);
        // @phpstan-ignore method.notFound
        $req->definitelyNotAMethod();
    }

    public function testGetThrowsForUnknownProperty(): void
    {
        $req = new ZRequest($this->makeUnderlying());
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore property.notFound */
        $unused = $req->thisPropertyDoesNotExist;
    }
}
