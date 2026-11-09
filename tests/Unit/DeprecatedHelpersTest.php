<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;

/** The deprecated render helpers warn once per call site in dev mode, never in production. */
class DeprecatedHelpersTest extends TestCase
{
    private const DIR = 'tests/fixtures/render';

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(false);
        RequestContext::instance()->memo = [];
        RequestContext::instance()->zealphp_request = null;
        $this->warned([]);
    }

    protected function tearDown(): void
    {
        App::devReload(false);
        $this->warned([]);
    }

    /** @param array<string, true>|null $set */
    private function warned(?array $set = null): array
    {
        $p = new \ReflectionProperty(App::class, 'deprecation_warned');
        if ($set !== null) {
            $p->setValue(null, $set);
        }
        $v = $p->getValue();
        return is_array($v) ? $v : [];
    }

    public function testSilentOutsideDevMode(): void
    {
        App::devReload(false);
        App::renderToString('echo_only', [], self::DIR);
        $this->assertSame([], $this->warned());
    }

    public function testWarnsOncePerCallSiteInDevMode(): void
    {
        App::devReload(true);
        for ($i = 0; $i < 3; $i++) {
            App::renderToString('echo_only', [], self::DIR);
        }
        App::renderToString('echo_only', [], self::DIR);
        ob_start();
        try {
            App::renderHtmx('/components/_card', ['title' => 't']);
        } finally {
            ob_end_clean();
        }
        $keys = array_keys($this->warned());
        $this->assertCount(3, $keys, implode("\n", $keys));
        $this->assertSame(2, count(array_filter($keys, fn($k) => str_starts_with($k, 'App::renderToString()@tests/Unit/DeprecatedHelpersTest.php:'))));
        $this->assertSame(1, count(array_filter($keys, fn($k) => str_starts_with($k, 'App::renderHtmx()@'))));
    }

    public function testStreamHelperWarnsWhenIterated(): void
    {
        App::devReload(true);
        iterator_to_array(App::renderStream('echo_only', [], self::DIR));
        $this->assertNotEmpty(array_filter(array_keys($this->warned()), fn($k) => str_starts_with($k, 'App::renderStream()@')));
    }
}
