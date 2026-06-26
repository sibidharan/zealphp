<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * #471 — the implicit ZealAPI `/api/**` catch-alls must be registered ONLY when an
 * `api/` directory exists under the app cwd (ZealAPI resolves handlers from
 * `self::$cwd.'/api'`). A project with NO api/ dir — e.g. hosting a foreign
 * front-controller app (Slim/Shaarli) whose own REST API lives under `/api/*` —
 * must cede `/api` to `App::setFallback()` instead of having every `/api/*`
 * shadowed by the terminal #347 `method_not_found` 404. With the api routes
 * absent, `/api/...` matches the `/{dir}/{uri}` public catch-all, finds no file,
 * and falls through to the fallback. Genuine ZealAPI projects (which DO have an
 * api/ dir) keep the implicit routes AND the #347 404 for an unmatched method.
 */
class ImplicitApiRouteGatingTest extends TestCase
{
    private string $tmp;
    /** @var array<int, mixed> */
    private array $savedRoutes = [];

    public static function setUpBeforeClass(): void
    {
        // Build the App singleton ONCE here (not in per-test setUp) so installing
        // the process error/exception handlers in App's constructor isn't attributed
        // to a test — which PHPUnit would flag risky ("removed error handlers").
        App::superglobals(true);
        if (App::instance() === null) {
            App::init('127.0.0.1', 0);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::superglobals(true);
        // Capture the singleton's real routes up-front so tearDown always restores
        // them (registerImplicitRoutes() appends to the shared $routes property).
        $rp = new \ReflectionProperty(App::class, 'routes');
        $rp->setAccessible(true);
        /** @var array<int, mixed> $cur */
        $cur = $rp->getValue(App::instance());
        $this->savedRoutes = $cur;

        $this->tmp = sys_get_temp_dir() . '/zealphp_apigate_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $app = App::instance();
        if ($app !== null) {
            $rp = new \ReflectionProperty(App::class, 'routes');
            $rp->setAccessible(true);
            $rp->setValue($app, $this->savedRoutes);
        }
        App::$cwd = ZEALPHP_ROOT;
        @rmdir($this->tmp . '/api');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /**
     * Run a fresh registerImplicitRoutes() rooted at $cwd and return the resulting
     * route paths (the shared $routes is isolated to [] first so only the implicit
     * routes are observed).
     *
     * @return list<string>
     */
    private function implicitPaths(string $cwd): array
    {
        $app = App::instance();
        $this->assertNotNull($app);

        $rp = new \ReflectionProperty(App::class, 'routes');
        $rp->setAccessible(true);
        $rp->setValue($app, []);

        App::$cwd = $cwd;
        $m = new \ReflectionMethod(App::class, 'registerImplicitRoutes');
        $m->setAccessible(true);
        $m->invoke($app);

        /** @var array<int, mixed> $routes */
        $routes = $rp->getValue($app);
        return array_values(array_map(
            static fn($r) => is_array($r) ? (string) ($r['path'] ?? '') : '',
            $routes
        ));
    }

    public function testNoApiDirSkipsImplicitApiRoutes(): void
    {
        $paths = $this->implicitPaths($this->tmp); // no api/ subdir

        $apiRoutes = array_values(array_filter($paths, static fn($p) => str_starts_with($p, '/api/')));
        $this->assertSame([], $apiRoutes, '#471 — no api/ dir → no implicit /api catch-alls (ceded to fallback)');
        // The public catch-alls are still present, so /api/* matches /{dir}/{uri}
        // and falls through to the fallback.
        $this->assertContains('/', $paths, 'public implicit routes still register');
    }

    public function testApiDirRegistersImplicitApiRoutes(): void
    {
        @mkdir($this->tmp . '/api', 0700, true);

        $paths = $this->implicitPaths($this->tmp);

        $apiRoutes = array_values(array_filter($paths, static fn($p) => str_starts_with($p, '/api/')));
        $this->assertNotEmpty($apiRoutes, '#471 — api/ dir present → implicit /api routes registered (#347 preserved)');
    }
}
