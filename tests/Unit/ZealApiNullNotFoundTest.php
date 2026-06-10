<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * #347 — Apache-parity for the unhandled-method shape.
 *
 * A filename-match closure that dispatches some methods internally (the labs
 * WebAPI pattern: `$search` serves POST, no-ops on GET) returns null for the
 * rest; Apache's dispatcher 404s those before any handler body runs. ZealAPI
 * previously surfaced that null as `200 OK` + empty body. Now a null return
 * with NO output, NO explicit status and NO streaming yields
 * `404 {"error":"method_not_found"}` — and every intentional-empty escape
 * hatch (echoed output, explicit status, explicit `''`, the global opt-out)
 * keeps its pre-#347 behaviour.
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

        // The labs shape: filename-match closure that no-ops on GET.
        file_put_contents(
            $apiDir . '/m/search.php',
            '<?php $search = function() {'
            . ' if ((\ZealPHP\G::instance()->server["REQUEST_METHOD"] ?? "GET") !== "POST") { return null; }'
            . ' return ["results" => ["a"]]; };'
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

    public function testNullNoOutputNoStatusYields404Envelope(): void
    {
        $result = $this->makeApi()->processApi('/m', 'search');
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
        $result = $this->makeApi()->processApi('/m', 'search');
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
