<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\HaltException;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * Unit coverage for ZealAPI::processApi() — file resolution, the
 * variable-name binding rule (handler var must match basename), parameter
 * injection by name, the universal return contract across the JSON / int /
 * string / Generator / ResponseInterface shapes, and the guard clauses
 * (invalid_module / invalid_request / method_not_found / traversal refusal).
 *
 * processApi() can run fully in-process: it `include`s a fixture file from a
 * temp api/ dir, binds the closure to $this, and invokes it with reflection.
 * No live OpenSwoole request/response socket is involved on these paths, so
 * everything below is unit-reachable. Endpoints that need $request/$response
 * are exercised through the integration suite.
 */
class ZealApiProcessApiTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        // $app injection resolves App::instance() (the singleton). Boot it
        // deterministically so testParameterInjectionBindsAppAndDefaults
        // doesn't depend on whether a prior test in the suite happened to
        // construct it (init() is idempotent — only builds when null).
        if (App::instance() === null) {
            App::init('127.0.0.1', 0);
        }

        // Per-request state — the RestTest pattern. response() writes through
        // zealphp_response; processApi reads/writes $g->server.
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

        // Build an isolated temp "project root" with an api/ subtree of fixtures.
        $this->tmpRoot = sys_get_temp_dir() . '/zealphp_api_' . bin2hex(random_bytes(6));
        $apiDir = $this->tmpRoot . '/api';
        @mkdir($apiDir . '/users', 0777, true);

        // GET /api/users/get_action → array → JSON
        file_put_contents(
            $apiDir . '/users/get_action.php',
            '<?php $get_action = function() { return ["users" => ["alice", "bob"]]; };'
        );
        // string return → echoed verbatim
        file_put_contents(
            $apiDir . '/users/hello.php',
            '<?php $hello = function() { return "hi there"; };'
        );
        // int return → HTTP status
        file_put_contents(
            $apiDir . '/users/status.php',
            '<?php $status = function() { return 201; };'
        );
        // echo with no explicit return → buffered output is the body
        file_put_contents(
            $apiDir . '/users/echoed.php',
            '<?php $echoed = function() { echo "echoed-body"; };'
        );
        // ResponseInterface return → used directly
        file_put_contents(
            $apiDir . '/users/psr.php',
            '<?php $psr = function() { return new \OpenSwoole\Core\Psr\Response("psr-body", 202); };'
        );
        // Generator return → streamed (returned as-is)
        file_put_contents(
            $apiDir . '/users/gen.php',
            '<?php $gen = function() { return (function() { yield "a"; yield "b"; })(); };'
        );
        // Parameter injection by name — $app is the App instance (the ZealAPI
        // instance is reachable via $this inside the handler closure).
        file_put_contents(
            $apiDir . '/users/inject.php',
            '<?php $inject = function($app, $missing = "dflt") { return ["is_app" => $app instanceof \ZealPHP\App, "missing" => $missing]; };'
        );
        // Handler that calls a real method on $this
        file_put_contents(
            $apiDir . '/users/usesthis.php',
            '<?php $usesthis = function() { return ["authed" => $this->isAuthenticated()]; };'
        );
        // Variable-name mismatch — file defines wrong var name
        file_put_contents(
            $apiDir . '/users/mismatch.php',
            '<?php $wrongname = function() { return ["x" => 1]; };'
        );
        // Typo inside handler → __call → BadMethodCallException → 404 hint
        file_put_contents(
            $apiDir . '/users/typo.php',
            '<?php $typo = function() { return $this->paramExist(["a"]); };'
        );
        // Root-level file (issue #157): /api/login → processApi('', 'login').
        // Lives directly under api/ (no module subdir) and must resolve.
        file_put_contents(
            $apiDir . '/login.php',
            '<?php $login = function() { return ["ok" => true, "where" => "root"]; };'
        );
    }

    protected function tearDown(): void
    {
        // Reset RequestContext globals set in setUp so they don't leak.
        $g = G::instance();
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        $g->_streaming = null;
        $g->status = null;
        $g->server = [];
        $g->get = [];
        $g->post = [];

        App::authChecker(null);
        App::adminChecker(null);
        App::usernameProvider(null);

        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testRunHandlerWithContractTreatsHaltAsCleanResponse(): void
    {
        // #194: a handler that echoes then throws HaltException must produce a
        // normal Response carrying the buffered body — not propagate to
        // $api->die() (a 4xx error) and lose the buffered body.
        $resp = $this->makeApi()->runHandlerWithContract(function (): void {
            echo 'halted-body';
            throw new HaltException('stop');
        }, []);

        $this->assertInstanceOf(ResponseInterface::class, $resp);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('halted-body', (string) $resp->getBody());
    }

    private function makeApi(): ZealAPI
    {
        return new ZealAPI(null, null, $this->tmpRoot);
    }

    /**
     * processApi() either returns a Response/Generator or echoes via its
     * own ob_*; capture both so assertions see the body either way.
     *
     * @return array{return: mixed, echo: string}
     */
    private function dispatch(ZealAPI $api, string $module, ?string $request): array
    {
        $depth = ob_get_level();
        ob_start();
        $ret = $api->processApi($module, $request);
        $echo = (string) ob_get_clean();
        // processApi's ResponseInterface fast-path returns without closing its
        // own internal ob_start() (framework behaviour); drain any buffers it
        // left dangling above our entry depth so PHPUnit doesn't flag risky.
        while (ob_get_level() > $depth) {
            ob_end_clean();
        }
        return ['return' => $ret, 'echo' => $echo];
    }

    // ── return-contract shapes ───────────────────────────────────────

    public function testArrayReturnEmitsJsonResponse(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'get_action');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = (string) $r['return']->getBody();
        $this->assertSame(['users' => ['alice', 'bob']], json_decode($body, true));
        $this->assertSame(200, $r['return']->getStatusCode());
    }

    public function testStringReturnEchoedAsBody(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'hello');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $this->assertSame('hi there', (string) $r['return']->getBody());
    }

    public function testIntReturnBecomesHttpStatus(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'status');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $this->assertSame(201, $r['return']->getStatusCode());
    }

    public function testEchoNoReturnBufferedAsBody(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'echoed');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $this->assertSame('echoed-body', (string) $r['return']->getBody());
    }

    public function testResponseInterfaceReturnedDirectly(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'psr');
        $this->assertInstanceOf(Response::class, $r['return']);
        $this->assertSame(202, $r['return']->getStatusCode());
        $this->assertSame('psr-body', (string) $r['return']->getBody());
    }

    public function testGeneratorReturnedAsIs(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'gen');
        $this->assertInstanceOf(\Generator::class, $r['return']);
        $this->assertSame(['a', 'b'], iterator_to_array($r['return']));
    }

    // ── parameter injection + $this binding ──────────────────────────

    public function testParameterInjectionBindsAppAndDefaults(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'inject');
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertTrue($body['is_app']);
        $this->assertSame('dflt', $body['missing']);
    }

    public function testHandlerThisIsBoundToZealApiInstance(): void
    {
        App::authChecker(fn(): bool => true);
        $r = $this->dispatch($this->makeApi(), 'users', 'usesthis');
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertTrue($body['authed']);
    }

    public function testReflectionCacheReusedAcrossCalls(): void
    {
        // Two invocations of the same endpoint hit the cached reflection
        // params on the second call — both must still produce identical output.
        $first = $this->dispatch($this->makeApi(), 'users', 'get_action');
        $second = $this->dispatch($this->makeApi(), 'users', 'get_action');
        $this->assertSame(
            (string) $first['return']->getBody(),
            (string) $second['return']->getBody()
        );
    }

    // ── guard clauses / error paths ──────────────────────────────────

    public function testInvalidModuleReturns400(): void
    {
        $r = $this->dispatch($this->makeApi(), 'bad module!', 'get');
        $this->assertNull($r['return']);
        $this->assertSame(['error' => 'invalid_module'], json_decode($r['echo'], true));
    }

    public function testInvalidRequestReturns400(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'has.dots');
        $this->assertNull($r['return']);
        $this->assertSame(['error' => 'invalid_request'], json_decode($r['echo'], true));
    }

    public function testMissingFileReturns404(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'doesnotexist');
        $this->assertNull($r['return']);
        $this->assertSame(['error' => 'method_not_found'], json_decode($r['echo'], true));
    }

    public function testDotDotInModuleRejectedByRegex(): void
    {
        // The module regex /^\/[a-zA-Z0-9_\/-]+$/ disallows '.', so any
        // ../ traversal attempt is refused at the first guard as invalid_module
        // (before realpath even runs).
        $r = $this->dispatch($this->makeApi(), 'users/../../etc', 'passwd');
        $this->assertNull($r['return']);
        $this->assertSame(['error' => 'invalid_module'], json_decode($r['echo'], true));
    }

    public function testValidCharModuleThatResolvesNowhereReturns404(): void
    {
        // Passes both regexes (only valid chars) but realpath() fails because
        // nothing exists there → method_not_found via the traversal guard.
        $r = $this->dispatch($this->makeApi(), 'no-such-dir', 'whatever');
        $this->assertNull($r['return']);
        $this->assertSame(['error' => 'method_not_found'], json_decode($r['echo'], true));
    }

    public function testEmptyModuleWithUnknownFuncReturns404(): void
    {
        // module === '' and func not a method on ZealAPI → method_not_found
        $r = $this->dispatch($this->makeApi(), '', 'nope');
        $this->assertNull($r['return']);
        $this->assertSame(['error' => 'method_not_found'], json_decode($r['echo'], true));
    }

    public function testRootLevelApiFileResolvesWithEmptyModule(): void
    {
        // Issue #157: /api/login → processApi('', 'login'). 'login' is NOT a
        // ZealAPI method, but api/login.php exists at the root of api/, so the
        // file-resolution block must stat + dispatch it instead of 404ing.
        $r = $this->dispatch($this->makeApi(), '', 'login');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $this->assertSame(200, $r['return']->getStatusCode());
        $this->assertSame(
            ['ok' => true, 'where' => 'root'],
            json_decode((string) $r['return']->getBody(), true)
        );
    }

    public function testTypoInsideHandlerYields404WithHint(): void
    {
        $r = $this->dispatch($this->makeApi(), 'users', 'typo');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $this->assertSame(404, $r['return']->getStatusCode());
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('undefined_method', $body['error']);
        $this->assertSame('paramExist', $body['method']);
        // levenshtein('paramexist', 'paramsexists') is small → suggestion present
        $this->assertSame('paramsExists', $body['did_you_mean']);
    }
}
