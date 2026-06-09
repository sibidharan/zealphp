<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_session_regenerate_id;
use function ZealPHP\Session\php_session_decode_to_array;

/**
 * Regression test for issue #19 — session ID regeneration must be aware of a
 * custom SessionHandlerInterface (Redis/Valkey/etc.).
 *
 * The old implementation only rename()'d the on-disk `sess_<id>` file, so with
 * a handler-backed store regeneration (a) didn't migrate the session data to
 * the new ID and (b) didn't emit a Set-Cookie for the new ID. OAuth callbacks
 * that call session_regenerate_id() post-login therefore stranded the auth
 * fields (`sub`/`tokens`/`profile`/`username`) under an ID the client never
 * received — they vanished on the next request.
 *
 * These tests pin: data migration (both superglobal and coroutine data
 * sources), old-ID destruction when requested, and the new-ID Set-Cookie
 * (gated on App::$session_lifecycle).
 */
class SessionRegenerateIdHandlerTest extends TestCase
{
    /** @var array{sub:string,tokens:array<string,string>,profile:array<string,string>,username:string} */
    private array $authData = [
        'sub'      => 'gitlab|4242',
        'tokens'   => ['access' => 'abc', 'refresh' => 'def'],
        'profile'  => ['name' => 'Sibidharan'],
        'username' => 'sibidharan',
    ];

    private string $oldId = 'oldsession0000000000000000000000';

    /**
     * In-memory SessionHandlerInterface that records writes/destroys, standing
     * in for phpredis/Valkey without a live server.
     */
    private function makeHandler(): object
    {
        return new class implements \SessionHandlerInterface {
            /** @var array<string, string> */
            public array $store = [];
            /** @var array<int, string> */
            public array $destroyed = [];
            public function open($path, $name): bool { return true; }
            public function close(): bool { return true; }
            public function read($id): string { return $this->store[(string) $id] ?? ''; }
            public function write($id, $data): bool { $this->store[(string) $id] = (string) $data; return true; }
            public function destroy($id): bool { $this->destroyed[] = (string) $id; unset($this->store[(string) $id]); return true; }
            public function gc($max): int|false { return 0; }
        };
    }

    private function makeResponse(): object
    {
        return new class {
            /** @var array<int, array<int, mixed>> */
            public array $cookies = [];
            public function cookie(mixed ...$args): void { $this->cookies[] = $args; }
            public function isWritable(): bool { return true; }
        };
    }

