<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * #376 — endpoint files must not clobber (or read) processApi() locals.
 *
 * The filename-handler convention assigns the closure to
 * `${basename(__FILE__, '.php')}`, and the include used to run directly in
 * processApi()'s scope — so a file named `request.php` set `$request` to a
 * Closure and the Apache-parity block's
 * `'/api'.$module.'/'.$request.'.php'` fataled with "Object of class
 * Closure could not be converted to string" (500 on every call). Seen in
 * production on labs-dashboard-web (`api/admin/ssl/request.php`,
 * `api/learn/syllabus/request.php`).
 *
 * The include now runs in an isolated scope, so NO dispatcher local is
 * reachable from endpoint files — the reserved-name list is zero. Pinned
 * here: the `request.php` repro, a second dispatcher local (`module.php`),
 * a non-Closure clobber (`$g = 'oops'`), and the two BC contracts the
 * isolation must preserve — top-level `$this->…` access in endpoint files
 * (the wrapper closure auto-binds $this) and per-method `$get` dispatch.
 */
class ZealApiScopeIsolationTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
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

        $this->tmpRoot = sys_get_temp_dir() . '/zealphp_api_scope_' . bin2hex(random_bytes(6));
        $apiDir = $this->tmpRoot . '/api';
        @mkdir($apiDir . '/m', 0777, true);

        // The #376 repro verbatim: filename matches the dispatcher's $request local.
        file_put_contents(
            $apiDir . '/m/request.php',
            '<?php ${basename(__FILE__, ".php")} = function () { return ["ok" => true]; };'
        );
        // A second dispatcher local ($module feeds the same string concat + elog strings).
        file_put_contents(
            $apiDir . '/m/module.php',
            '<?php $module = function () { return ["mod" => true]; };'
        );
        // Non-Closure clobber: the dispatcher writes $g->server[...] after the
        // include — a string $g would fatal "Attempt to assign property on string".
        file_put_contents(
            $apiDir . '/m/gclobber.php',
            '<?php $g = "oops"; $gclobber = function () { return ["g" => "safe"]; };'
        );
        // BC: top-level $this-> access in an endpoint file (include runs inside
        // an instance context, so $this must remain bound in the new scope).
        file_put_contents(
            $apiDir . '/m/thisbound.php',
            '<?php $thisbound = ($this instanceof \ZealPHP\ZealAPI)'
            . ' ? function () { return ["this" => "bound"]; }'
            . ' : null;'
        );
        // BC: per-method dispatch still resolves from the isolated scope.
        file_put_contents(
            $apiDir . '/m/permethod.php',
            '<?php $get = function () { return ["method" => "get"]; };'
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

    /** @param mixed $result */
    private function assertJsonBody($result, int $status, string $key, mixed $value): void
    {
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame($status, $result->getStatusCode());
        $decoded = json_decode((string) $result->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame($value, $decoded[$key] ?? null);
    }

    public function testEndpointNamedRequestDoesNotClobberDispatcher(): void
    {
        // Pre-fix: Error "Object of class Closure could not be converted to
        // string" at the Apache-parity $scriptName concat — 500 on every call.
        $result = $this->makeApi()->processApi('/m', 'request');
        $this->assertJsonBody($result, 200, 'ok', true);
        // The Apache-parity vars must be built from the REAL request name.
        $this->assertSame('/api//m/request.php', G::instance()->server['SCRIPT_NAME'] ?? null);
    }

    public function testEndpointNamedModuleDoesNotClobberDispatcher(): void
    {
        $result = $this->makeApi()->processApi('/m', 'module');
        $this->assertJsonBody($result, 200, 'mod', true);
    }

    public function testEndpointAssigningGDoesNotClobberRequestContext(): void
    {
        $result = $this->makeApi()->processApi('/m', 'gclobber');
        $this->assertJsonBody($result, 200, 'g', 'safe');
        // The dispatcher's post-include $g->server writes must have reached
        // the real RequestContext, not the endpoint file's string.
        $this->assertSame('/api//m/gclobber.php', G::instance()->server['PHP_SELF'] ?? null);
    }

    public function testTopLevelThisRemainsBoundInEndpointFiles(): void
    {
        $result = $this->makeApi()->processApi('/m', 'thisbound');
        $this->assertJsonBody($result, 200, 'this', 'bound');
    }

    public function testPerMethodDispatchStillResolvesFromIsolatedScope(): void
    {
        $result = $this->makeApi()->processApi('/m', 'permethod');
        $this->assertJsonBody($result, 200, 'method', 'get');
    }
}
