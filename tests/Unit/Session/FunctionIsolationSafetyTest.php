<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Session\CoSessionManager;
use ZealPHP\Session\SessionManager;

/**
 * Covers the private static `safeForFunctionIsolation()` guard added to both
 * SessionManager and CoSessionManager in the CGI-pool / function-isolation PR.
 *
 * The guard decides whether per-request function/class/include cleanup
 * (`zealphp_process_state_clean`) is safe to run. It is NOT safe when an app
 * has registered a Composer autoloader that lazy-loads classes from files
 * under `App::$document_root` — cleaning the function/class table mid-process
 * would orphan those classes while the require_once cache persists. The guard
 * returns false in that case so endSession() skips the cleanup.
 *
 * The method is `private static`, reached via reflection. We register/unregister
 * temporary Composer ClassLoaders to deterministically exercise each branch
 * (PSR-4 prefix, PSR-0 prefix, classmap, and the "outside docRoot" case) rather
 * than relying on whatever the test runner's own autoloader happens to map.
 */
final class FunctionIsolationSafetyTest extends TestCase
{
    /** @var class-string[] */
    private const MANAGERS = [SessionManager::class, CoSessionManager::class];

    private string $savedDocRoot;

    /** @var ClassLoader[] */
    private array $registered = [];

    protected function setUp(): void
    {
        $this->savedDocRoot = App::$document_root;
    }

    protected function tearDown(): void
    {
        foreach ($this->registered as $loader) {
            $loader->unregister();
        }
        $this->registered = [];
        App::$document_root = $this->savedDocRoot;
    }

    private function call(string $manager): bool
    {
        $ref = new \ReflectionMethod($manager, 'safeForFunctionIsolation');
        $ref->setAccessible(true);
        return (bool) $ref->invoke(null);
    }

    private function register(ClassLoader $loader): void
    {
        $loader->register();
        $this->registered[] = $loader;
    }

    /**
     * Empty docRoot is the "no document root configured" sentinel — always
     * safe (no app classes can live under "nothing").
     */
    public function testEmptyDocumentRootIsAlwaysSafe(): void
    {
        App::$document_root = '';
        foreach (self::MANAGERS as $m) {
            $this->assertTrue($this->call($m), "$m should treat empty docRoot as safe");
        }
    }

    /**
     * The "." docRoot is the framework default; treated as safe.
     */
    public function testCurrentDirDocumentRootIsAlwaysSafe(): void
    {
        App::$document_root = '.';
        foreach (self::MANAGERS as $m) {
            $this->assertTrue($this->call($m), "$m should treat '.' docRoot as safe");
        }
    }

    /**
     * A concrete docRoot with NO autoloader mapping into it is safe.
     */
    public function testDocumentRootWithNoMatchingAutoloaderIsSafe(): void
    {
        App::$document_root = '/tmp/zp-app-' . bin2hex(random_bytes(4)) . '/public';
        foreach (self::MANAGERS as $m) {
            $this->assertTrue($this->call($m), "$m should be safe when nothing maps under docRoot");
        }
    }

    /**
     * A PSR-4 prefix resolving under docRoot => NOT safe.
     */
    public function testPsr4PrefixUnderDocumentRootIsUnsafe(): void
    {
        $docRoot = '/tmp/zp-app-' . bin2hex(random_bytes(4)) . '/public';
        App::$document_root = $docRoot;

        $loader = new ClassLoader();
        $loader->setPsr4('App\\', $docRoot . '/src');
        $this->register($loader);

        foreach (self::MANAGERS as $m) {
            $this->assertFalse($this->call($m), "$m must be unsafe when a PSR-4 prefix maps under docRoot");
        }
    }

    /**
     * A PSR-0 prefix resolving under docRoot => NOT safe.
     */
    public function testPsr0PrefixUnderDocumentRootIsUnsafe(): void
    {
        $docRoot = '/tmp/zp-app-' . bin2hex(random_bytes(4)) . '/public';
        App::$document_root = $docRoot;

        $loader = new ClassLoader();
        $loader->set('Legacy_', $docRoot . '/lib');
        $this->register($loader);

        foreach (self::MANAGERS as $m) {
            $this->assertFalse($this->call($m), "$m must be unsafe when a PSR-0 prefix maps under docRoot");
        }
    }

    /**
     * A classmap entry pointing to a file under docRoot => NOT safe.
     */
    public function testClassmapUnderDocumentRootIsUnsafe(): void
    {
        $docRoot = '/tmp/zp-app-' . bin2hex(random_bytes(4)) . '/public';
        App::$document_root = $docRoot;

        $loader = new ClassLoader();
        $loader->addClassMap(['App\\Service' => $docRoot . '/Service.php']);
        $this->register($loader);

        foreach (self::MANAGERS as $m) {
            $this->assertFalse($this->call($m), "$m must be unsafe when a classmap entry lives under docRoot");
        }
    }

    /**
     * An autoloader whose paths are all OUTSIDE docRoot stays safe — the guard
     * must only trip on paths that actually live under the document root.
     */
    public function testAutoloaderOutsideDocumentRootIsSafe(): void
    {
        $docRoot = '/tmp/zp-app-' . bin2hex(random_bytes(4)) . '/public';
        App::$document_root = $docRoot;

        $loader = new ClassLoader();
        $loader->setPsr4('Vendor\\', '/opt/vendor/src');
        $loader->set('Old_', '/opt/legacy/lib');
        $loader->addClassMap(['Vendor\\Thing' => '/opt/vendor/Thing.php']);
        $this->register($loader);

        foreach (self::MANAGERS as $m) {
            $this->assertTrue($this->call($m), "$m should stay safe when every autoloader path is outside docRoot");
        }
    }

    /**
     * Trailing slash on docRoot must not break the prefix match — the guard
     * rtrim()s docRoot before comparing, so "/x/public/" and "/x/public"
     * behave identically.
     */
    public function testTrailingSlashDocumentRootStillMatches(): void
    {
        $base = '/tmp/zp-app-' . bin2hex(random_bytes(4)) . '/public';
        App::$document_root = $base . '/';

        $loader = new ClassLoader();
        $loader->setPsr4('App\\', $base . '/src');
        $this->register($loader);

        foreach (self::MANAGERS as $m) {
            $this->assertFalse($this->call($m), "$m must normalise the trailing slash and still detect the match");
        }
    }
}
