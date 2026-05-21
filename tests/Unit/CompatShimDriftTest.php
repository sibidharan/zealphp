<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use ZealPHP\RequestContext;

/**
 * Drift guard for the canonical dual-runtime shim at `compat/g.php`.
 *
 * The shim's Apache branch builds a plain object with reference properties
 * (`get`, `post`, `server`, `cookie`, `files`, `request`, `session`) so that
 * `$g->X` resolves to PHP's superglobals when ZealPHP isn't loaded. Those
 * keys MUST match the request-data properties that `RequestContext` exposes
 * under ZealPHP — otherwise the same `$g->X` access would work on one runtime
 * and not the other, silently breaking dual-runtime apps.
 *
 * This test fails loudly if either side drifts: a key added to the shim that
 * RequestContext doesn't have, or a request-data property added to
 * RequestContext that the shim's Apache branch forgot.
 *
 * See compat/g.php and /legacy-apps#dual-runtime.
 */
class CompatShimDriftTest extends PhpUnitTestCase
{
    /**
     * The request-data surface the shim bridges. These are the property
     * names that map 1:1 to PHP superglobals ($_GET, $_POST, ...). Other
     * RequestContext properties (status, zealphp_request, memo, error
     * stacks, etc.) are framework-only state that has no Apache analogue and
     * is intentionally NOT in the shim.
     *
     * @var list<string>
     */
    private const SHIM_KEYS = ['get', 'post', 'server', 'cookie', 'files', 'request', 'session'];

    /**
     * Parse the canonical shim file and extract the keys its Apache-branch
     * stdClass actually declares — so the test reads the real file, not a
     * copy of the list.
     *
     * @return list<string>
     */
    private function extractShimKeys(string $path): array
    {
        $src = file_get_contents($path);
        $this->assertIsString($src, "Could not read shim at $path");
        // Match the (object) [ ... ] block's quoted keys: 'get' => &$_GET,
        $matches = [];
        preg_match_all("/'([a-z]+)'\\s*=>\\s*&\\\$_/", $src, $matches);
        $keys = $matches[1];
        sort($keys);
        return $keys;
    }

    public function testCanonicalShimFileExists(): void
    {
        $path = dirname(__DIR__, 2) . '/compat/g.php';
        $this->assertFileExists($path, 'Canonical compat shim must exist at compat/g.php');
    }

    public function testCanonicalShimKeysMatchExpectedSurface(): void
    {
        $path = dirname(__DIR__, 2) . '/compat/g.php';
        $keys = $this->extractShimKeys($path);
        $expected = self::SHIM_KEYS;
        sort($expected);
        $this->assertSame($expected, $keys,
            'compat/g.php Apache-branch keys drifted from the expected request-data surface');
    }

    public function testScaffoldShimMatchesCanonical(): void
    {
        $canonical = $this->extractShimKeys(dirname(__DIR__, 2) . '/compat/g.php');
        $scaffold  = $this->extractShimKeys(dirname(__DIR__, 2) . '/examples/lamp-scaffold/bootstrap/g.php');
        $this->assertSame($canonical, $scaffold,
            'LAMP scaffold bootstrap/g.php drifted from canonical compat/g.php');
    }

    public function testEveryShimKeyIsADeclaredRequestContextProperty(): void
    {
        $rc = new \ReflectionClass(RequestContext::class);
        foreach (self::SHIM_KEYS as $key) {
            $this->assertTrue($rc->hasProperty($key),
                "Shim key '$key' must be a declared property on RequestContext — "
                . "if it was renamed/removed, update compat/g.php to match");
            $prop = $rc->getProperty($key);
            $type = $prop->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type,
                "RequestContext::\$$key should have a named type");
            $this->assertSame('array', $type->getName(),
                "RequestContext::\$$key must be array-typed so the shim's &\$_$key reference is type-compatible");
        }
    }
}
