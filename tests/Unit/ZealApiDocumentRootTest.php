<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * Regression test for issue #18 — ZealAPI must keep $_SERVER['DOCUMENT_ROOT']
 * at the web root for API routes, matching Apache/mod_php. Previously
 * processApi() overwrote it to "<cwd>/api", which broke handlers that include
 * files relative to DOCUMENT_ROOT (the mod_php convention).
 *
 * Also pins the dependent SCRIPT_NAME / PHP_SELF / SCRIPT_FILENAME parity:
 * the script path is rooted at the URL ('/api/<module>/<request>.php') and
 * SCRIPT_FILENAME is the real handler file.
 */
class ZealApiDocumentRootTest extends TestCase
{
    private string $tmpRoot;
    private string $apiDir;

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
            public function header(string $name, mixed $value, bool $ucwords = true): void { $this->headers[$name] = $value; }
            public function status(int $status): void { $this->status = $status; }
        };

        $this->tmpRoot = sys_get_temp_dir() . '/zealphp_docroot_' . bin2hex(random_bytes(6));
        $this->apiDir = $this->tmpRoot . '/api';
        @mkdir($this->apiDir . '/diag', 0777, true);
        // Trivial handler — the fix is observed via $g->server side effects, so
        // the handler body is irrelevant beyond being a valid bound closure.
        file_put_contents(
            $this->apiDir . '/diag/docroot.php',
            '<?php $docroot = function() { return ["ok" => true]; };'
        );
    }

    protected function tearDown(): void
    {
        $g = G::instance();
        $g->server = [];
        $g->zealphp_request = null;
        $g->zealphp_response = null;
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

    public function testDocumentRootStaysAtWebRootForApiRoutes(): void
    {
        $api = new ZealAPI(null, null, $this->tmpRoot);
        $depth = ob_get_level();
        ob_start();
        $api->processApi('diag', 'docroot');
        while (ob_get_level() > $depth) {
            ob_end_clean();
        }

        $g = G::instance();
        $this->assertSame(
            App::resolveDocumentRoot(),
            $g->server['DOCUMENT_ROOT'],
            'DOCUMENT_ROOT must equal the configured web root, not the /api subdir'
        );
        $this->assertStringEndsNotWith(
            '/api',
            (string) $g->server['DOCUMENT_ROOT'],
            'DOCUMENT_ROOT must not be pointed at the /api subdirectory (issue #18)'
        );
    }

    public function testScriptVariablesMatchApacheParity(): void
    {
        $api = new ZealAPI(null, null, $this->tmpRoot);
        $depth = ob_get_level();
        ob_start();
        $api->processApi('diag', 'docroot');
        while (ob_get_level() > $depth) {
            ob_end_clean();
        }

        $g = G::instance();
        $this->assertSame('/api/diag/docroot.php', $g->server['SCRIPT_NAME']);
        $this->assertSame('/api/diag/docroot.php', $g->server['PHP_SELF']);
        $this->assertSame(
            realpath($this->apiDir . '/diag/docroot.php'),
            $g->server['SCRIPT_FILENAME'],
            'SCRIPT_FILENAME must be the real handler file (mod_php parity)'
        );
    }
}
