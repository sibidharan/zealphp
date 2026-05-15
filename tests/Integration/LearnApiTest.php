<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

class LearnApiTest extends TestCase
{
    private static string $sharedJar = '';
    private static string $altJar = '';
    private static bool $usersCreated = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$sharedJar = tempnam(sys_get_temp_dir(), 'lc_shared_');
        self::$altJar = tempnam(sys_get_temp_dir(), 'lc_alt_');

        $register = function (string $jar, string $user) {
            $ch = curl_init(TEST_SERVER_URL . '/api/learn/register');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['username' => $user, 'password' => 'password123']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIEJAR      => $jar,
                CURLOPT_COOKIEFILE     => $jar,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            curl_exec($ch);
            curl_close($ch);
        };
        $register(self::$sharedJar, 'test_alice_' . getmypid());
        $register(self::$altJar, 'test_bob_' . getmypid());
        self::$usersCreated = true;
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$sharedJar);
        @unlink(self::$altJar);
        parent::tearDownAfterClass();
    }

    private function req(string $jar, string $method, string $path, ?array $jsonBody = null): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $body = substr($raw, $hSize);
        return ['status' => $status, 'body' => $body, 'json' => @json_decode($body, true)];
    }

    public function test_unauth_endpoints_return_401(): void
    {
        $emptyJar = tempnam(sys_get_temp_dir(), 'lc_empty_');
        $r = $this->req($emptyJar, 'POST', '/api/learn/notes', ['title' => 't', 'body' => 'b']);
        @unlink($emptyJar);
        $this->assertSame(401, $r['status']);
    }

    public function test_chat_status_shape(): void
    {
        $r = $this->req(self::$sharedJar, 'GET', '/api/learn/chat_status');
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('ai_enabled', $r['json']);
        $this->assertArrayHasKey('mock_mode', $r['json']);
    }

    public function test_all_lesson_pages_return_200(): void
    {
        $slugs = ['', '/create-app', '/first-page', '/components', '/react-vs-php', '/routing',
                   '/sessions', '/auth', '/htmx', '/notes', '/ai-chat', '/websocket', '/async', '/deployment'];
        foreach ($slugs as $s) {
            $r = $this->http('GET', '/learn' . $s);
            $this->assertSame(200, $r['status'], "/learn$s did not return 200");
        }
    }

    public function test_session_persists_across_requests(): void
    {
        $r1 = $this->req(self::$sharedJar, 'GET', '/api/learn/notes');
        $this->assertSame(200, $r1['status'], 'GET notes should be authenticated');
        $r2 = $this->req(self::$sharedJar, 'GET', '/api/learn/notes');
        $this->assertSame(200, $r2['status'], 'Second request should still be authenticated');
    }

    public function test_notes_crud_lifecycle(): void
    {
        $create = $this->req(self::$sharedJar, 'POST', '/api/learn/notes', ['title' => 'CRUD Test', 'body' => 'Hello']);
        $this->assertSame(200, $create['status']);
        $this->assertStringContainsString('CRUD Test', $create['body']);

        $list = $this->req(self::$sharedJar, 'GET', '/api/learn/notes');
        $this->assertSame(200, $list['status']);
        $this->assertStringContainsString('CRUD Test', $list['body']);

        preg_match('/data-id="(\d+)"/', $create['body'], $m);
        $noteId = $m[1] ?? '0';
        $this->assertNotSame('0', $noteId);

        $del = $this->req(self::$sharedJar, 'DELETE', "/api/learn/notes/$noteId");
        $this->assertSame(200, $del['status']);

        $listAfter = $this->req(self::$sharedJar, 'GET', '/api/learn/notes');
        $this->assertStringNotContainsString('CRUD Test', $listAfter['body']);
    }

    public function test_two_users_see_separate_notes(): void
    {
        $this->req(self::$sharedJar, 'POST', '/api/learn/notes', ['title' => 'alice-private', 'body' => '']);
        $this->req(self::$altJar,    'POST', '/api/learn/notes', ['title' => 'bob-private',   'body' => '']);

        $aliceList = $this->req(self::$sharedJar, 'GET', '/api/learn/notes');
        $bobList   = $this->req(self::$altJar,    'GET', '/api/learn/notes');

        $this->assertStringContainsString('alice-private', $aliceList['body']);
        $this->assertStringNotContainsString('bob-private', $aliceList['body']);
        $this->assertStringContainsString('bob-private', $bobList['body']);
        $this->assertStringNotContainsString('alice-private', $bobList['body']);
    }

    public function test_chat_sse_returns_events(): void
    {
        $ch = curl_init(self::$baseUrl . '/api/learn/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['message' => 'hi', 'thread_id' => 'sse_test_' . uniqid()]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_COOKIEFILE     => self::$sharedJar,
            CURLOPT_COOKIEJAR      => self::$sharedJar,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(200, $status, 'Chat should return 200');
        $this->assertStringContainsString('event: done', $body, 'SSE must contain done event');
        $this->assertTrue(
            str_contains($body, 'event: token') || str_contains($body, 'event: tool_call'),
            'SSE must contain at least one token or tool_call'
        );
    }

    public function test_chat_consecutive_requests_work(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $ch = curl_init(self::$baseUrl . '/api/learn/chat');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['message' => 'hi', 'thread_id' => 'consec_' . uniqid()]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_COOKIEFILE     => self::$sharedJar,
                CURLOPT_COOKIEJAR      => self::$sharedJar,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->assertSame(200, $status, "Chat request $i should return 200");
            $this->assertStringContainsString('event: done', $body, "Request $i must complete");
        }
    }
}
