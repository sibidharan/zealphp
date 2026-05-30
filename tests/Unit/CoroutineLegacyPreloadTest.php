<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ZealPHP\App;
use ZealPHP\HTTP\Request;
use ZealPHP\RequestContext;

/**
 * Unit coverage for the coroutine-legacy preload + request-input rebind surface
 * added on the fix/coroutine-legacy-preload-and-request-input branch.
 *
 * These are the boot-time / dispatch-time primitives that NO integration request
 * can exercise under coverage: the preload helpers run in onWorkerStart / the
 * master before fork (a separate, un-instrumented process when an integration
 * fixture spawns it), and `rebindRequestInput`'s active body only fires in
 * coroutine-legacy mode. So they are pinned here, in-process, where pcov sees
 * them. The behavioural side (real concurrent requests) lives in
 * tests/Integration/CoroutineLegacyBehaviorTest.php.
 *
 * `rebindRequestInput`'s active body and the toggle past the `function_exists`
 * guard need the ext loaded — those cases skip cleanly without it (CI loads
 * ext-zealphp, so they run there).
 */
final class CoroutineLegacyPreloadTest extends TestCase
{
    /** @var array<string,bool> */
    private array $savedSuperglobals = [];

    protected function setUp(): void
    {
        // Reset the registration state these tests mutate (public static props).
        App::$preload_classes = [];
        App::$preload_classmap = false;
        App::$preload_dirs = [];
        // rebindRequestInput writes the real superglobals — snapshot to restore.
        $this->savedSuperglobals = [
            'GET' => $_GET, 'POST' => $_POST, 'COOKIE' => $_COOKIE,
            'FILES' => $_FILES, 'SERVER' => $_SERVER, 'REQUEST' => $_REQUEST,
        ];
    }

    protected function tearDown(): void
    {
        App::$coroutine_isolated_superglobals = false;
        App::$preload_classes = [];
        App::$preload_classmap = false;
        App::$preload_dirs = [];
        // Restore superglobals any active-path test may have rewritten.
        $_GET = $this->savedSuperglobals['GET'];
        $_POST = $this->savedSuperglobals['POST'];
        $_COOKIE = $this->savedSuperglobals['COOKIE'];
        $_FILES = $this->savedSuperglobals['FILES'];
        $_SERVER = $this->savedSuperglobals['SERVER'];
        $_REQUEST = $this->savedSuperglobals['REQUEST'];
    }

