<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * End-to-end smoke tests for real-world PHP applications mounted under
 * ZealPHP's documentRoot. Each test probes a known hot path and asserts:
 *
 *   1. HTTP status — 200 / 30x / 4xx as expected for that path.
 *   2. Body markers — title tag, recognizable form, app-specific HTML
 *      class — proving the FULL framework boot reached the app and
 *      produced real content (not a stub or error page).
 *   3. Stability — 3 consecutive requests, all consistent (no
 *      first-request-only behavior).
 *
 * Lab requirement: scripts/app-lab/ setup must be running.
 *
 * Apps covered: the verified-rendering set from docs/compatibility-database.md.
 */
final class AppCompatibilityTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = getenv('ZEALPHP_LAB_BASE_URL') ?: 'http://172.30.24.4:9102';
        if (getenv('SKIP_LAB_TESTS') === '1') {
            $this->markTestSkipped('SKIP_LAB_TESTS=1 — set ZEALPHP_LAB_BASE_URL to a running lab to enable.');
        }
    }

    /**
     * @return list<array{status:int,body:string}>
     */
    private function probe(string $path, ?int $expectStatus = null): array
    {
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init($this->base . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HEADER         => true,
            ]);
            $resp   = \curl_exec($ch);
            $hsz    = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $results[] = [
                'status' => (int) $status,
                'body'   => is_string($resp) ? substr($resp, (int) $hsz) : '',
            ];
        }
        if ($expectStatus !== null) {
            foreach ($results as $r) {
                $this->assertSame($expectStatus, $r['status']);
            }
        }
        return $results;
    }

    public function testAdminerLoginPage(): void
    {
        foreach ($this->probe('/adminer/', 200) as $hit) {
            $this->assertStringContainsString('<title>Login - Adminer', $hit['body']);
            $this->assertGreaterThan(2000, strlen($hit['body']));
        }
    }

    public function testPhpMyAdminLoginPage(): void
    {
        foreach ($this->probe('/phpmyadmin/', 200) as $hit) {
            $this->assertStringContainsString('<title>phpMyAdmin', $hit['body']);
            $this->assertGreaterThan(10_000, strlen($hit['body']));
        }
    }

    public function testPrivateBinFullUI(): void
    {
        foreach ($this->probe('/privatebin/', 200) as $hit) {
            $this->assertStringContainsString('PrivateBin', $hit['body']);
            $this->assertGreaterThan(15_000, strlen($hit['body']));
        }
    }

    public function testJoomlaSetupWizard(): void
    {
        foreach ($this->probe('/joomla/', 200) as $hit) {
            $body = $hit['body'];
            $this->assertTrue(
                str_contains($body, 'Joomla')
                && (str_contains($body, 'Setup') || str_contains($body, 'Installation')),
                'Joomla landing did not contain expected markers'
            );
        }
    }

    public function testTraditionalDemoPage(): void
    {
        foreach ($this->probe('/traditional/', 200) as $hit) {
            $this->assertStringContainsString('Traditional PHP Test', $hit['body']);
        }
    }

    public function testLycheeRootPage(): void
    {
        foreach ($this->probe('/lychee/', 403) as $hit) {
            $this->assertStringContainsString('ROOT', $hit['body']);
        }
    }

    /**
     * 30x redirect apps — they're working but need install-wizard
     * walkthrough before we can assert rendered content. Only verify
     * the redirect itself here.
     *
     * @dataProvider installRedirectApps
     */
    public function testAppRedirectsToInstall(string $appPath): void
    {
        foreach ($this->probe($appPath) as $hit) {
            $this->assertContains($hit['status'], [200, 301, 302],
                "{$appPath} returned {$hit['status']}");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function installRedirectApps(): iterable
    {
        yield 'wordpress' => ['/wordpress/'];
        yield 'dokuwiki'  => ['/dokuwiki/'];
        yield 'freshrss'  => ['/freshrss/'];
    }
}
