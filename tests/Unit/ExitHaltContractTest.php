<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\HaltException;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * ext#47 — the framework half of the exit()/die() → HaltException contract.
 *
 * ext-zealphp 0.3.48 intercepts a userland exit() inside a coroutine and
 * throws ZealPHP\HaltException with the exit argument in ->status (string
 * message / int code / null for bare exit). These tests pin the framework's
 * consumption of that status at the halt-aware sites — by throwing the same
 * shape from fixtures, so they run without the extension:
 *
 *  - executeFile (App::render): string status appended to the buffered body
 *    (mod_php echoes the exit message), int 100–599 → HTTP status (the
 *    established ExitException mapping), null → pre-existing behaviour.
 *  - ZealAPI::runHandlerWithContract: same mapping on the API path.
 *  - HaltException::getStatus() mirrors OpenSwoole\ExitException::getStatus()
 *    so the generic exit-predicate sites treat both uniformly.
 */
class ExitHaltContractTest extends TestCase
{
    private const DIR = 'tests/fixtures/render';

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(false); // coroutine mode → executeFile runs in-process
        $g = G::instance();
        $g->memo = [];
        $g->status = null;
    }

    protected function tearDown(): void
    {
        App::hookExit(null);
        // hookExit() only writes on non-null, so reset the backing static directly.
        App::$hook_exit = null;
        parent::tearDown();
    }

    // ── HaltException status carrier ─────────────────────────────

    public function testStatusDefaultsToNullAndRoundTrips(): void
    {
        $h = new HaltException('x');
        $this->assertNull($h->getStatus());
        $h->status = 'msg';
        $this->assertSame('msg', $h->getStatus());
        $h->status = 302;
        $this->assertSame(302, $h->getStatus());
    }

    public function testHookExitFluentAccessor(): void
    {
        $this->assertNull(App::hookExit());
        $this->assertTrue(App::hookExit(true));
        $this->assertTrue(App::hookExit());
        $this->assertFalse(App::hookExit(false));
    }

    // ── executeFile / render consumption ─────────────────────────

    public function testStringStatusIsAppendedToBufferedOutput(): void
    {
        // exit("redirected") after echo "before-" → mod_php emits both.
        $this->assertSame('before-redirected', App::render('halt_string_status', [], self::DIR));
    }

    public function testIntStatusBecomesHttpStatus(): void
    {
        // exit(302) → the established ExitException int mapping.
        $this->assertSame(302, App::render('halt_int_status', [], self::DIR));
    }

    public function testNullStatusKeepsPreExistingHaltBehaviour(): void
    {
        // bare `throw new HaltException` (fragments, app-facing halts) — BC.
        $this->assertSame('kept', App::render('halt_null_status', [], self::DIR));
    }

    public function testNestedObLevelsCollapseBeforeStringStatus(): void
    {
        // An app that pushed its own ob_start() before exiting — inner buffers
        // flush into ours in wire order, then the exit message appends.
        $this->assertSame('ab-c', App::render('halt_nested_ob_string', [], self::DIR));
    }

    // ── ZealAPI consumption ──────────────────────────────────────

    /** @return array{0: ZealAPI, 1: string} api instance + tmp root */
    private function makeApiWithFixtures(): array
    {
        App::superglobals(true);
        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $g->get = [];
        $g->post = [];
        $g->_streaming = null;
        $g->status = null;
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

        $tmp = sys_get_temp_dir() . '/zealphp_exithalt_' . bin2hex(random_bytes(6));
        @mkdir($tmp . '/api/m', 0777, true);
        file_put_contents(
            $tmp . '/api/m/haltstr.php',
            '<?php $haltstr = function () { echo "A";'
            . ' $h = new \ZealPHP\HaltException("zealphp exit"); $h->status = "B"; throw $h; };'
        );
        file_put_contents(
            $tmp . '/api/m/haltint.php',
            '<?php $haltint = function () { echo "gone";'
            . ' $h = new \ZealPHP\HaltException("zealphp exit"); $h->status = 410; throw $h; };'
        );
        return [new ZealAPI(new \stdClass(), new \stdClass(), $tmp), $tmp];
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testApiHandlerExitStringAppendsToBody(): void
    {
        [$api, $tmp] = $this->makeApiWithFixtures();
        try {
            $result = $api->processApi('/m', 'haltstr');
            $this->assertInstanceOf(ResponseInterface::class, $result);
            $this->assertSame(200, $result->getStatusCode());
            $this->assertSame('AB', (string) $result->getBody());
        } finally {
            $g = G::instance();
            $g->zealphp_request = null;
            $g->zealphp_response = null;
            $g->server = [];
            $this->rrmdir($tmp);
        }
    }

    public function testApiHandlerExitIntBecomesHttpStatus(): void
    {
        [$api, $tmp] = $this->makeApiWithFixtures();
        try {
            $result = $api->processApi('/m', 'haltint');
            $this->assertInstanceOf(ResponseInterface::class, $result);
            $this->assertSame(410, $result->getStatusCode());
            $this->assertSame('gone', (string) $result->getBody());
        } finally {
            $g = G::instance();
            $g->zealphp_request = null;
            $g->zealphp_response = null;
            $g->server = [];
            $this->rrmdir($tmp);
        }
    }
}
