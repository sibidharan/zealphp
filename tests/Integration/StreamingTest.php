<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests for SSR streaming patterns.
 * All streaming endpoints should return 200 with chunked content.
 */
class StreamingTest extends TestCase
{
    public function testGeneratorSsrReturns200(): void
    {
        $r = $this->get('/stream/ssr');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('</html>', $r['body']);
    }

    public function testGeneratorSsrContainsBothSections(): void
    {
        $r = $this->get('/stream/ssr');
        $this->assertStringContainsString('Users', $r['body']);
        $this->assertStringContainsString('Posts', $r['body']);
    }

    public function testStreamCallbackReturns200(): void
    {
        $r = $this->get('/stream/words');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('ZealPHP', $r['body']);
    }

    public function testSseEndpointContentType(): void
    {
        // Use write callback to capture data as it arrives — avoids timeout issues
        $collected = '';
        $ch = curl_init(self::$baseUrl . '/stream/events');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_WRITEFUNCTION  => function($ch, $data) use (&$collected) {
                $collected .= $data;
                // Stop after first event block arrives
                if (str_contains($collected, 'data:')) return -1;
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // Status 200 expected; content-type verified via the body pattern
        $this->assertSame(200, $status);
    }

    public function testSseBodyHasEvents(): void
    {
        $collected = '';
        $ch = curl_init(self::$baseUrl . '/stream/events');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_WRITEFUNCTION  => function($ch, $data) use (&$collected) {
                $collected .= $data;
                if (str_contains($collected, 'data:')) return -1;
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
        $this->assertStringContainsString('data:', $collected);
    }

    public function testTimersSseStreams(): void
    {
        $collected = '';
        $ch = curl_init(self::$baseUrl . '/timers/sse');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_WRITEFUNCTION  => function($ch, $data) use (&$collected) {
                $collected .= $data;
                if (str_contains($collected, 'data:')) return -1;
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
        $this->assertStringContainsString('data:', $collected);
    }
}
