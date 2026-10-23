<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\Tests\TestCase;

/**
 * Request headers → HTTP_* server vars: a client `Proxy:` header is dropped
 * (httpoxy), and an underscore-named header can't overwrite the dashed header
 * that maps to the same key.
 */
final class ServerVarsHeaderMappingTest extends TestCase
{
    /** @param array<string, string> $headers @return array<string, mixed> */
    private function serverVars(array $headers): array
    {
        $or = new \OpenSwoole\Http\Request();
        $or->header = $headers;
        $or->server = ['request_method' => 'GET', 'request_uri' => '/', 'server_protocol' => 'HTTP/1.1', 'remote_addr' => '127.0.0.1', 'server_port' => 80];
        $m = new \ReflectionMethod(App::class, 'buildServerVars');
        /** @var array<string, mixed> */
        return $m->invoke(null, new ZRequest($or));
    }

    public function testProxyHeaderNeverBecomesHttpProxy(): void
    {
        $srv = $this->serverVars(['proxy' => 'http://evil.test:3128', 'host' => 'app.test']);
        $this->assertArrayNotHasKey('HTTP_PROXY', $srv);
        $this->assertSame('app.test', $srv['HTTP_HOST']);
    }

    public function testUnderscoreHeaderCannotOverwriteItsDashedTwin(): void
    {
        $srv = $this->serverVars(['content_length' => '999', 'content-length' => '5', 'x_forwarded_for' => '6.6.6.6', 'x-forwarded-for' => '1.2.3.4']);
        $this->assertSame('5', $srv['HTTP_CONTENT_LENGTH']);
        $this->assertSame('1.2.3.4', $srv['HTTP_X_FORWARDED_FOR']);
        // Order of arrival must not matter.
        $srv = $this->serverVars(['x-forwarded-for' => '1.2.3.4', 'x_forwarded_for' => '6.6.6.6']);
        $this->assertSame('1.2.3.4', $srv['HTTP_X_FORWARDED_FOR']);
    }

    public function testUnderscoreHeaderAloneIsStillVisible(): void
    {
        $srv = $this->serverVars(['x_api_key' => 'k']);
        $this->assertSame('k', $srv['HTTP_X_API_KEY']);
    }
}
