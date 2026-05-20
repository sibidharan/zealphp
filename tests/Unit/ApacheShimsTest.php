<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Coverage for src/apache_shims.php — the GLOBAL-namespace apache_* /
 * getallheaders() / virtual() wrappers that delegate to the namespaced
 * \ZealPHP\* implementations.
 *
 * UtilsExtraTest already exercises the \ZealPHP\* functions; this file calls
 * the bare global functions (no namespace prefix) so the conditional
 * if (!function_exists(...)) { function foo(){...} } shim bodies execute.
 *
 * All state mutated lives on $g (apacheContext, zealphp_request/response) and
 * is reset in tearDown.
 */
class ApacheShimsTest extends TestCase
{
    /** @var object{headersList: array<int,array{0:string,1:string}>} */
    private object $resp;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $this->resp = new class {
            /** @var array<int, array{0:string,1:string}> */
            public array $headersList = [];
            public function header(string $k, string $v, bool $ucwords = true): void { $this->headersList[] = [$k, $v]; }
        };
        $g = RequestContext::instance();
        $g->zealphp_response = $this->resp;
        $g->apacheContext = null;
        $g->zealphp_request = null;
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->apacheContext = null;
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        parent::tearDown();
    }

    public function testGlobalApacheRequestHeadersAndGetallheaders(): void
    {
        $g = RequestContext::instance();
        $g->zealphp_request = new class {
            public object $parent;
            public function __construct()
            {
                $this->parent = new class {
                    /** @var array<string,mixed> */
                    public array $header = ['x-test-header' => 'hello'];
                };
            }
        };
        // Bare global call → apache_shims.php wrapper → \ZealPHP\apache_request_headers().
        $headers = \apache_request_headers();
        $this->assertSame('hello', $headers['X-Test-Header']);
        // getallheaders() global alias.
        $this->assertSame($headers, \getallheaders());
    }

    public function testGlobalApacheResponseHeaders(): void
    {
        $this->resp->headersList = [['X-A', '1'], ['X-B', '2']];
        $out = \apache_response_headers();
        $this->assertSame(['X-A' => '1', 'X-B' => '2'], $out);
    }

    public function testGlobalApacheSetenvGetenv(): void
    {
        $this->assertFalse(\apache_getenv('FOO'));
        $this->assertTrue(\apache_setenv('FOO', 'bar'));
        $this->assertSame('bar', \apache_getenv('FOO'));
        // walk_to_top param is accepted and ignored.
        $this->assertTrue(\apache_setenv('BAZ', 'qux', true));
        $this->assertSame('qux', \apache_getenv('BAZ', true));
    }

    public function testGlobalApacheNote(): void
    {
        // First set returns the previous (empty) value.
        $this->assertSame('', \apache_note('n1', 'v1'));
        // Reading back returns the stored value as the "previous".
        $this->assertSame('v1', \apache_note('n1', 'v2'));
        // Read-only call (no value) returns current without mutation.
        $this->assertSame('v2', \apache_note('n1'));
    }

    public function testGlobalVirtualReturnsFalse(): void
    {
        // virtual() is unsupported — logs a warning and returns false.
        $this->assertFalse(\virtual('/some/uri'));
    }
}
