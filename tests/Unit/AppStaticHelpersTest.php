<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;
use ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse;

/**
 * In-process coverage for the pure / near-pure static + helper methods on
 * src/App.php that don't need a live OpenSwoole server: status coercion +
 * reason phrases + emitStatus, route registration (route/nsRoute/nsPathRoute/
 * patternRoute) into the routes table, the document-root / template / current-
 * file path resolvers, canonicalHost, includeFile alias + tryInclude, and
 * renderError's default response shapes.
 *
 * setUpBeforeClass uses a dedicated port + a test-only route path prefix so it
 * never collides with ResponseMiddlewarePipelineTest's routes on the App
 * process singleton. Coroutine-runtime hooks are reset in tearDownAfterClass
 * (same reason as AppConfigurablesTest) so downstream Integration curl tests
 * don't fatal.
 */
class AppStaticHelpersTest extends TestCase
{
    private const PREFIX = '/zsh-test';

    private static App $app;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        // Superglobals(true) → in-process includeFile() (no CGI subprocess) and
        // sequential request handling. App::init() creates the singleton.
        App::superglobals(true);
        if (App::instance() === null) {
            App::init('127.0.0.1', 19996, ZEALPHP_ROOT);
        }
        $app = App::instance();
        \assert($app !== null);
        self::$app = $app;
    }

    public static function tearDownAfterClass(): void
    {
        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
    }

    protected function setUp(): void
    {
        // Reset configurables / per-request state every helper here touches.
        App::$document_root = 'public';
        App::serverAdmin(null);
        App::canonicalName(null);
        App::useCanonicalName(false);
        App::display_errors(false);

        $g = RequestContext::instance();
        $g->server = [];
        $g->session = [];
        $g->error_render_depth = 0;
        $g->error_exception = null;
        $g->status = 200;
    }

    public function testBaseServerVarsProvidesCgiSapiKeys(): void
    {
        // #270 — the static CGI/SAPI keys seeded into $g->server at worker start
        // so app bootstrap that reads them BEFORE the first request doesn't warn
        // "Undefined array key" (the per-request handler overlays real values).
        $m = new \ReflectionMethod(App::class, 'baseServerVars');
        $m->setAccessible(true);
        /** @var array<string, string> $base */
        $base = $m->invoke(null);
        foreach (['PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'REQUEST_URI', 'DOCUMENT_ROOT', 'REQUEST_METHOD'] as $key) {
            $this->assertArrayHasKey($key, $base, "baseServerVars must provide {$key}");
            $this->assertNotSame('', $base[$key], "{$key} must not be empty");
        }
        $this->assertStringEndsWith($base['PHP_SELF'], $base['SCRIPT_FILENAME']);
    }

    // ─────────────────────────────────────────────────────────────
    // coerceStatusCode()
    // ─────────────────────────────────────────────────────────────

    public function testCoerceStatusInRangePassesThrough(): void
    {
        $this->assertSame(200, App::coerceStatusCode(200));
        $this->assertSame(404, App::coerceStatusCode(404));
        $this->assertSame(100, App::coerceStatusCode(100));   // lower bound
        $this->assertSame(599, App::coerceStatusCode(599));   // upper bound
        $this->assertSame(418, App::coerceStatusCode(418));
        $this->assertSame(451, App::coerceStatusCode(451));
    }

    public function testCoerceStatusOutOfRangeBecomes500(): void
    {
        $this->assertSame(500, App::coerceStatusCode(0));
        $this->assertSame(500, App::coerceStatusCode(-1));
        $this->assertSame(500, App::coerceStatusCode(42));
        $this->assertSame(500, App::coerceStatusCode(999));
        $this->assertSame(500, App::coerceStatusCode(600));   // first invalid above range
    }

    // ─────────────────────────────────────────────────────────────
    // reasonPhrase() + REASON_PHRASES
    // ─────────────────────────────────────────────────────────────

    public function testReasonPhraseKnownCodes(): void
    {
        $this->assertSame('OK', App::reasonPhrase(200));
        $this->assertSame('Not Found', App::reasonPhrase(404));
        $this->assertSame("I'm a teapot", App::reasonPhrase(418));
        $this->assertSame('Too Early', App::reasonPhrase(425));
        $this->assertSame('Unavailable For Legal Reasons', App::reasonPhrase(451));
        $this->assertSame('Insufficient Storage', App::reasonPhrase(507));
        $this->assertSame('Network Authentication Required', App::reasonPhrase(511));
    }

    public function testReasonPhraseUnknownCodeReturnsEmpty(): void
    {
        $this->assertSame('', App::reasonPhrase(444));   // nginx ext, not in table
        $this->assertSame('', App::reasonPhrase(799));
    }

    // ─────────────────────────────────────────────────────────────
    // emitStatus() — two-arg form for codes with a known reason
    // ─────────────────────────────────────────────────────────────

    public function testEmitStatusUsesTwoArgFormForKnownCode(): void
    {
        $fake = new FakeOpenSwooleResponse();
        App::emitStatus($fake, 451);
        $this->assertSame([['status', 451, 'Unavailable For Legal Reasons']], $fake->log);
    }

    public function testEmitStatusTeapot(): void
    {
        $fake = new FakeOpenSwooleResponse();
        App::emitStatus($fake, 418);
        $this->assertSame([['status', 418, "I'm a teapot"]], $fake->log);
    }

    public function testEmitStatusTooEarly(): void
    {
        $fake = new FakeOpenSwooleResponse();
        App::emitStatus($fake, 425);
        $this->assertSame([['status', 425, 'Too Early']], $fake->log);
    }

    public function testEmitStatusUnknownCodeSynthesizesNonEmptyReason(): void
    {
        // #370 — a non-IANA in-range code (no REASON_PHRASES entry) must still
        // reach the wire as its numeric code. OpenSwoole's single-arg form (and
        // an empty-reason two-arg call) flatten it to 200, so emitStatus() now
        // ALWAYS uses the two-arg form with a synthesized non-empty reason.
        $fake = new FakeOpenSwooleResponse();
        App::emitStatus($fake, 444);
        $this->assertSame([['status', 444, 'Status 444']], $fake->log);
    }

    /**
     * @return array<string, array{0:int}>
     */
    public static function nonIanaInRangeCodes(): array
    {
        return [
            '299 warning'        => [299],
            '444 nginx no-resp'  => [444],
            '499 client closed'  => [499],
            '599 in-range top'   => [599],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonIanaInRangeCodes')]
    public function testEmitStatusInRangeNonIanaCodePreservesCode(int $code): void
    {
        // #370 — the numeric code must survive (a non-empty reason ensures
        // OpenSwoole emits HTTP/1.1 <code> instead of downgrading to 200 OK).
        $fake = new FakeOpenSwooleResponse();
        App::emitStatus($fake, $code);
        $this->assertCount(1, $fake->log);
        $this->assertSame('status', $fake->log[0][0]);
        $this->assertSame($code, $fake->log[0][1]);
        $this->assertNotSame('', $fake->log[0][2], 'reason must be non-empty so OpenSwoole keeps the code');
    }

    public function testEmitStatusCommonCodesCarryReason(): void
    {
        foreach ([200 => 'OK', 301 => 'Moved Permanently', 404 => 'Not Found', 500 => 'Internal Server Error'] as $code => $reason) {
            $fake = new FakeOpenSwooleResponse();
            App::emitStatus($fake, $code);
            $this->assertSame([['status', $code, $reason]], $fake->log);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // route() — populates routes table with path/pattern/methods/param_map
    // ─────────────────────────────────────────────────────────────

    private function lastRoute(): array
    {
        $routes = self::$app->routes();
        $route = end($routes);
        \assert(is_array($route));
        return $route;
    }

    public function testRouteRegistersStaticPath(): void
    {
        self::$app->route(self::PREFIX . '/static', fn() => 'x');
        $r = $this->lastRoute();
        $this->assertSame(self::PREFIX . '/static', $r['path']);
        $this->assertSame('#^' . self::PREFIX . '/static$#', $r['pattern']);
        $this->assertSame(['GET'], $r['methods']);
        $this->assertFalse($r['raw']);
        $this->assertSame([], $r['param_map']);
    }

    public function testRouteBuildsNamedParamPattern(): void
    {
        self::$app->route(self::PREFIX . '/user/{id}', fn($id) => $id);
        $r = $this->lastRoute();
        $this->assertSame('#^' . self::PREFIX . '/user/(?P<id>[^/]+)$#', $r['pattern']);
        $this->assertCount(1, $r['param_map']);
        $this->assertSame('id', $r['param_map'][0]['name']);
    }

    public function testRouteOptionsMethodsUppercasedAndRawHonored(): void
    {
        self::$app->route(self::PREFIX . '/opts', ['methods' => ['get', 'post'], 'raw' => true], fn() => '');
        $r = $this->lastRoute();
        $this->assertSame(['GET', 'POST'], $r['methods']);
        $this->assertTrue($r['raw']);
    }

    public function testRouteTwoArgFormTreatsSecondAsHandler(): void
    {
        $called = false;
        self::$app->route(self::PREFIX . '/twoarg', function () use (&$called) { $called = true; });
        $r = $this->lastRoute();
        $this->assertSame(['GET'], $r['methods']);
        $this->assertIsCallable($r['handler']);
    }

    // ─────────────────────────────────────────────────────────────
    // nsRoute() — namespace prefixing
    // ─────────────────────────────────────────────────────────────

    public function testNsRoutePrependsNamespace(): void
    {
        self::$app->nsRoute('zshns', '/widgets', fn() => '');
        $r = $this->lastRoute();
        $this->assertSame('/zshns/widgets', $r['path']);
        $this->assertSame('#^/zshns/widgets$#', $r['pattern']);
    }

    public function testNsRouteTrimsSlashesAroundNamespaceAndPath(): void
    {
        self::$app->nsRoute('/zshns2/', 'thing', fn() => '');
        $r = $this->lastRoute();
        $this->assertSame('/zshns2/thing', $r['path']);
    }

    // ─────────────────────────────────────────────────────────────
    // nsPathRoute() — last param is catch-all (.+)
    // ─────────────────────────────────────────────────────────────

    public function testNsPathRouteLastParamIsCatchAll(): void
    {
        self::$app->nsPathRoute('zshapi', '/{path}', fn($path) => $path);
        $r = $this->lastRoute();
        $this->assertSame('/zshapi/{path}', $r['path']);
        $this->assertSame('#^/zshapi/(?P<path>.+)$#', $r['pattern']);
        // catch-all matches multiple segments
        $this->assertSame(1, preg_match($r['pattern'], '/zshapi/devices/set_pref'));
    }

    public function testNsPathRouteIntermediateParamsAreSingleSegment(): void
    {
        self::$app->nsPathRoute('zshapi2', '/{first}/{rest}', fn($first, $rest) => '');
        $r = $this->lastRoute();
        $this->assertSame('#^/zshapi2/(?P<first>[^/]+)/(?P<rest>.+)$#', $r['pattern']);
        // first is single-segment, rest swallows the remainder
        $this->assertSame(1, preg_match($r['pattern'], '/zshapi2/a/b/c'));
        $this->assertSame(0, preg_match($r['pattern'], '/zshapi2/a'));
    }

    // ─────────────────────────────────────────────────────────────
    // patternRoute() — raw regex, anchored if not already
    // ─────────────────────────────────────────────────────────────

    public function testPatternRouteAnchorsBareRegex(): void
    {
        self::$app->patternRoute('/zshraw/(?P<rest>.*)', fn($rest) => '');
        $r = $this->lastRoute();
        $this->assertSame('#^/zshraw/(?P<rest>.*)$#', $r['pattern']);
        $this->assertSame($r['pattern'], $r['path']);
    }

    public function testPatternRoutePreservesAlreadyAnchoredRegex(): void
    {
        self::$app->patternRoute('#^/zshraw2/.*$#', fn() => '');
        $r = $this->lastRoute();
        $this->assertSame('#^/zshraw2/.*$#', $r['pattern']);
    }

    // ─────────────────────────────────────────────────────────────
    // isExactRoutePath() (via reflection — protected)
    // ─────────────────────────────────────────────────────────────

    public function testIsExactRoutePath(): void
    {
        $m = new \ReflectionMethod(App::class, 'isExactRoutePath');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke(self::$app, '/plain/path'));
        $this->assertFalse($m->invoke(self::$app, '/has/(?P<x>.+)'));
        $this->assertFalse($m->invoke(self::$app, '/has/{param}'));
        $this->assertFalse($m->invoke(self::$app, '/dot.thing'));
    }

    // ─────────────────────────────────────────────────────────────
    // normalizeMethods() (private — via reflection)
    // ─────────────────────────────────────────────────────────────

    public function testNormalizeMethodsUppercasesAndDropsNonStrings(): void
    {
        $m = new \ReflectionMethod(App::class, 'normalizeMethods');
        $m->setAccessible(true);
        $this->assertSame(['GET', 'POST'], $m->invoke(null, ['get', 'Post']));
        // Non-string entries are filtered out.
        $this->assertSame(['DELETE'], $m->invoke(null, [123, 'delete', null]));
        $this->assertSame([], $m->invoke(null, []));
    }

    // ─────────────────────────────────────────────────────────────
    // resolveDocumentRoot()
    // ─────────────────────────────────────────────────────────────

    public function testResolveDocumentRootRelativeJoinsCwd(): void
    {
        App::$document_root = 'public';
        $this->assertSame(ZEALPHP_ROOT . '/public', App::resolveDocumentRoot());
    }

    public function testResolveDocumentRootAbsolutePassesThrough(): void
    {
        App::$document_root = '/srv/www/';
        $this->assertSame('/srv/www', App::resolveDocumentRoot());
    }

    public function testResolveDocumentRootStripsTrailingSlashOnRelative(): void
    {
        App::$document_root = 'htdocs/';
        $this->assertSame(ZEALPHP_ROOT . '/htdocs', App::resolveDocumentRoot());
    }

    // ─────────────────────────────────────────────────────────────
    // getCurrentFile()
    // ─────────────────────────────────────────────────────────────

    public function testGetCurrentFileFromExplicitArg(): void
    {
        $this->assertSame('article', App::getCurrentFile('/var/www/article.php'));
        $this->assertSame('index', App::getCurrentFile('index.php'));
    }

    public function testGetCurrentFileFromPhpSelf(): void
    {
        $g = RequestContext::instance();
        $g->server['PHP_SELF'] = '/blog/post.php';
        $this->assertSame('post', App::getCurrentFile(null));
    }

    public function testGetCurrentFileEmptyWhenNoPhpSelf(): void
    {
        $g = RequestContext::instance();
        $g->server = [];
        $this->assertSame('', App::getCurrentFile(null));
    }

    // ─────────────────────────────────────────────────────────────
    // canonicalHost()
    // ─────────────────────────────────────────────────────────────

    public function testCanonicalHostReturnsEmptyWithNoHost(): void
    {
        $g = RequestContext::instance();
        $g->server = [];
        $this->assertSame('', App::canonicalHost());
    }

    public function testCanonicalHostPrefersHostHeaderOverServerName(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'h.example', 'SERVER_NAME' => 's.example'];
        $this->assertSame('h.example', App::canonicalHost());
    }

    // ─────────────────────────────────────────────────────────────
    // resolveTemplatePath() (private — via reflection)
    // ─────────────────────────────────────────────────────────────

    public function testResolveTemplatePathRootLookup(): void
    {
        $m = new \ReflectionMethod(App::class, 'resolveTemplatePath');
        $m->setAccessible(true);
        $resolved = $m->invoke(null, '/_master', 'template');
        $this->assertSame(realpath(ZEALPHP_ROOT . '/template/_master.php'), $resolved);
    }

    public function testResolveTemplatePathMissingThrows(): void
    {
        $m = new \ReflectionMethod(App::class, 'resolveTemplatePath');
        $m->setAccessible(true);
        $this->expectException(\ZealPHP\TemplateUnavailableException::class);
        $m->invoke(null, '/definitely-no-such-template-xyz', 'template');
    }

    // ─────────────────────────────────────────────────────────────
    // includeFile() alias + tryInclude()
    // ─────────────────────────────────────────────────────────────

    public function testTryIncludeMissingReturnsNull(): void
    {
        $this->assertNull(App::tryInclude('/no-such-file-zsh.php'));
    }

    public function testIncludeFileOutsideDocRootRunsViaCore(): void
    {
        // includeFile() with an absolute path outside the document root runs
        // the file through executeFile() (superglobals mode is in-process here
        // because processIsolation defaults follow superglobals but the file is
        // outside docroot — still routed through the contract). We assert the
        // universal return contract carries the file's explicit return.
        $tmp = sys_get_temp_dir() . '/zsh_incl_' . uniqid() . '.php';
        file_put_contents($tmp, "<?php return 'from-include-file';");
        // Force in-process dispatch (no CGI subprocess) so the test stays pure.
        $savedCoproc = App::$coproc_implicit_request_handler;
        App::$coproc_implicit_request_handler = false;
        try {
            $this->assertSame('from-include-file', App::includeFile($tmp));
        } finally {
            App::$coproc_implicit_request_handler = $savedCoproc;
            @unlink($tmp);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // renderError() — default response shapes (no handler registered)
    // ─────────────────────────────────────────────────────────────

    public function testRenderErrorDefaultHtmlBody(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_ACCEPT' => 'text/html', 'REQUEST_METHOD' => 'GET'];
        $res = self::$app->renderError(404);
        $this->assertSame(404, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('404 Not Found', $body);
        $this->assertStringContainsString('<pre>', $body);
    }

    public function testRenderErrorJsonBodyWhenAcceptJson(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_ACCEPT' => 'application/json', 'REQUEST_METHOD' => 'GET'];
        $res = self::$app->renderError(503);
        $this->assertSame(503, $res->getStatusCode());
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $res->getBody(), true);
        $this->assertSame(503, $decoded['error']['status']);
        $this->assertSame('Service Unavailable', $decoded['error']['message']);
    }

    public function testRenderErrorJsonIncludesServerAdminContact(): void
    {
        App::serverAdmin('ops@zsh.example');
        $g = RequestContext::instance();
        $g->server = ['HTTP_ACCEPT' => 'application/json', 'REQUEST_METHOD' => 'GET'];
        $res = self::$app->renderError(500);
        $decoded = json_decode((string) $res->getBody(), true);
        $this->assertSame('ops@zsh.example', $decoded['error']['contact']);
    }

    public function testRenderErrorHtmlIncludesServerAdminAddress(): void
    {
        App::serverAdmin('webmaster@zsh.example');
        $g = RequestContext::instance();
        $g->server = ['HTTP_ACCEPT' => 'text/html', 'REQUEST_METHOD' => 'GET'];
        $res = self::$app->renderError(403);
        $this->assertStringContainsString('webmaster@zsh.example', (string) $res->getBody());
    }

    public function testRenderErrorRecursionGuardFallsToDefault(): void
    {
        // depth >= 1 short-circuits straight to the default response, even if a
        // handler were registered. We just verify it produces the default body.
        $g = RequestContext::instance();
        $g->error_render_depth = 1;
        $g->server = ['HTTP_ACCEPT' => 'text/html', 'REQUEST_METHOD' => 'GET'];
        $res = self::$app->renderError(500);
        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringContainsString('500 Internal Server Error', (string) $res->getBody());
    }

    public function testRefreshGlobalsBaselineIsSafeNoOpWithoutExt(): void
    {
        // #26 — without ext-zealphp 0.3.33+ the helper is a safe no-op returning
        // false, so the worker-start auto-call never errors on a stock PHP build.
        // The real refresh behaviour (post-activation boot $GLOBALS visible to every
        // request coroutine, not just the first) is validated against the ASAN ext.
        if (\function_exists('zealphp_globals_baseline_refresh')) {
            $this->markTestSkipped('ext-zealphp 0.3.33+ present — no-op contract not exercised here.');
        }
        $this->assertFalse(\ZealPHP\App::refreshGlobalsBaseline());
    }
}
