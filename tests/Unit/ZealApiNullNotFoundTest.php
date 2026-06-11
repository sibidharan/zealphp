<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * #347 — the null-return contract, MODE-AWARE (corrected rule).
 *
 * The two dispatch modes are mutually exclusive (filename match wins), so a
 * `null` + empty + 200 return means different things and is graded by mode:
 *   • FILENAME match (`$search` serves all methods, no-ops on some) → the
 *     handler IS the responder and chose to emit nothing → **empty 200**
 *     (native-PHP parity; an empty-set/infinite-scroll tail). The earlier
 *     blanket 404 was a bug — it broke clients reading empty-200 as "no more".
 *   • PER-METHOD (`$get`/`$post`/…) handler that ran and returned null →
 *     `404 {"error":"method_not_found"}`. (A method with NO handler 405s
 *     before reaching here.)
 * Escape hatches unchanged: echoed output, an explicit status, explicit `''`,
 * and `App::apiNullNotFound(false)` (disables the per-method 404).
 */
class ZealApiNullNotFoundTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        App::apiNullNotFound(true);

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

        $this->tmpRoot = sys_get_temp_dir() . '/zealphp_api_nnf_' . bin2hex(random_bytes(6));
        $apiDir = $this->tmpRoot . '/api';
        @mkdir($apiDir . '/m', 0777, true);

        // The labs shape: FILENAME-MATCH closure that no-ops on GET. Under the
        // corrected #347 rule this is an intentional empty 200 (the handler IS
        // the responder for GET and chose to emit nothing) — NOT a 404.
        file_put_contents(
            $apiDir . '/m/search.php',
            '<?php $search = function() {'
            . ' if ((\ZealPHP\G::instance()->server["REQUEST_METHOD"] ?? "GET") !== "POST") { return null; }'
            . ' return ["results" => ["a"]]; };'
        );
        // PER-METHOD dispatch whose $get handler returns null → 404 (the only
        // shape that still yields the method_not_found envelope under the rule).
        file_put_contents(
            $apiDir . '/m/pmnull.php',
            '<?php $get = function() { return null; };'
        );
        // Intentional empty 200 — explicit empty string.
        file_put_contents(
            $apiDir . '/m/emptystr.php',
            '<?php $emptystr = function() { return ""; };'
        );
        // Null return but with echoed output — body wins, stays 200.
        file_put_contents(
            $apiDir . '/m/echoer.php',
            '<?php $echoer = function() { echo "body"; return null; };'
        );
        // Null return but the handler set an explicit status — status wins.
        file_put_contents(
            $apiDir . '/m/statuser.php',
            '<?php $statuser = function() { \ZealPHP\G::instance()->status = 204; return null; };'
        );
    }

    protected function tearDown(): void
    {
        $g = G::instance();
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        $g->_streaming = null;
        $g->status = null;
        $g->server = [];
        $g->get = [];
        $g->post = [];
        App::apiNullNotFound(true);

        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeApi(): ZealAPI
    {
        return new ZealAPI(new \stdClass(), new \stdClass(), $this->tmpRoot);
    }

    public function testFilenameMatchNullYields200Empty(): void
    {
        // Corrected #347 rule: a filename-match handler that returns null on a
        // method it doesn't internally handle is an intentional empty 200 —
        // never a 404 (the bug that broke empty-200-as-"no-more-data" clients).
        $result = $this->makeApi()->processApi('/m', 'search');
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('', (string) $result->getBody());
    }

    public function testPerMethodNullYields404Envelope(): void
    {
        // The one shape that still 404s: a per-method handler ($get) that ran
        // and produced no response.
        $result = $this->makeApi()->processApi('/m', 'pmnull');
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(404, $result->getStatusCode());
        $decoded = json_decode((string) $result->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('method_not_found', $decoded['error'] ?? null);
    }

    public function testPostStillReachesTheHandler(): void
    {
        G::instance()->server = ['REQUEST_METHOD' => 'POST'];
        $result = $this->makeApi()->processApi('/m', 'search');
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('results', (string) $result->getBody());
    }

    public function testExplicitEmptyStringStays200(): void
    {
        $result = $this->makeApi()->processApi('/m', 'emptystr');
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('', (string) $result->getBody());
    }

    public function testEchoedOutputWithNullReturnStays200(): void
    {
        $result = $this->makeApi()->processApi('/m', 'echoer');
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('body', (string) $result->getBody());
    }

    public function testExplicitStatusWithNullReturnIsRespected(): void
    {
        $result = $this->makeApi()->processApi('/m', 'statuser');
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(204, $result->getStatusCode());
    }

    public function testGlobalOptOutRestoresNullTo200Empty(): void
    {
        App::apiNullNotFound(false);
        // The opt-out only matters for the per-method shape (filename-match is
        // already 200); with it off, a per-method null is a plain empty 200.
        $result = $this->makeApi()->processApi('/m', 'pmnull');
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('', (string) $result->getBody());
    }

    public function testKnobRoundTrip(): void
    {
        $this->assertTrue(App::apiNullNotFound());
        $this->assertFalse(App::apiNullNotFound(false));
        $this->assertFalse(App::apiNullNotFound());
        $this->assertTrue(App::apiNullNotFound(true));
    }
}