    /**
     * @param object $handler
     * @param object $response
     */
    private function primeContext(object $handler, object $response): RequestContext
    {
        App::$cwd = ZEALPHP_ROOT;
        ini_set('session.use_cookies', '1');

        $g = RequestContext::instance();
        $g->session_params = [
            'name'      => 'PHPSESSID',
            'save_path' => sys_get_temp_dir(),
            'handler'   => $handler,
            'cookie_params' => [
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
        $g->cookie = ['PHPSESSID' => $this->oldId];
        $g->openswoole_response = $response;

        // The old ID already has the auth data in the handler store.
        // @phpstan-ignore-next-line — fake handler exposes ->store
        $handler->store[$this->oldId] = serialize($this->authData);

        return $g;
    }

    protected function tearDown(): void
    {
        App::sessionLifecycle(true);
        $g = RequestContext::instance();
        $g->session_params = [];
        $g->cookie = [];
        $g->openswoole_response = null;
        unset($GLOBALS['_SESSION']);
        parent::tearDown();
    }

    public function testMigratesDataAndEmitsCookieInSuperglobalsMode(): void
    {
        App::superglobals(true);
        App::sessionLifecycle(true);
        $handler = $this->makeHandler();
        $response = $this->makeResponse();
        $g = $this->primeContext($handler, $response);

        // superglobals(true): the live data is in $_SESSION.
        $GLOBALS['_SESSION'] = $this->authData;

        $ok = zeal_session_regenerate_id(true);
        $this->assertTrue($ok);

        $newId = $g->cookie['PHPSESSID'];
        $this->assertNotSame($this->oldId, $newId, 'a fresh ID must be generated');

        // @phpstan-ignore-next-line — fake handler exposes ->store
        $this->assertArrayHasKey($newId, $handler->store, 'new ID must be written to the handler');
        // @phpstan-ignore-next-line — fake handler exposes ->store
        $migrated = php_session_decode_to_array($handler->store[$newId]);
        $this->assertSame($this->authData, $migrated, 'auth fields must survive regeneration');

        // delete_old_session = true → old ID destroyed in the handler.
        // @phpstan-ignore-next-line — fake handler exposes ->destroyed
        $this->assertContains($this->oldId, $handler->destroyed);

        // Set-Cookie for the new ID was emitted.
        // @phpstan-ignore-next-line — fake response exposes ->cookies
        $this->assertNotEmpty($response->cookies);
        // @phpstan-ignore-next-line — fake response exposes ->cookies
        [$name, $value] = $response->cookies[0];
        $this->assertSame('PHPSESSID', $name);
        $this->assertSame($newId, $value);
    }

    public function testMigratesDataInCoroutineMode(): void
    {
        App::superglobals(false);
        App::sessionLifecycle(true);
        $handler = $this->makeHandler();
        $response = $this->makeResponse();
        $g = $this->primeContext($handler, $response);

        // superglobals(false): the live data is in $g->session.
        $g->session = $this->authData;

        $ok = zeal_session_regenerate_id(false);
        $this->assertTrue($ok);

        $newId = $g->cookie['PHPSESSID'];
        $this->assertNotSame($this->oldId, $newId);

        // @phpstan-ignore-next-line — fake handler exposes ->store
        $migrated = php_session_decode_to_array($handler->store[$newId]);
        $this->assertSame($this->authData, $migrated);

        // delete_old_session = false → old ID NOT destroyed.
        // @phpstan-ignore-next-line — fake handler exposes ->destroyed
        $this->assertNotContains($this->oldId, $handler->destroyed);

        App::superglobals(true); // restore for other tests
    }

    public function testNoCookieEmittedWhenSessionLifecycleOff(): void
    {
        App::superglobals(true);
        App::sessionLifecycle(false);   // app/Symfony owns cookie emission
        $handler = $this->makeHandler();
        $response = $this->makeResponse();
        $g = $this->primeContext($handler, $response);
        $GLOBALS['_SESSION'] = $this->authData;

        zeal_session_regenerate_id(true);

        // Data still migrates (handler-aware), but no cookie is raced.
        $newId = $g->cookie['PHPSESSID'];
        // @phpstan-ignore-next-line — fake handler exposes ->store
        $this->assertArrayHasKey($newId, $handler->store);
        // @phpstan-ignore-next-line — fake response exposes ->cookies
        $this->assertEmpty($response->cookies, 'no Set-Cookie when sessionLifecycle is off');
    }

    /**
     * ext-zealphp#2 root cause — regeneration must keep
     * `$g->session_params['session_id']` in sync. `zeal_session_write_close()`
     * deliberately reads the sid from session_params (NOT zeal_session_id(),
     * which suffers auto-global caching in Mode 4), so a desync makes EVERY
     * post-regenerate session write land in the OLD (deleted) id's store:
     * login flows lose their session, and the strict-mode rotation cascades
     * (next request loads empty → rotates again → writes lost forever —
     * the 1→2→2→2 / rotate-every-request signature on the rig).
     */
    public function testRegenerateSyncsSessionParamsSessionId(): void
    {
        App::superglobals(true);
        App::sessionLifecycle(true);
        $handler = $this->makeHandler();
        $response = $this->makeResponse();
        $g = $this->primeContext($handler, $response);
        // write_close's canonical sid slot points at the OLD id, as
        // zeal_session_start() would have left it.
        $params = $g->session_params;
        $params['session_id'] = $this->oldId;
        $g->session_params = $params;
        $GLOBALS['_SESSION'] = $this->authData;

        $this->assertTrue(zeal_session_regenerate_id(true));

        $newId = $g->cookie['PHPSESSID'];
        $this->assertNotSame($this->oldId, $newId);
        $this->assertSame(
            $newId,
            $g->session_params['session_id'] ?? null,
            'write_close reads session_params[session_id] — regenerate must sync it or every later write lands in the deleted old id (ext-zealphp#2)'
        );
    }
}
