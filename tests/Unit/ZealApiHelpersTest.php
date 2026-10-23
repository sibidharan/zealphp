<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * Unit coverage for ZealAPI's small helper surface that doesn't require a
 * live request socket: paramsExists(), die() (the HTTP-status-from-message
 * mapping), __call() (the undefined-method "did you mean" diagnostics), and
 * requirePostAuth()'s method-guard branch (the auth branch is covered by
 * ZealApiAuthHooksTest — this pins the non-POST short-circuit).
 */
class ZealApiHelpersTest extends TestCase
{
    private ZealAPI $api;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $g->get = [];
        $g->post = [];
        $g->zealphp_request = new \stdClass();
        $g->zealphp_response = new class {
            /** @var array<string, mixed> */
            public array $headers = [];
            public int $status = 200;

            public function header(string $name, mixed $value, bool $ucwords = true): void
            {
                $this->headers[$name] = $value;
            }

            public function status(int $status): void
            {
                $this->status = $status;
            }
        };

        $this->api = new ZealAPI(null, null, sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        $g = G::instance();
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        $g->server = [];
        $g->get = [];
        $g->post = [];

        App::authChecker(null);
        App::adminChecker(null);
        App::usernameProvider(null);
        parent::tearDown();
    }

    /** Capture whatever the helper echoes through response()/json(). */
    private function captureStatus(callable $fn): array
    {
        $g = G::instance();
        ob_start();
        $fn();
        $echo = (string) ob_get_clean();
        return ['echo' => $echo, 'status' => $g->zealphp_response->status];
    }

    // ── paramsExists() ───────────────────────────────────────────────

    public function testParamsExistsTrueWhenAllPresent(): void
    {
        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $g->get = ['a' => '1', 'b' => '2'];
        $api = new ZealAPI(null, null, sys_get_temp_dir());

        $this->assertTrue($api->paramsExists(['a', 'b']));
    }

    public function testParamsExistsFalseWhenMissing(): void
    {
        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $g->get = ['a' => '1'];
        $api = new ZealAPI(null, null, sys_get_temp_dir());

        $this->assertFalse($api->paramsExists(['a', 'missing']));
    }

    public function testParamsExistsTrueForEmptyList(): void
    {
        $this->assertTrue($this->api->paramsExists([]));
    }

    // ── die() — status mapping from exception message ────────────────

    public function testDieMapsGenericMessageTo400(): void
    {
        $r = $this->captureStatus(fn() => $this->api->die(new \RuntimeException('boom')));
        $this->assertSame(400, $r['status']);
        $body = json_decode($r['echo'], true);
        $this->assertSame('boom', $body['error']);
        $this->assertSame('exception', $body['type']);
    }

    public function testDieHidesDriverAndEngineDetailsWithoutDisplayErrors(): void
    {
        $prev = \ZealPHP\App::displayErrors();
        \ZealPHP\App::displayErrors(false);
        try {
            foreach ([new \PDOException('Access denied for user app@10.0.0.5'), new \TypeError('f(): Argument #1 in /srv/app/x.php')] as $e) {
                $r = $this->captureStatus(fn() => $this->api->die($e));
                $body = json_decode($r['echo'], true);
                $this->assertSame('Internal error', $body['error'], get_class($e));
                $this->assertArrayNotHasKey('stack', $body);
            }
            $r = $this->captureStatus(fn() => $this->api->die(new \RuntimeException('boom')));
            $this->assertArrayNotHasKey('stack', json_decode($r['echo'], true));
        } finally {
            \ZealPHP\App::displayErrors($prev);
        }
    }

    public function testDieShowsOnlyAppThrownMessagesWithoutDisplayErrors(): void
    {
        $prev = \ZealPHP\App::displayErrors();
        \ZealPHP\App::displayErrors(false);
        try {
            $hidden = [
                new \JsonException('Syntax error near db.internal:5432'),
                new \PHPUnit\Framework\Exception('vendor library message with http://10.0.0.5/api'),
            ];
            foreach ($hidden as $e) {
                $body = json_decode($this->captureStatus(fn() => $this->api->die($e))['echo'], true);
                $this->assertSame('Internal error', $body['error'], get_class($e));
            }
            $shown = [
                new \InvalidArgumentException('email is required'),
                new \DomainException('plan not available'),
                new ZealApiHelpersTestAppException('quota exceeded'),
            ];
            foreach ($shown as $e) {
                $body = json_decode($this->captureStatus(fn() => $this->api->die($e))['echo'], true);
                $this->assertSame($e->getMessage(), $body['error'], get_class($e));
            }
        } finally {
            \ZealPHP\App::displayErrors($prev);
        }
    }

    public function testDieMapsUnauthorizedTo403(): void
    {
        $r = $this->captureStatus(fn() => $this->api->die(new \RuntimeException('Unauthorized')));
        $this->assertSame(403, $r['status']);
    }

    public function testDieMapsExpiredTokenTo403(): void
    {
        $r = $this->captureStatus(fn() => $this->api->die(new \RuntimeException('Expired token')));
        $this->assertSame(403, $r['status']);
    }

    public function testDieMapsNotFoundTo404(): void
    {
        $r = $this->captureStatus(fn() => $this->api->die(new \RuntimeException('Not found')));
        $this->assertSame(404, $r['status']);
    }

    // ── __call() — undefined-method diagnostics ──────────────────────

    public function testCallSuggestsCloseMatch(): void
    {
        try {
            // paramExist is one edit from paramsExists → did_you_mean present
            $this->api->paramExist();
            $this->fail('expected BadMethodCallException');
        } catch (\BadMethodCallException $e) {
            $this->assertStringContainsString('paramExist', $e->getMessage());
        }
    }

    public function testCallHasNoSuggestionForUnrelatedName(): void
    {
        try {
            $this->api->totallyUnrelatedNonsenseMethodName();
            $this->fail('expected BadMethodCallException');
        } catch (\BadMethodCallException $e) {
            $this->assertStringContainsString('totallyUnrelatedNonsenseMethodName', $e->getMessage());
        }
    }

    // ── requirePostAuth() — non-POST short-circuit ───────────────────

    public function testRequirePostAuthFailsForNonPostMethod(): void
    {
        // GET request + authed checker → still false because method != POST.
        App::authChecker(fn(): bool => true);
        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $api = new ZealAPI(null, null, sys_get_temp_dir());

        $r = $this->captureStatus(function () use ($api) {
            $this->assertFalse($api->requirePostAuth());
        });
        $this->assertSame(403, $r['status']);
        $this->assertSame(['error' => 'Unauthorized'], json_decode($r['echo'], true));
    }

    public function testRequirePostAuthSucceedsForAuthedPost(): void
    {
        App::authChecker(fn(): bool => true);
        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'POST'];
        $api = new ZealAPI(null, null, sys_get_temp_dir());

        $this->assertTrue($api->requirePostAuth());
    }
}

/** An exception class the APP declares (outside vendor/): its message is meant for the client. */
final class ZealApiHelpersTestAppException extends \RuntimeException
{
}