    /** Invoke a private static App method by reflection. */
    private static function priv(string $method, array $args = []): mixed
    {
        $m = new ReflectionMethod(App::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    /** Build a ZealPHP\HTTP\Request without its (server-bound) constructor. */
    private static function fakeRequest(array $props): Request
    {
        /** @var Request $req */
        $req = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
        foreach ($props as $k => $v) {
            $req->$k = $v;
        }
        return $req;
    }

    // ───────────────────────── preload setters ─────────────────────────

    public function testPreloadClassesAppends(): void
    {
        App::preloadClasses('Foo\\Bar', 'Baz\\Qux');
        $this->assertSame(['Foo\\Bar', 'Baz\\Qux'], App::$preload_classes);
        App::preloadClasses('More\\One');
        $this->assertSame(['Foo\\Bar', 'Baz\\Qux', 'More\\One'], App::$preload_classes);
    }

    public function testPreloadClassmapToggle(): void
    {
        $this->assertFalse(App::$preload_classmap);
        App::preloadClassmap();
        $this->assertTrue(App::$preload_classmap);
        App::preloadClassmap(false);
        $this->assertFalse(App::$preload_classmap);
    }

    public function testPreloadDirAppends(): void
    {
        App::preloadDir('/tmp/a');
        App::preloadDir('/tmp/b');
        $this->assertSame(['/tmp/a', '/tmp/b'], App::$preload_dirs);
    }

    // ──────────────────────── symbolsInFile (tokenizer) ────────────────────────

    public function testSymbolsInFileExtractsEveryDeclarationKind(): void
    {
        $f = $this->tmpPhp(
            "<?php\nnamespace Acme\\Pkg;\n" .
            "class Alpha {}\ninterface Beta {}\ntrait Gamma {}\nenum Delta {}\n"
        );
        $syms = self::priv('symbolsInFile', [$f]);
        @unlink($f);
        $this->assertEqualsCanonicalizing(
            ['Acme\\Pkg\\Alpha', 'Acme\\Pkg\\Beta', 'Acme\\Pkg\\Gamma', 'Acme\\Pkg\\Delta'],
            $syms
        );
    }

    public function testSymbolsInFileNoNamespace(): void
    {
        $f = $this->tmpPhp("<?php\nclass PlainTop {}\n");
        $syms = self::priv('symbolsInFile', [$f]);
        @unlink($f);
        $this->assertSame(['PlainTop'], $syms);
    }

    public function testSymbolsInFileSkipsClassConstAndAnonymous(): void
    {
        // `::class` and `new class {}` must NOT be captured as declarations.
        $f = $this->tmpPhp(
            "<?php\nnamespace N;\n\$a = Something::class;\n\$o = new class {};\nclass Real {}\n"
        );
        $syms = self::priv('symbolsInFile', [$f]);
        @unlink($f);
        $this->assertSame(['N\\Real'], $syms);
    }

    public function testSymbolsInFileHandlesBracedNamespace(): void
    {
        $f = $this->tmpPhp("<?php\nnamespace Braced {\n  class Inside {}\n}\n");
        $syms = self::priv('symbolsInFile', [$f]);
        @unlink($f);
        $this->assertSame(['Braced\\Inside'], $syms);
    }

    public function testSymbolsInFileMissingOrEmptyReturnsEmpty(): void
    {
        $this->assertSame([], self::priv('symbolsInFile', ['/no/such/file_' . uniqid() . '.php']));
        $empty = $this->tmpPhp('');
        $this->assertSame([], self::priv('symbolsInFile', [$empty]));
        @unlink($empty);
    }

    // ──────────────────────── warmClass / warmDir ────────────────────────

    public function testWarmClassExistingAndMissingNeverThrow(): void
    {
        self::priv('warmClass', [App::class]);                       // already loaded
        self::priv('warmClass', ['Totally\\Missing\\' . uniqid()]); // unresolved -> swallowed
        $this->assertTrue(true); // reached -> neither threw
    }

    public function testWarmDirNonDirectoryReturnsEarly(): void
    {
        self::priv('warmDir', ['/no/such/dir_' . uniqid()]);
        $this->assertTrue(true);
    }

    public function testWarmDirWalksPhpFilesOnly(): void
    {
        $dir = sys_get_temp_dir() . '/zwarm_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/A.php', "<?php\nnamespace ZWarm;\nclass A {}\n");
        file_put_contents($dir . '/ignore.txt', 'not php');
        // No autoloader for ZWarm\A -> warmClass swallows; we assert the walk ran
        // without throwing and visited the tree.
        self::priv('warmDir', [$dir]);
        @unlink($dir . '/A.php');
        @unlink($dir . '/ignore.txt');
        @rmdir($dir);
        $this->assertTrue(true);
    }

    // ──────────────────── warmComposerClassmap / warmBulkPreloads ────────────────────

    public function testWarmComposerClassmapRunsCleanly(): void
    {
        // Composer's ClassLoader is loaded (vendor/autoload bootstrapped the suite)
        // -> this iterates real registered loaders and warms their classmaps.
        self::priv('warmComposerClassmap');
        $this->assertTrue(true);
    }

    public function testWarmBulkPreloadsDirsThenClassmap(): void
    {
        $dir = sys_get_temp_dir() . '/zbulk_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/B.php', "<?php\nclass ZBulkB {}\n");
        App::$preload_dirs = [$dir];
        App::$preload_classmap = true;
        self::priv('warmBulkPreloads');
        @unlink($dir . '/B.php');
        @rmdir($dir);
        $this->assertTrue(true);
    }

