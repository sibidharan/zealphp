<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * RequestContext::once() — request-scoped memoization helper.
 *
 * This is the framework's documented alternative to `static $cache = []`
 * in user code. The cache lives on the per-coroutine RequestContext, so
 * it dies with the coroutine (or with the worker on the next recycle in
 * superglobals mode). Critical that the value is computed once per key
 * and reused on subsequent calls without re-invoking the closure.
 */
class RequestContextOnceTest extends TestCase
{
    protected function setUp(): void
    {
        // Tests run outside any coroutine context — RequestContext returns
        // the process-wide singleton. Reset the memo between tests so each
        // case starts clean.
        RequestContext::instance()->memo = [];
    }

    public function testOnceComputesOnceAndCachesValue(): void
    {
        $callCount = 0;
        $compute = function () use (&$callCount) {
            $callCount++;
            return 'computed-value';
        };

        $first  = RequestContext::once('test_key_once', $compute);
        $second = RequestContext::once('test_key_once', $compute);
        $third  = RequestContext::once('test_key_once', $compute);

        $this->assertSame('computed-value', $first);
        $this->assertSame('computed-value', $second);
        $this->assertSame('computed-value', $third);
        $this->assertSame(1, $callCount, 'Closure should only run on the first once() call');
    }

    public function testOnceCachesNullValueWithoutRecomputing(): void
    {
        // A common bug in roll-your-own memo: re-running because the cached
        // value is null. once() uses array_key_exists, not isset, to avoid this.
        $callCount = 0;
        $compute = function () use (&$callCount) {
            $callCount++;
            return null;
        };

        $first  = RequestContext::once('test_key_null', $compute);
        $second = RequestContext::once('test_key_null', $compute);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $callCount, 'null result should still be cached');
    }

    public function testOnceCachesFalseValueWithoutRecomputing(): void
    {
        $callCount = 0;
        $compute = function () use (&$callCount) {
            $callCount++;
            return false;
        };

        RequestContext::once('test_key_false', $compute);
        RequestContext::once('test_key_false', $compute);

        $this->assertSame(1, $callCount, 'false result should still be cached');
    }

    public function testOnceDifferentKeysComputeIndependently(): void
    {
        $callCount = 0;
        $compute = function ($label) use (&$callCount) {
            return function () use ($label, &$callCount) {
                $callCount++;
                return "value-{$label}";
            };
        };

        $a = RequestContext::once('test_key_a', $compute('a'));
        $b = RequestContext::once('test_key_b', $compute('b'));

        $this->assertSame('value-a', $a);
        $this->assertSame('value-b', $b);
        $this->assertSame(2, $callCount);
    }

    public function testHasReportsMembership(): void
    {
        $this->assertFalse(RequestContext::has('test_has_missing'));

        RequestContext::once('test_has_present', fn() => 'x');

        $this->assertTrue(RequestContext::has('test_has_present'));
        $this->assertFalse(RequestContext::has('test_has_missing'));
    }

    public function testForgetClearsMemoizedValue(): void
    {
        $callCount = 0;
        $compute = function () use (&$callCount) {
            $callCount++;
            return 'recomputed';
        };

        RequestContext::once('test_forget_key', $compute);
        $this->assertSame(1, $callCount);

        RequestContext::forget('test_forget_key');
        $this->assertFalse(RequestContext::has('test_forget_key'));

        RequestContext::once('test_forget_key', $compute);
        $this->assertSame(2, $callCount, 'After forget(), once() should recompute');
    }

    public function testOnceReturnsArrayAndObjectByReferenceSemantics(): void
    {
        // PHP arrays are copy-on-write; objects are by-handle. Verify both
        // round-trip through memo correctly without surprises.
        $array  = RequestContext::once('test_once_array', fn() => ['a' => 1, 'b' => 2]);
        $object = RequestContext::once('test_once_obj',   fn() => (object) ['x' => 42]);

        $this->assertSame(['a' => 1, 'b' => 2], $array);
        $this->assertInstanceOf(\stdClass::class, $object);
        $this->assertSame(42, $object->x);

        // Cached object should be the same handle on subsequent calls.
        $object2 = RequestContext::once('test_once_obj', fn() => (object) ['x' => 999]);
        $this->assertSame($object, $object2, 'Cached object handle must be reused');
        $this->assertSame(42, $object2->x);
    }

    public function testOnceIsCallableViaGAlias(): void
    {
        // \ZealPHP\G is a class_alias for RequestContext — both class names
        // share identity. once() must work through either name.
        \ZealPHP\G::once('test_alias_key', fn() => 'via-G');
        $this->assertTrue(\ZealPHP\G::has('test_alias_key'));
        $this->assertTrue(RequestContext::has('test_alias_key'));
        $this->assertSame('via-G', RequestContext::once('test_alias_key', fn() => 'never-runs'));
    }
}
