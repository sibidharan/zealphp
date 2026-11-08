<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Reactive components over real HTTP (#509), against the /components demo.
 * Skips when the server has no ZEALPHP_APP_KEY (the demo is then disabled).
 */
class ComponentsTest extends TestCase
{
    private string $cookie = '';

    /** Load the page, keep its session cookie, return the snapshot of one component. */
    private function load(string $id): string
    {
        $r = $this->get('/components', $this->cookie !== '' ? ['Cookie' => $this->cookie] : []);
        $this->assertStatus(200, $r);
        if (str_contains($r['body'], 'Demo disabled.')) {
            $this->markTestSkipped('Server runs without ZEALPHP_APP_KEY; the reactive components demo is disabled');
        }
        if ($this->cookie === '' && preg_match('/PHPSESSID=([^;,\s]+)/', $r['headers']['set-cookie'] ?? '', $m) === 1) {
            $this->cookie = 'PHPSESSID=' . $m[1];
        }
        return self::snapshot($r['body'], $id);
    }

    private static function snapshot(string $html, string $id): string
    {
        $found = preg_match('/id="' . preg_quote($id, '/') . '".*?name="_zc" value="([^"]+)"/s', $html, $m);
        self::assertSame(1, $found, "snapshot for {$id}");
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
     */
    private function act(string $path, array $fields, array $headers = ['HX-Request' => 'true']): array
    {
        return $this->post($path, $headers + [
            'Cookie' => $this->cookie,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query($fields));
    }

    public function testActionReRendersTheComponent(): void
    {
        $wire = $this->load('like-counter-demo');
        $r = $this->act('/_zeal/c/like-counter/add', ['_zc' => $wire, '_zc_args' => '{"by":10}']);
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('<span class="lc-count" aria-live="polite">13</span>', $r['body']);
        $this->assertSame('no-store', $r['headers']['cache-control'] ?? null);

        $r2 = $this->act('/_zeal/c/like-counter/add', ['_zc' => self::snapshot($r['body'], 'like-counter-demo')]);
        $this->assertStringContainsString('>14</span>', $r2['body']);
    }

    public function testRefusals(): void
    {
        $wire = $this->load('like-counter-demo');
        $tampered = 'A' . substr($wire, 1);
        $this->assertStatus(400, $this->act('/_zeal/c/like-counter/add', ['_zc' => $tampered]));
        $this->assertStatus(404, $this->act('/_zeal/c/like-counter/render', ['_zc' => $wire]));
        $this->assertStatus(404, $this->act('/_zeal/c/nope/add', ['_zc' => $wire]));
        $this->assertStatus(403, $this->act('/_zeal/c/like-counter/add', ['_zc' => $wire], ['HX-Request' => 'true', 'Sec-Fetch-Site' => 'cross-site']));

        // Another session's snapshot: not run, the page is told to reload.
        $mine = $this->cookie;
        $this->cookie = 'PHPSESSID=' . str_repeat('a', 26);
        $other = $this->act('/_zeal/c/like-counter/add', ['_zc' => $wire]);
        $this->assertStatus(200, $other);
        $this->assertSame('true', $other['headers']['hx-refresh'] ?? null);
        $this->assertSame('', $other['body']);
        $this->cookie = $mine;
    }

    public function testLiveValidationAndHookErrors(): void
    {
        $wire = $this->load('signup-form-demo');
        $r = $this->act('/_zeal/c/signup-form/_update', ['_zc' => $wire, 'username' => 'admin'], [
            'HX-Request' => 'true',
            'HX-Trigger-Name' => 'username',
        ]);
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('That username is taken.', $r['body']);
        $this->assertMatchesRegularExpression('/id="signup-form-demo-username"[^>]*hx-preserve="true"/', $r['body']);
    }

    public function testSaveDispatchesAnEvent(): void
    {
        $wire = $this->load('signup-form-demo');
        $r = $this->act('/_zeal/c/signup-form/save', [
            '_zc' => $wire, 'username' => 'alice', 'email' => 'a@b.co', 'plan' => 'pro', 'terms' => '1',
        ]);
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('Signed up as <strong>alice</strong>', $r['body']);
        $this->assertSame('{"signup-saved":{"username":"alice"}}', $r['headers']['hx-trigger'] ?? null);
    }

    public function testNoJsPostRedirectsBackWithTheNewState(): void
    {
        $wire = $this->load('like-counter-demo');
        $base = rtrim(self::$baseUrl, '/');
        $r = $this->act('/_zeal/c/like-counter/add', ['_zc' => $wire, '_zc_args' => '{"by":100}'], [
            'Referer' => $base . '/components',
        ]);
        $this->assertStatus(303, $r);
        $this->assertSame('/components', $r['headers']['location'] ?? null);

        $page = $this->get('/components', ['Cookie' => $this->cookie]);
        $this->assertStringContainsString('>103</span>', $page['body']);
    }
}