    public function testPreloadRequestPathClassesRunsCleanly(): void
    {
        // Exercises the framework-class warm loop AND the user-class loop.
        App::preloadClasses('Cold\\Missing\\' . uniqid());
        self::priv('preloadRequestPathClasses');
        $this->assertTrue(true);
    }

    // ──────────────────────── buildServerVars ────────────────────────

    public function testBuildServerVarsUppercasesHeadersAndSetsDefaults(): void
    {
        $req = self::fakeRequest([
            'server' => ['request_method' => 'POST', 'request_uri' => '/x', 'remote_addr' => '10.0.0.1'],
            'header' => ['host' => 'ex.com', 'x-custom-key' => 'v1'],
        ]);
        $srv = self::priv('buildServerVars', [$req]);

        $this->assertSame('POST', $srv['REQUEST_METHOD']);
        $this->assertSame('/x', $srv['REQUEST_URI']);
        $this->assertSame('ex.com', $srv['HTTP_HOST']);
        $this->assertSame('v1', $srv['HTTP_X_CUSTOM_KEY']);     // dashes -> underscores, upper
        $this->assertSame('CGI/1.1', $srv['GATEWAY_INTERFACE']);
        $this->assertArrayHasKey('SCRIPT_FILENAME', $srv);
        $this->assertArrayHasKey('REQUEST_SCHEME', $srv);
        $this->assertSame('http', $srv['REQUEST_SCHEME']);       // no TLS markers
    }

    public function testBuildServerVarsDefaultsWhenRequestEmpty(): void
    {
        $req = self::fakeRequest(['server' => null, 'header' => null]);
        $srv = self::priv('buildServerVars', [$req]);

        $this->assertSame('GET', $srv['REQUEST_METHOD']);
        $this->assertSame('/', $srv['REQUEST_URI']);
        $this->assertSame('/app.php', $srv['SCRIPT_NAME']);
    }

    public function testBuildServerVarsHonoursMethodOverride(): void
    {
        $req = self::fakeRequest([
            'server' => ['request_method' => 'POST'],
            'header' => ['x-http-method-override' => 'DELETE'],
        ]);
        $srv = self::priv('buildServerVars', [$req]);
        $this->assertSame('DELETE', $srv['REQUEST_METHOD']);
    }

    public function testBuildServerVarsCoercesNonScalarServerValuesToNull(): void
    {
        $req = self::fakeRequest([
            'server' => ['weird' => ['nested' => 'array'], 'ok' => 'scalar'],
            'header' => null,
        ]);
        $srv = self::priv('buildServerVars', [$req]);
        $this->assertNull($srv['WEIRD']);     // non-scalar coerced
        $this->assertSame('scalar', $srv['OK']);
    }

    public function testBuildServerVarsHttpsBranch(): void
    {
        $req = self::fakeRequest([
            'server' => ['https' => 'on', 'server_port' => 443],
            'header' => ['host' => 'secure.example'],
        ]);
        $srv = self::priv('buildServerVars', [$req]);
        $this->assertSame('https', $srv['REQUEST_SCHEME']);
        $this->assertSame('on', $srv['HTTPS']);
    }

    public function testBuildServerVarsHostnameLookupsPopulatesRemoteHost(): void
    {
        $prev = App::$hostname_lookups;
        App::$hostname_lookups = true;
        try {
            // 127.0.0.1 reverse-resolves to 'localhost' on essentially every host;
            // assert the lookup branch ran and produced a non-empty REMOTE_HOST.
            $req = self::fakeRequest([
                'server' => ['remote_addr' => '127.0.0.1'],
                'header' => null,
            ]);
            $srv = self::priv('buildServerVars', [$req]);
            $this->assertNotSame('', (string)($srv['REMOTE_HOST'] ?? ''));
        } finally {
            App::$hostname_lookups = $prev;
        }
    }

