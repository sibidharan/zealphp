<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

class LearnApiTest extends TestCase
{
    private string $aliceCookieJar;
    private string $bobCookieJar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aliceCookieJar = tempnam(sys_get_temp_dir(), 'lc_alice_');
        $this->bobCookieJar = tempnam(sys_get_temp_dir(), 'lc_bob_');
    }

    protected function tearDown(): void
    {
        @unlink($this->aliceCookieJar);
        @unlink($this->bobCookieJar);
    }

    private function req(string $cookieJar, string $method, string $path, ?array $body = null): array
    {
        $url = self::$baseUrl . $path;
        return $this->http($method, $path, $body ? ['Content-Type' => 'application/json'] : [], $body ? json_encode($body) : null);
    }

    public function test_unauth_endpoints_return_401(): void
    {
        $r = $this->http('POST', '/api/learn/notes', ['Content-Type' => 'application/json'], json_encode(['title' => 't', 'body' => 'b']));
        $this->assertSame(401, $r['status']);
    }

    public function test_chat_status_shape(): void
    {
        $r = $this->http('GET', '/api/learn/chat_status');
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('ai_enabled', $r['json']);
        $this->assertArrayHasKey('mock_mode', $r['json']);
        $this->assertArrayHasKey('model', $r['json']);
    }

    public function test_all_lesson_pages_return_200(): void
    {
        $slugs = ['', '/create-app', '/first-page', '/components', '/routing', '/sessions',
                   '/htmx', '/notes', '/ai-chat', '/async', '/deployment', '/philosophy'];
        foreach ($slugs as $s) {
            $r = $this->http('GET', '/learn' . $s);
            $this->assertSame(200, $r['status'], "/learn$s did not return 200");
        }
    }
}
