<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * App::reloadRoutes() — in-process route hot-reload: it re-includes route/*.php
 * and rebuilds the dispatch table from a baseline, and SAFELY refuses (rather
 * than fatally redeclaring) when a route file declares a top-level function.
 */
class RouteReloadTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        App::superglobals(true);
        self::$app = App::init('0.0.0.0', 19996, ZEALPHP_ROOT);
    }

    /** Point App::$cwd at a temp dir holding a route/ subdir + give $app a clean baseline. */
    private function withTempRoutes(string $routeFileBody): string
    {
        $tmp = sys_get_temp_dir() . '/zealphp_reload_' . bin2hex(random_bytes(4));
        @mkdir($tmp . '/route', 0700, true);
        file_put_contents($tmp . '/route/r.php', "<?php\n" . $routeFileBody);

        App::$cwd = $tmp;

        // Inject a clean baseline (run() normally does this) via reflection.
        $ref = new \ReflectionProperty(App::class, 'route_baseline');
        $ref->setAccessible(true);
        $ref->setValue(self::$app, ['routes' => [], 'implicit' => [], 'when' => [], 'aliases' => []]);

        return $tmp;
    }

    private function cleanup(string $tmp): void
    {
        App::$cwd = ZEALPHP_ROOT;
        @unlink($tmp . '/route/r.php');
        @rmdir($tmp . '/route');
        @rmdir($tmp);
    }

    /** @return list<string> */
    private function paths(): array
    {
        return array_values(array_map(
            fn($r) => is_array($r) ? (string)($r['path'] ?? '') : '',
            self::$app->routes()
        ));
    }

    public function testReloadPicksUpFunctionFreeRouteFile(): void
    {
        $tmp = $this->withTempRoutes(
            "\\ZealPHP\\App::instance()->route('/reload-test-x', fn() => 'ok');"
        );
        try {
            $count = self::$app->reloadRoutes();
            $this->assertContains('/reload-test-x', $this->paths());
            $this->assertGreaterThan(0, $count);
        } finally {
            $this->cleanup($tmp);
        }
    }

    public function testReloadRefusesFunctionDeclaringRouteFileWithoutCrash(): void
    {
        // A top-level function can't be re-included (redeclaration fatal), so
        // reloadRoutes must REFUSE and leave the table unchanged — never crash.
        $tmp = $this->withTempRoutes(
            "function zealphp_reload_helper_fn() { return 1; }\n"
            . "\\ZealPHP\\App::instance()->route('/should-not-appear', fn() => 'x');"
        );
        try {
            self::$app->reloadRoutes();
            $this->assertNotContains('/should-not-appear', $this->paths());
            $this->assertFalse(function_exists('zealphp_reload_helper_fn'), 'function file must not be included on refusal');
        } finally {
            $this->cleanup($tmp);
        }
    }

    public function testReloadResetsToBaselineEachTime(): void
    {
        // Two reloads with different route files: the second must NOT retain the
        // first's routes (baseline reset), proving it rebuilds rather than appends.
        $tmp1 = $this->withTempRoutes("\\ZealPHP\\App::instance()->route('/reload-first', fn() => 'a');");
        try {
            self::$app->reloadRoutes();
            $this->assertContains('/reload-first', $this->paths());
        } finally {
            $this->cleanup($tmp1);
        }

        $tmp2 = $this->withTempRoutes("\\ZealPHP\\App::instance()->route('/reload-second', fn() => 'b');");
        try {
            self::$app->reloadRoutes();
            $this->assertContains('/reload-second', $this->paths());
            $this->assertNotContains('/reload-first', $this->paths(), 'baseline reset should drop the prior reload');
        } finally {
            $this->cleanup($tmp2);
        }
    }

    public function testDevReloadSetterAndEnv(): void
    {
        $orig = App::$dev_reload;
        try {
            App::devReload(true);
            $this->assertTrue(App::devReload());
            App::devReload(false);
            $this->assertFalse(App::devReload());
        } finally {
            App::$dev_reload = $orig;
        }
    }

    public function testDevCliFlagEnablesHotReload(): void
    {
        // `php app.php --dev` must flip dev reload on. parseCliArgs() sets the
        // static during run(); we drive it directly with a synthetic argv and a
        // throwaway --pid-file so the 'start' path returns without forking/exiting.
        $origArgv = $_SERVER['argv'] ?? null;
        $origDev = App::$dev_reload;
        try {
            App::$dev_reload = null; // baseline: unset (would fall back to env)
            $_SERVER['argv'] = [
                'app.php', 'start', '--dev',
                '--pid-file', sys_get_temp_dir() . '/zp_dev_' . bin2hex(random_bytes(4)) . '.pid',
            ];
            $m = new \ReflectionMethod(\ZealPHP\CLI::class, 'parseCliArgs');
            $m->setAccessible(true);
            $m->invoke(null);
            $this->assertTrue(App::devReload(), '--dev CLI flag must enable route hot-reload');
        } finally {
            App::$dev_reload = $origDev;
            if ($origArgv !== null) {
                $_SERVER['argv'] = $origArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }
    }
}
