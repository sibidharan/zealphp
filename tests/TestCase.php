<?php
namespace ZealPHP\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base test case for ZealPHP.
 *
 * Unit tests (Store, Counter, BuildParamMap) need no server.
 * Integration tests call http() which hits a running ZealPHP server.
 *
 * To run integration tests, start the server first:
 *   php app.php &
 * Then:
 *   ./vendor/bin/phpunit tests/Integration/
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected static string $baseUrl = TEST_SERVER_URL;

    /**
     * Make an HTTP request to the running test server.
     * Returns an array: ['status' => int, 'headers' => array, 'body' => string, 'json' => mixed]
     */
    protected function http(
        string $method,
        string $path,
        array  $headers = [],
        ?string $body   = null
    ): array {
        $url = self::$baseUrl . $path;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => array_map(
                fn($k, $v) => "$k: $v",
                array_keys($headers),
                $headers
            ),
        ]);

        // HEAD requests need CURLOPT_NOBODY to properly receive headers without body
        if (strtoupper($method) === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerRaw = substr($raw, 0, $hSize);
        $bodyRaw   = substr($raw, $hSize);

        // Parse headers
        $parsedHeaders = [];
        foreach (explode("\r\n", $headerRaw) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        $json = null;
        if (str_starts_with($parsedHeaders['content-type'] ?? '', 'application/json')) {
            $json = json_decode($bodyRaw, true);
        }

        return ['status' => $status, 'headers' => $parsedHeaders, 'body' => $bodyRaw, 'json' => $json];
    }

    protected function get(string $path, array $headers = []): array
    {
        return $this->http('GET', $path, $headers);
    }

    protected function post(string $path, array $headers = [], ?string $body = null): array
    {
        return $this->http('POST', $path, $headers, $body);
    }

    protected function assertStatus(int $expected, array $response, string $msg = ''): void
    {
        $this->assertSame($expected, $response['status'],
            $msg ?: "Expected HTTP $expected, got {$response['status']} for body: " . substr($response['body'], 0, 200));
    }

    protected function assertHeader(string $name, string $expected, array $response): void
    {
        $actual = $response['headers'][strtolower($name)] ?? '(missing)';
        $this->assertStringContainsString($expected, $actual,
            "Header '$name' expected to contain '$expected', got '$actual'");
    }

    protected function assertJsonResponse(array $response): array
    {
        $this->assertNotNull($response['json'], 'Response is not valid JSON: ' . substr($response['body'], 0, 200));
        return $response['json'];
    }
}