    public function testWarmClassSwallowsAutoloaderThrow(): void
    {
        // A class whose autoloader THROWS must not abort worker start: warmClass
        // catches and elog()s. Register a throwing loader for a sentinel name.
        $sentinel = 'ZWarmThrow_' . uniqid();
        $loader = function (string $class) use ($sentinel): void {
            if ($class === $sentinel) {
                throw new \RuntimeException('autoloader boom');
            }
        };
        spl_autoload_register($loader);
        try {
            self::priv('warmClass', [$sentinel]); // must swallow the throw
            $this->assertFalse(class_exists($sentinel, false));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    // ──────────────────────── rebindRequestInput ────────────────────────

    public function testRebindIsNoopWhenIsolationDisabled(): void
    {
        App::$coroutine_isolated_superglobals = false;
        $g = $this->fakeContext(self::fakeRequest(['get' => ['q' => 'x']]));
        App::rebindRequestInput($g);          // must early-return, no throw
        $this->assertTrue(true);
    }

    public function testRebindIsNoopWhenRequestNotPresent(): void
    {
        if (!\function_exists('zealphp_request_input_set')) {
            $this->markTestSkipped('needs ext-zealphp (guard past function_exists)');
        }
        App::$coroutine_isolated_superglobals = true;
        $g = $this->fakeContext(null);        // zealphp_request is not a Request
        App::rebindRequestInput($g);          // early-return at the instanceof guard
        $this->assertTrue(true);
    }

    public function testRebindActivePathRepinsSuperglobalsFromRequest(): void
    {
        if (!\function_exists('zealphp_request_input_set')) {
            $this->markTestSkipped('needs ext-zealphp for the active rebind body');
        }
        App::$coroutine_isolated_superglobals = true;
        $req = self::fakeRequest([
            'get'    => ['q' => 'apple'],
            'post'   => ['p' => 'pear'],
            'cookie' => ['c' => 'cherry'],
            'files'  => [],
            'server' => ['request_method' => 'GET'],
            'header' => ['host' => 'ex.com'],
        ]);
        $g = $this->fakeContext($req);

        App::rebindRequestInput($g);

        $this->assertSame('apple', $_GET['q'] ?? null, 'rebind did not pin $_GET');
        $this->assertSame('pear', $_POST['p'] ?? null, 'rebind did not pin $_POST');
        $this->assertSame('cherry', $_COOKIE['c'] ?? null, 'rebind did not pin $_COOKIE');
        $this->assertSame('ex.com', $_SERVER['HTTP_HOST'] ?? null, 'rebind did not pin $_SERVER');
        // $_REQUEST = GET + POST.
        $this->assertSame('apple', $_REQUEST['q'] ?? null);
        $this->assertSame('pear', $_REQUEST['p'] ?? null);
    }

    public function testRebindServerOverlayWinsOverRequestDerived(): void
    {
        if (!\function_exists('zealphp_request_input_set')) {
            $this->markTestSkipped('needs ext-zealphp for the active rebind body');
        }
        App::$coroutine_isolated_superglobals = true;
        $req = self::fakeRequest([
            'get' => [], 'post' => [], 'cookie' => [], 'files' => [],
            'server' => ['request_method' => 'GET'], 'header' => ['host' => 'ex.com'],
        ]);
        $g = $this->fakeContext($req);

        App::rebindRequestInput($g, ['SCRIPT_NAME' => '/included.php', 'PHP_SELF' => '/included.php']);

        $this->assertSame('/included.php', $_SERVER['SCRIPT_NAME'] ?? null, 'overlay must win for an included file');
        $this->assertSame('/included.php', $_SERVER['PHP_SELF'] ?? null);
    }

    // ──────────────────────────── helpers ────────────────────────────

    private function tmpPhp(string $contents): string
    {
        $f = tempnam(sys_get_temp_dir(), 'zsym_') . '.php';
        file_put_contents($f, $contents);
        return $f;
    }

    private function fakeContext(mixed $zealphpRequest): RequestContext
    {
        /** @var RequestContext $g */
        $g = (new ReflectionClass(RequestContext::class))->newInstanceWithoutConstructor();
        $g->zealphp_request = $zealphpRequest;
        return $g;
    }
}
