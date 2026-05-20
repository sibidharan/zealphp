<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * Branch coverage for ZealAPI::processApi() not reached by
 * ZealApiProcessApiTest.php:
 *
 *   - module === '' && method_exists($this, $func)  →  $this->$func()  (122)
 *   - non-Closure handler var → \Closure::bind TypeError → 404          (142-145)
 *   - parameter injection for $request / $response / $server names      (163,165,167)
 *   - parameter with no default value → null injection                 (171)
 *   - BadMethodCallException with empty _undefinedMethodError → rethrow (191)
 *   - $g->_streaming set by handler → ob_end_clean + early return       (197-199)
 *   - json() non-array branch → "{}"                                    (403)
 *
 * Same in-process fixture-dir harness as ZealApiProcessApiTest.
 */
class ZealApiCoverageTest extends TestCase
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

        $this->tmpRoot = sys_get_temp_dir() . '/zealphp_api_cov_' . bin2hex(random_bytes(6));
        $apiDir = $this->tmpRoot . '/api';
        @mkdir($apiDir . '/m', 0777, true);

        // Non-Closure handler var → Closure::bind() raises TypeError → 404.
        file_put_contents(
            $apiDir . '/m/notclosure.php',
            '<?php $notclosure = "i am a string, not a closure";'
        );
        // Parameter injection for the $request / $response / $server names.
        file_put_contents(
            $apiDir . '/m/inject_named.php',
            '<?php $inject_named = function($request, $response, $server) {' .
            ' return ["req" => $request !== null, "res_class" => is_object($response), "srv" => is_array($server) || $server === null]; };'
        );
        // Parameter with no default value (and not a reserved name) → injected null.
        file_put_contents(
            $apiDir . '/m/nodefault.php',
            '<?php $nodefault = function($somearg) { return ["arg_is_null" => $somearg === null]; };'
        );
        // Streaming handler: sets _streaming, framework must early-return null.
        file_put_contents(
            $apiDir . '/m/streamer.php',
            '<?php $streamer = function() { \ZealPHP\RequestContext::instance()->_streaming = true; echo "streamed"; };'
        );
        // Handler throwing BadMethodCallException directly (no _undefinedMethodError
        // populated) → processApi rethrows it.
        file_put_contents(
            $apiDir . '/m/badcall.php',
            '<?php $badcall = function() { throw new \BadMethodCallException("boom"); };'
        );
        // Handler that calls the private json() helper with a non-array → "{}".
        file_put_contents(
            $apiDir . '/m/jsonnonarray.php',
            '<?php $jsonnonarray = function() { return $this->json("not-an-array"); };'
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
        // Pass concrete request/response stand-ins so the named-parameter
        // injection branches ($request/$response) receive non-null values.
        $request = new \stdClass();
        $response = new \stdClass();
        return new ZealAPI($request, $response, $this->tmpRoot);
    }

    /**
     * @return array{return: mixed, echo: string}
     */
    private function dispatch(ZealAPI $api, string $module, ?string $request): array
    {
        $depth = ob_get_level();
        ob_start();
        $ret = $api->processApi($module, $request);
        $echo = (string) ob_get_clean();
        while (ob_get_level() > $depth) {
            ob_end_clean();
        }
        return ['return' => $ret, 'echo' => $echo];
    }

    public function testEmptyModuleCallsMethodOnSelf(): void
    {
        // module === '' and 'isAuthenticated' IS a real method on ZealAPI →
        // hits the $this->$func() dispatch branch (line 122). With no auth
        // checker wired, isAuthenticated() returns false and sends its own
        // response; processApi returns null.
        App::authChecker(fn(): bool => false);
        $r = $this->dispatch($this->makeApi(), '', 'isAuthenticated');
        $this->assertNull($r['return']);
    }

    public function testNonClosureHandlerVarReturns404(): void
    {
        $r = $this->dispatch($this->makeApi(), 'm', 'notclosure');
        $this->assertNull($r['return']);
        $this->assertSame(['error' => 'method_not_found'], json_decode($r['echo'], true));
    }

    public function testNamedParameterInjection(): void
    {
        $r = $this->dispatch($this->makeApi(), 'm', 'inject_named');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertTrue($body['req']);
        $this->assertTrue($body['res_class']);
        $this->assertTrue($body['srv']);
    }

    public function testParameterWithoutDefaultInjectsNull(): void
    {
        $r = $this->dispatch($this->makeApi(), 'm', 'nodefault');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertTrue($body['arg_is_null']);
    }

    public function testStreamingHandlerEarlyReturnsNull(): void
    {
        $r = $this->dispatch($this->makeApi(), 'm', 'streamer');
        // _streaming set → ob_end_clean + return (no Response built).
        $this->assertNull($r['return']);
        $this->assertTrue(G::instance()->_streaming);
    }

    public function testBadMethodCallWithoutHintRethrows(): void
    {
        $depth = ob_get_level();
        $thrown = null;
        try {
            $this->makeApi()->processApi('m', 'badcall');
        } catch (\BadMethodCallException $e) {
            $thrown = $e;
        } finally {
            while (ob_get_level() > $depth) {
                ob_end_clean();
            }
        }
        $this->assertInstanceOf(\BadMethodCallException::class, $thrown);
        $this->assertSame('boom', $thrown->getMessage());
    }

    public function testJsonNonArrayReturnsEmptyObject(): void
    {
        $r = $this->dispatch($this->makeApi(), 'm', 'jsonnonarray');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        // json('not-an-array') → "{}", echoed as the body.
        $this->assertSame('{}', (string) $r['return']->getBody());
    }
}
