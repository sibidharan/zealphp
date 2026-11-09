<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\FormBinder;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Registry;
use ZealPHP\Component\Snapshot;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;
use ZealPHP\Tests\Unit\Component\Fixtures\Guarded;
use ZealPHP\Tests\Unit\Component\Fixtures\Signup;
use ZealPHP\Tests\Unit\Component\Fixtures\Size;

/**
 * End-to-end component round trips through {@see Endpoint::handle()}:
 * render with App::component(), post back the snapshot, check the result.
 */
class EndpointTest extends TestCase
{
    private const HTMX = ['HTTP_HX_REQUEST' => 'true', 'HTTP_HOST' => 'app.test'];

    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'k', true)));
        Registry::reset();
        App::components([Counter::class, Signup::class, Guarded::class, \ZealPHP\Tests\Unit\Component\Fixtures\Nester::class]);
        App::componentBinder(new HtmxBinder());
        $this->session('sess-A');
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::componentBinder(new HtmxBinder());
        App::$cwd = $this->prevCwd;
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = [];
    }

    private function session(?string $id): void
    {
        $g = RequestContext::instance();
        $g->memo = [];
        if ($id === null) {
            unset($g->session);
            $g->cookie = [];
            return;
        }
        $g->session = [];
        $g->cookie = ['PHPSESSID' => $id];
    }

    private static function wire(string $html): string
    {
        self::assertSame(1, preg_match('/name="_zc" value="([^"]+)"/', $html, $m), 'snapshot input present');
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $server
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function post(string $name, string $action, string $html, array $input = [], array $server = self::HTMX): array
    {
        return Endpoint::handle($name, $action, ['_zc' => self::wire($html)] + $input, $server);
    }

    // ── rendering ──────────────────────────────────────────────────

    public function testComponentRendersWrapperSnapshotAndBody(): void
    {
        $html = (string) App::component(Counter::class, ['count' => 5], key: 'main');
        $this->assertStringStartsWith('<div id="counter-main" data-zc="counter">', $html);
        $this->assertStringContainsString('<span class="count">5</span>', $html);
        $this->assertStringContainsString('hx-post="/_zeal/c/counter/increment"', $html);
        $this->assertStringContainsString('hx-target="#counter-main"', $html);
        $this->assertStringContainsString('hx-include="#counter-main-zc"', $html, 'its own form: never a nested child\'s fields');
        $this->assertStringContainsString('<form method="post" action="/_zeal/c/counter/_refresh" id="counter-main-zc" hidden data-zc-form="counter-main"></form>', $html);
        $this->assertStringContainsString('<input type="hidden" name="_zc" value="', $html);
        $this->assertMatchesRegularExpression('/name="_zc" value="[^"]+" form="counter-main-zc"/', $html);
        $this->assertMatchesRegularExpression('/formaction="\/_zeal\/c\/counter\/increment"[^>]* form="counter-main-zc"/', $html, 'buttons join the form');
        $this->assertStringContainsString('formaction="/_zeal/c/counter/increment"', $html, 'no-JS fallback kept');
        $this->assertStringEndsWith('</div>', $html);
    }

    public function testComponentCanBeRenderedByName(): void
    {
        $this->assertStringContainsString('id="counter-x"', (string) App::component('counter', [], 'x'));
    }

    public function testAutoKeysAreSequentialPerRequest(): void
    {
        $this->assertStringContainsString('id="counter-1"', (string) App::component(Counter::class));
        $this->assertStringContainsString('id="counter-2"', (string) App::component(Counter::class));
    }

    public function testMountRunsOnFirstRenderOnly(): void
    {
        $html = (string) App::component(Counter::class, [], 'm');
        $r = $this->post('counter', 'increment', $html, ['_zc_args' => '{"by":1}']);
        $this->assertSame(200, $r['status']);
        $props = $this->props($r['body']);
        $this->assertSame(1, $props['mounted']);
        $this->assertSame(1, $props['count']);
    }

    public function testUnregisteredComponentThrows(): void
    {
        $this->expectException(\LogicException::class);
        App::component('nope');
    }

    public function testUnknownInitialPropThrows(): void
    {
        $this->expectException(\LogicException::class);
        App::component(Counter::class, ['bogus' => 1]);
    }

    public function testInvalidKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::component(Counter::class, [], 'bad key!');
    }

    // ── actions ────────────────────────────────────────────────────

    public function testActionRunsAndReRendersWithNewState(): void
    {
        $html = (string) App::component(Counter::class, ['count' => 5], key: 'main');
        $r = $this->post('counter', 'increment', $html, ['_zc_args' => '{"by":2}']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<span class="count">7</span>', $r['body']);
        $this->assertSame('no-store', $r['headers']['Cache-Control']);

        // The new snapshot carries the new state forward.
        $r2 = $this->post('counter', 'increment', $r['body'], ['_zc_args' => '{"by":2}']);
        $this->assertStringContainsString('<span class="count">9</span>', $r2['body']);
    }

    public function testActionDefaultsApplyWhenArgsOmitted(): void
    {
        $html = (string) App::component(Counter::class, [], 'd');
        $r = $this->post('counter', 'increment', $html);
        $this->assertSame(1, $this->props($r['body'])['count']);
    }

    public function testActionArgsAreCoercedFromStrings(): void
    {
        $html = (string) App::component(Counter::class, [], 'c');
        $r = $this->post('counter', 'setTo', $html, ['_zc_args' => '{"value":"42","size":"l"}']);
        $this->assertSame(200, $r['status']);
        $this->assertSame(42, $this->props($r['body'])['count']);
    }

    /** @return iterable<string, array{string}> */
    public static function badArgs(): iterable
    {
        yield 'not a number' => ['{"value":"abc"}'];
        yield 'missing required' => ['{}'];
        yield 'bad enum' => ['{"value":1,"size":"x"}'];
        yield 'object for int' => ['{"value":{"a":1}}'];
        yield 'list not object' => ['[1,2]'];
        yield 'not json' => ['nope'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badArgs')]
    public function testBadActionArgsAre400(string $args): void
    {
        $html = (string) App::component(Counter::class, [], 'b');
        $r = $this->post('counter', 'setTo', $html, ['_zc_args' => $args]);
        $this->assertSame(400, $r['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function notActions(): iterable
    {
        yield 'public non-action' => ['reset'];
        yield 'render' => ['render'];
        yield 'mount' => ['mount'];
        yield 'authorize' => ['authorize'];
        yield 'magic' => ['__construct'];
        yield 'internal' => ['zcCall'];
        yield 'missing' => ['nope'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notActions')]
    public function testOnlyActionMethodsAreCallable(string $method): void
    {
        $html = (string) App::component(Counter::class, ['count' => 3], 'n');
        $r = $this->post('counter', $method, $html);
        $this->assertSame(404, $r['status']);
    }

    public function testUnknownComponentIs404(): void
    {
        $r = Endpoint::handle('ghost', 'x', ['_zc' => 'a.b'], self::HTMX);
        $this->assertSame(404, $r['status']);
    }

    public function testAuthorizeFalseIs403AndActionDoesNotRun(): void
    {
        $html = (string) App::component(Guarded::class, [], 'g');
        $this->assertSame(403, $this->post('guarded', 'secret', $html)['status']);
        $r = $this->post('guarded', 'open', $html);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<p>open</p>', $r['body']);
    }

    // ── snapshot integrity ─────────────────────────────────────────

    public function testTamperedPropsAre400(): void
    {
        $html = (string) App::component(Counter::class, ['count' => 1], 't');
        [$payload, $mac] = explode('.', self::wire($html));
        $json = (string) base64_decode(strtr($payload, '-_', '+/'));
        $forged = str_replace('"count":1', '"count":999', $json);
        $this->assertNotSame($json, $forged);
        $forgedWire = rtrim(strtr(base64_encode($forged), '+/', '-_'), '=') . '.' . $mac;
        $r = Endpoint::handle('counter', 'increment', ['_zc' => $forgedWire], self::HTMX);
        $this->assertSame(400, $r['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedWires(): iterable
    {
        yield 'empty' => [''];
        yield 'no dot' => ['abc'];
        yield 'three parts' => ['a.b.c'];
        yield 'bad chars' => ['a$.b'];
        yield 'oversized' => [str_repeat('a', 70000) . '.b'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedWires')]
    public function testMalformedSnapshotsAre400(string $wire): void
    {
        $this->assertSame(400, Endpoint::handle('counter', 'increment', ['_zc' => $wire], self::HTMX)['status']);
    }

    public function testSnapshotWithSurroundingWhitespaceIs400(): void
    {
        // Strict base64_decode() skips whitespace, so the wire format is checked first.
        $wire = self::wire((string) App::component(Counter::class, [], 'ws'));
        [$payload, $mac] = explode('.', $wire);
        foreach (["{$payload}\n.{$mac}", " {$payload}.{$mac}", "{$payload}.{$mac}\n"] as $padded) {
            $this->assertSame(400, Endpoint::handle('counter', 'increment', ['_zc' => $padded], self::HTMX)['status'], json_encode($padded) ?: '');
        }
        $this->assertSame(200, Endpoint::handle('counter', 'increment', ['_zc' => $wire], self::HTMX)['status']);
    }

    public function testSnapshotSignedWithAnotherKeyIs400(): void
    {
        $html = (string) App::component(Counter::class, [], 'k');
        AppKey::set('base64:' . base64_encode(hash('sha256', 'z', true)));
        $this->assertSame(400, $this->post('counter', 'increment', $html)['status']);
    }

    public function testPreviousKeyStillVerifiesAfterRotation(): void
    {
        $old = 'base64:' . base64_encode(hash('sha256', 'k', true));
        $html = (string) App::component(Counter::class, [], 'r');
        AppKey::set('base64:' . base64_encode(hash('sha256', 'n', true)), $old);
        $this->assertSame(200, $this->post('counter', 'increment', $html)['status']);
    }

    public function testSnapshotFromAnotherComponentIs400(): void
    {
        $html = (string) App::component(Guarded::class, [], 'x');
        $this->assertSame(400, $this->post('counter', 'increment', $html)['status']);
    }

    public function testSnapshotFromAnotherSessionIsRefusedWithAReload(): void
    {
        $html = (string) App::component(Counter::class, ['count' => 1], 's');
        $this->session('sess-B');
        $r = $this->post('counter', 'increment', $html);
        // Not run, not rendered: the page reloads and shows sess-B its own state.
        $this->assertSame('true', $r['headers']['HX-Refresh']);
        $this->assertSame('', $r['body']);
    }

    public function testRegeneratedSessionIdIsTheBinding(): void
    {
        $g = RequestContext::instance();
        $g->session_params = ['session_id' => 'new-id'];
        try {
            $html = (string) App::component(Counter::class, [], 'regen');
            $this->assertSame(200, $this->post('counter', 'increment', $html)['status'], 'binds to session_params, not the stale cookie');
            $g->session_params = [];
            $this->assertArrayHasKey('HX-Refresh', $this->post('counter', 'increment', $html)['headers']);
        } finally {
            $g->session_params = [];
        }
    }

    public function testSessionlessSnapshotIsRefusedOnceASessionExists(): void
    {
        $this->session(null);
        $html = (string) App::component(Counter::class, [], 'ns');
        $this->session('sess-A');
        $this->assertArrayHasKey('HX-Refresh', $this->post('counter', 'increment', $html)['headers']);
    }

    public function testSessionlessRequestsNeedAnOriginHeader(): void
    {
        $this->session(null);
        $html = (string) App::component(Counter::class, [], 'nos');
        $this->assertSame(403, $this->post('counter', 'increment', $html)['status']);
        $this->assertSame(200, $this->post('counter', 'increment', $html, [], self::HTMX + ['HTTP_SEC_FETCH_SITE' => 'same-origin'])['status']);
    }

    public function testExpiredSnapshotsAskForAReload(): void
    {
        $wire = (new Snapshot('counter', 'counter-old', ['count' => 1, 'mounted' => 0], (string) Registry::byName('counter')?->version, Snapshot::currentBinding(), [], [], time() - 90000))->encode();
        $this->assertSame('true', Endpoint::handle('counter', 'increment', ['_zc' => $wire], self::HTMX)['headers']['HX-Refresh'] ?? null);
        App::componentMaxAge(0);
        try {
            $this->assertSame(200, Endpoint::handle('counter', 'increment', ['_zc' => $wire], self::HTMX)['status']);
        } finally {
            App::componentMaxAge(86400);
        }
    }

    public function testEphemeralKeyRestartAsksForAReload(): void
    {
        AppKey::useEphemeral();
        $html = (string) App::component(Counter::class, [], 'eph');
        AppKey::useEphemeral(); // a dev restart
        $this->assertSame('true', $this->post('counter', 'increment', $html)['headers']['HX-Refresh'] ?? null);
    }

    public function testSnapshotFromAnotherVersionAsksHtmxToRefresh(): void
    {
        $meta = Registry::byName('counter');
        $this->assertNotNull($meta);
        $wire = (new Snapshot('counter', 'counter-v', ['count' => 1, 'mounted' => 0], 'old-build', Snapshot::currentBinding()))->encode();
        $r = Endpoint::handle('counter', 'increment', ['_zc' => $wire], self::HTMX);
        $this->assertSame(200, $r['status']);
        $this->assertSame('true', $r['headers']['HX-Refresh']);

        App::componentBinder(new FormBinder());
        $r = Endpoint::handle('counter', 'increment', ['_zc' => $wire], ['HTTP_ZEAL_REQUEST' => '1']);
        $this->assertSame(409, $r['status']);
        $this->assertSame('1', $r['headers']['Zeal-Reload']);
    }

    public function testCrossOriginRequestsAre403(): void
    {
        $html = (string) App::component(Counter::class, [], 'o');
        $this->assertSame(403, $this->post('counter', 'increment', $html, [], self::HTMX + ['HTTP_SEC_FETCH_SITE' => 'cross-site'])['status']);
        $this->assertSame(403, $this->post('counter', 'increment', $html, [], self::HTMX + ['HTTP_ORIGIN' => 'https://evil.test'])['status']);
        $this->assertSame(200, $this->post('counter', 'increment', $html, [], self::HTMX + ['HTTP_SEC_FETCH_SITE' => 'same-origin'])['status']);
        $this->assertSame(200, $this->post('counter', 'increment', $html, [], self::HTMX + ['HTTP_ORIGIN' => 'http://app.test'])['status']);
    }

    // ── input binding ──────────────────────────────────────────────

    public function testModelInputIsAppliedBeforeTheAction(): void
    {
        $html = (string) App::component(Signup::class, [], 'f');
        $r = $this->post('signup', 'save', $html, ['email' => 'a@b.co', 'username' => 'alice']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<b class="saved">yes</b>', $r['body']);
        $this->assertSame('{"signed-up":{"email":"a@b.co"}}', $r['headers']['HX-Trigger']);
    }

    public function testNonModelPropsCannotBeWrittenByInput(): void
    {
        $html = (string) App::component(Signup::class, [], 'w');
        $r = $this->post('signup', '_refresh', $html, ['saved' => '1', 'usernameChanges' => '99']);
        $props = $this->props($r['body']);
        $this->assertFalse($props['saved']);
        $this->assertSame(0, $props['usernameChanges']);
    }

    public function testInputForFieldsNotRenderedIsIgnored(): void
    {
        $html = (string) App::component(Signup::class, ['hideAge' => true], 'h');
        $r = $this->post('signup', '_refresh', $html, ['age' => '30']);
        $this->assertSame(0, $this->props($r['body'])['age']);
    }

    public function testCheckboxesAndSelectsBind(): void
    {
        $html = (string) App::component(Signup::class, [], 'cb');
        $r = $this->post('signup', '_refresh', $html, ['agree' => '1', 'tags' => ['b'], 'size' => 's', 'age' => '30']);
        $p = $this->props($r['body']);
        $this->assertTrue($p['agree']);
        $this->assertSame(['b'], $p['tags']);
        $this->assertSame('s', $p['size']);
        $this->assertSame(30, $p['age']);
        $this->assertMatchesRegularExpression('/name="agree"[^>]*checked/', $r['body']);
        $this->assertMatchesRegularExpression('/value="b" checked/', $r['body']);
        $this->assertStringNotContainsString('id="signup-cb-tags"', $r['body'], 'checkbox groups get no duplicate ids');
        $this->assertStringContainsString('value="s" selected', $r['body']);

        // Unchecked checkboxes send nothing: that means false / empty.
        $r2 = $this->post('signup', '_refresh', $r['body'], ['size' => 's', 'age' => '30']);
        $p2 = $this->props($r2['body']);
        $this->assertFalse($p2['agree']);
        $this->assertSame([], $p2['tags']);
    }

    public function testUnconvertibleInputIsAFieldErrorAndSkipsTheAction(): void
    {
        $html = (string) App::component(Signup::class, [], 'bad');
        $r = $this->post('signup', 'save', $html, ['email' => 'a@b.co', 'username' => 'alice', 'age' => 'old']);
        $this->assertSame(200, $r['status'], 'htmx gets 200 so it swaps the errors in');
        $this->assertStringContainsString('<b class="saved">no</b>', $r['body']);
        $this->assertStringContainsString('Age must be a whole number.', $r['body']);
        $this->assertStringContainsString('id="signup-bad-age" form="signup-bad" value="old"', $r['body'], 'the typed text is shown back');
        $this->assertSame(0, $this->props($r['body'])['age'], 'state keeps the last valid value');
    }

    public function testValidationErrorsRenderAndBlockTheAction(): void
    {
        $html = (string) App::component(Signup::class, [], 'v');
        $r = $this->post('signup', 'save', $html, ['email' => 'nope', 'username' => 'al']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<b class="saved">no</b>', $r['body']);
        $this->assertStringContainsString('Email must be a valid email address.', $r['body']);
        $this->assertStringContainsString('Username must be at least 3 characters.', $r['body']);
        $this->assertStringContainsString('aria-invalid="true"', $r['body']);
        $this->assertStringContainsString('aria-describedby="signup-v-email-error"', $r['body']);
        $this->assertArrayNotHasKey('HX-Trigger', $r['headers']);

        App::componentBinder(new FormBinder());
        $html = (string) App::component(Signup::class, [], 'v2');
        $r = $this->post('signup', 'save', $html, ['email' => 'nope'], ['HTTP_ZEAL_REQUEST' => '1']);
        $this->assertSame(422, $r['status'], 'the generic protocol uses 422');
    }

    public function testLiveUpdateValidatesOnlyChangedFieldsAndPreservesTheTrigger(): void
    {
        $html = (string) App::component(Signup::class, [], 'live');
        $this->assertStringContainsString('hx-trigger="input changed delay:300ms"', $html);
        $this->assertStringContainsString('hx-post="/_zeal/c/signup/_update"', $html);
        $this->assertStringContainsString('hx-trigger="change"', $html, 'blur model');

        $r = $this->post('signup', '_update', $html, ['username' => 'al'], self::HTMX + ['HTTP_HX_TRIGGER_NAME' => 'username']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Username must be at least 3 characters.', $r['body']);
        $this->assertStringNotContainsString('Email', $r['body'], 'untouched fields are not validated yet');
        $this->assertMatchesRegularExpression('/id="signup-live-username"[^>]*hx-preserve="true"/', $r['body']);
        $this->assertDoesNotMatchRegularExpression('/id="signup-live-email"[^>]*hx-preserve/', $r['body']);
        $this->assertSame(1, $this->props($r['body'])['usernameChanges'], 'updated hook ran');

        // Errors persist in the snapshot until the field is fixed.
        $r2 = $this->post('signup', '_update', $r['body'], ['username' => 'al', 'email' => 'x@y.co'], self::HTMX + ['HTTP_HX_TRIGGER_NAME' => 'email']);
        $this->assertStringContainsString('Username must be at least 3 characters.', $r2['body']);
        $r3 = $this->post('signup', '_update', $r2['body'], ['username' => 'alice', 'email' => 'x@y.co'], self::HTMX + ['HTTP_HX_TRIGGER_NAME' => 'username']);
        $this->assertStringNotContainsString('Username must be', $r3['body']);
        $this->assertSame(2, $this->props($r3['body'])['usernameChanges']);
    }

    public function testErrorsAddedByUpdatedHooksSurviveLiveValidation(): void
    {
        $html = (string) App::component(Signup::class, [], 'hook');
        // 'taken' passes the rules (required|min:3); the hook rejects it.
        $r = $this->post('signup', '_update', $html, ['username' => 'taken'], self::HTMX + ['HTTP_HX_TRIGGER_NAME' => 'username']);
        $this->assertStringContainsString('That username is taken.', $r['body']);
    }

    public function testNullableBoolCheckboxCanBeUnchecked(): void
    {
        $html = (string) App::component(Signup::class, ['newsletter' => true], 'nb');
        $r = $this->post('signup', '_refresh', $html, ['size' => 's']);
        $this->assertFalse($this->props($r['body'])['newsletter']);
    }

    public function testValidationInAnUpdatedHookIsAFieldErrorNotA500(): void
    {
        $html = (string) App::component(Signup::class, [], 'hv');
        $r = $this->post('signup', '_update', $html, ['username' => 'hook-validate', 'email' => 'bad'], self::HTMX + ['HTTP_HX_TRIGGER_NAME' => 'username']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Email must be a valid email address.', $r['body']);

        // Same from an action request: the hook runs before the action, which is skipped.
        $r2 = $this->post('signup', 'save', $html, ['username' => 'hook-validate', 'email' => 'bad']);
        $this->assertSame(200, $r2['status']);
        $this->assertStringContainsString('<b class="saved">no</b>', $r2['body']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function hostileInput(): iterable
    {
        yield 'invalid utf-8' => [['bio' => "\xFF\xFE"], 'Bio must be valid UTF-8 text.'];
        yield 'infinite float' => [['score' => '1e999'], 'Score must be a number.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileInput')]
    public function testHostileInputIsAFieldErrorNotA500(array $input, string $message): void
    {
        $html = (string) App::component(Signup::class, [], 'hostile');
        $r = $this->post('signup', '_refresh', $html, $input + ['size' => 's']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString($message, $r['body']);
    }

    public function testInfiniteActionArgumentIs400(): void
    {
        $html = (string) App::component(Counter::class, [], 'inf');
        $this->assertSame(400, $this->post('counter', 'setTo', $html, ['_zc_args' => '{"value":1e999}'])['status']);
    }

    public function testOversizedStateIs400NotAnUnusableSnapshot(): void
    {
        $html = (string) App::component(Signup::class, [], 'big');
        $r = $this->post('signup', '_refresh', $html, ['bio' => str_repeat('x', 70000), 'size' => 's']);
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('too large', $r['body']);
    }

    public function testStaleErrorsOnUntouchedFieldsDoNotMakeAnActionInvalid(): void
    {
        App::componentBinder(new FormBinder());
        $html = (string) App::component(Signup::class, [], 'old-err');
        $server = ['HTTP_ZEAL_REQUEST' => '1'];
        $r = $this->post('signup', 'save', $html, ['email' => 'bad', 'username' => 'alice', 'size' => 's'], $server);
        $this->assertSame(422, $r['status']);
        // A different action with the old email error still in the snapshot: not "invalid".
        $r2 = $this->post('signup', '_refresh', $r['body'], ['email' => 'bad', 'username' => 'alice', 'size' => 's'], $server);
        $this->assertSame(200, $r2['status']);
    }

    public function testDefaultSubmitButtonComesFirstSoEnterRunsSubmit(): void
    {
        $html = (string) App::component(Signup::class, [], 'enter');
        $first = strpos($html, '<button');
        $this->assertNotFalse($first);
        $this->assertStringStartsWith('<button type="submit" hidden tabindex="-1" aria-hidden="true" formaction="/_zeal/c/signup/save"', substr($html, $first));
        $this->assertGreaterThan($first, (int) strpos($html, '/_zeal/c/signup/leave'), 'an earlier action button is not the default');
    }

    public function testActionsReplaceInFlightRequestsAndDisableTheButton(): void
    {
        $html = (string) App::component(Counter::class, [], 'sync');
        $this->assertStringContainsString('hx-sync="#counter-sync:replace" hx-disabled-elt="this"', $html);
        $signup = (string) App::component(Signup::class, [], 'sync');
        $this->assertStringContainsString('hx-post="/_zeal/c/signup/save" hx-target="#signup-sync" hx-swap="outerHTML" hx-sync="#signup-sync:replace"', $signup);
        $this->assertStringContainsString('hx-sync="#signup-sync:queue last"', $signup);
    }

    public function testHtmx4DialectAndMorph(): void
    {
        App::componentBinder(new HtmxBinder(htmx: 4));
        $html = (string) App::component(Counter::class, [], 'v4');
        $this->assertStringContainsString('hx-disable="this"', $html);
        $this->assertStringNotContainsString('hx-disabled-elt', $html);
        $this->assertStringContainsString('hx-swap="outerMorph"', $html, 'htmx 4 always morphs');

        $live = (string) App::component(Signup::class, [], 'v4live');
        $r = $this->post('signup', '_update', $live, ['username' => 'al', '_zc_trigger' => 'username']);
        $this->assertStringNotContainsString('hx-preserve', $r['body'], 'morphing keeps the focused field in htmx 4');

        App::componentBinder(new HtmxBinder(morph: true, htmx: 4));
        $signup = (string) App::component(Signup::class, [], 'v4m');
        $this->assertStringContainsString('hx-swap="outerMorph"', $signup);
        $this->assertStringNotContainsString('hx-ext', $signup, 'htmx 4 morphs without an extension');

        App::componentBinder(new HtmxBinder(morph: true));
        $signup2 = (string) App::component(Signup::class, [], 'v2m');
        $this->assertStringContainsString('hx-swap="morph:outerHTML"', $signup2);
        $this->assertStringContainsString('hx-ext="morph"', $signup2);
        $this->assertStringContainsString('hx-disabled-elt="this"', $signup2);
    }

    public function testUnsupportedHtmxVersionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HtmxBinder(htmx: 3);
    }

    public function testRefusalsTellHtmxNotToSwap(): void
    {
        $r = Endpoint::handle('counter', 'nope', ['_zc' => 'x.y'], self::HTMX);
        $this->assertSame(404, $r['status']);
        $this->assertSame('none', $r['headers']['HX-Reswap']);
        App::componentBinder(new FormBinder());
        $this->assertArrayNotHasKey('HX-Reswap', Endpoint::handle('counter', 'nope', ['_zc' => 'x.y'], ['HTTP_ZEAL_REQUEST' => '1'])['headers']);
    }

    public function testLiveInputsNameTheirFieldInTheBody(): void
    {
        $html = (string) App::component(Signup::class, [], 'body');
        $this->assertMatchesRegularExpression('/id="signup-body-username"[^>]*hx-vals="\{&quot;_zc_trigger&quot;:&quot;username&quot;\}"/', $html);
        // No HX-Trigger-Name header (htmx 4): the body field is enough.
        $r = $this->post('signup', '_update', $html, ['username' => 'al', 'email' => 'x@y.co', '_zc_trigger' => 'username']);
        $this->assertMatchesRegularExpression('/id="signup-body-username"[^>]*hx-preserve="true"/', $r['body']);
        $this->assertDoesNotMatchRegularExpression('/id="signup-body-email"[^>]*hx-preserve/', $r['body']);
    }

    public function testNestedChildrenNeedAKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a key');
        App::component(\ZealPHP\Tests\Unit\Component\Fixtures\Nester::class);
    }

    public function testRawInputShownBackIsEscaped(): void
    {
        $html = (string) App::component(Signup::class, [], 'raw');
        $r = $this->post('signup', '_refresh', $html, ['age' => '"><script>x</script>', 'size' => 's']);
        $this->assertStringNotContainsString('<script>x', $r['body']);
        $this->assertStringContainsString('id="signup-raw-age" form="signup-raw" value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $r['body']);
    }

    public function testErrorMarkup(): void
    {
        $html = (string) App::component(Signup::class, [], 'em');
        $r = $this->post('signup', 'save', $html, ['email' => 'nope', 'username' => 'alice', 'size' => 's']);
        $this->assertStringContainsString('<span class="zc-error" id="signup-em-email-error" role="alert">Email must be a valid email address.</span>', $r['body']);
    }

    public function testModelWithoutValueAndArrayNames(): void
    {
        $html = (string) App::component(Signup::class, ['size' => Size::Small, 'bio' => 'hi'], 'nv');
        $this->assertStringContainsString('<select name="size" id="signup-nv-size" form="signup-nv">', $html, 'model(..., false) prints no value');
        $this->assertStringContainsString('<textarea name="bio" id="signup-nv-bio" form="signup-nv">hi</textarea>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="tags[]" form="signup-nv" value="a"', $html);
    }

    public function testValuesAreEscapedInTheMarkup(): void
    {
        $html = (string) App::component(Signup::class, [], 'esc');
        $r = $this->post('signup', '_refresh', $html, ['email' => '"><script>x</script>']);
        $this->assertStringNotContainsString('<script>x', $r['body']);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $r['body']);
    }

    // ── redirects and the no-JS path ───────────────────────────────

    public function testRedirectUsesHxRedirect(): void
    {
        $html = (string) App::component(Signup::class, [], 'rd');
        $r = $this->post('signup', 'leave', $html);
        $this->assertSame('/bye', $r['headers']['HX-Redirect']);
    }

    public function testNoJsPostRedirectsBackAndTheNextRenderShowsTheResult(): void
    {
        App::componentBinder(new FormBinder());
        $html = (string) App::component(Signup::class, [], 'nojs');
        $this->assertStringStartsWith('<form method="post" action="/_zeal/c/signup/save" id="signup-nojs"', $html);

        $server = ['HTTP_HOST' => 'app.test', 'HTTP_REFERER' => 'http://app.test/join?step=1'];
        $r = $this->post('signup', 'save', $html, ['email' => 'nope', 'username' => 'alice'], $server);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/join?step=1', $r['headers']['Location']);

        // The page re-renders the component with the flashed state, once.
        RequestContext::instance()->memo = [];
        $again = (string) App::component(Signup::class, [], 'nojs');
        $this->assertStringContainsString('Email must be a valid email address.', $again);
        $this->assertStringContainsString('value="alice"', $again);
        $fresh = (string) App::component(Signup::class, [], 'nojs');
        $this->assertStringNotContainsString('value="alice"', $fresh, 'flash is consumed');
    }

    public function testNoJsRedirectNeverLeavesTheSite(): void
    {
        App::componentBinder(new FormBinder());
        $html = (string) App::component(Counter::class, [], 'ref');
        foreach (['http://evil.test/x', '//evil.test', 'javascript:alert(1)', ''] as $ref) {
            $r = $this->post('counter', 'increment', $html, [], ['HTTP_HOST' => 'app.test', 'HTTP_REFERER' => $ref]);
            $this->assertSame('/', $r['headers']['Location'], "Referer {$ref}");
        }
    }

    // ── isolation ──────────────────────────────────────────────────

    public function testInterleavedSessionsNeverSeeEachOthersState(): void
    {
        $pages = [];
        foreach (['u1', 'u2', 'u3'] as $i => $sid) {
            $this->session($sid);
            $pages[$sid] = (string) App::component(Counter::class, ['count' => ($i + 1) * 100], 'shared-key');
        }
        for ($round = 0; $round < 3; $round++) {
            foreach (['u3', 'u1', 'u2'] as $sid) {
                $this->session($sid);
                $r = $this->post('counter', 'increment', $pages[$sid], ['_zc_args' => '{"by":1}']);
                $this->assertSame(200, $r['status']);
                $pages[$sid] = $r['body'];
            }
        }
        $this->session('u1');
        $this->assertSame(103, $this->props($pages['u1'])['count']);
        $this->assertSame(203, $this->props($pages['u2'])['count']);
        $this->assertSame(303, $this->props($pages['u3'])['count']);
    }

    /** @return array<string, mixed> */
    private function props(string $html): array
    {
        [$payload] = explode('.', self::wire($html));
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertIsArray($data);
        $this->assertIsArray($data['p']);
        return $data['p'];
    }
}
