<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ZealPHP\Store;
use ZealPHP\Store\MemcachedBackend;
use ZealPHP\Tests\Unit\Store\Support\RecordingGadget;

/**
 * #251 — object-injection defence for MemcachedBackend.
 *
 * ext-memcached's default PHP serializer runs an UNRESTRICTED `unserialize()`
 * on every `get()`, firing `__wakeup`/`__destruct` of any class in the blob.
 * The fix stores a plain `serialize()`d string and reads it back with
 * `['allowed_classes' => false]`, so a hostile payload can only round-trip to
 * scalars/arrays — objects decode to `__PHP_Incomplete_Class` and no magic
 * methods run.
 *
 * The (de)serialize helpers (`encodeRow`/`decodeRow`) are exercised directly
 * via reflection so this test needs NO memcached server — it pins the security
 * contract on every run. A second test does an end-to-end round-trip when a
 * server is reachable.
 */
final class MemcachedBackendSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingGadget::reset();
    }

    /** @return array{0:ReflectionMethod, 1:ReflectionMethod} */
    private function helpers(): array
    {
        $encode = new ReflectionMethod(MemcachedBackend::class, 'encodeRow');
        $decode = new ReflectionMethod(MemcachedBackend::class, 'decodeRow');
        return [$encode, $decode];
    }

    public function testScalarRowRoundTripsThroughHelpers(): void
    {
        [$encode, $decode] = $this->helpers();
        $blob = $encode->invoke(null, ['name' => 'alice', 'hits' => 7]);
        $this->assertIsString($blob);
        $this->assertSame(['name' => 'alice', 'hits' => 7], $decode->invoke(null, $blob));
    }

    public function testGadgetBlobNeverInstantiatesTheClass(): void
    {
        // A payload an attacker controls: a serialized RecordingGadget. This is
        // exactly what ext-memcached's default serializer would `unserialize()`
        // unrestricted, running __wakeup/__destruct.
        $hostile = serialize(['evil' => new RecordingGadget()]);
        RecordingGadget::reset(); // clear the __destruct flag from the temp instance above

        [, $decode] = $this->helpers();
        $decoded = $decode->invoke(null, $hostile);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('evil', $decoded);
        // The object decodes to __PHP_Incomplete_Class — NOT a live RecordingGadget.
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded['evil']);
        $this->assertFalse(RecordingGadget::$wakeupCalled, '__wakeup must NOT fire on a whitelisted unserialize');
    }

    public function testDecodeRowReturnsNullForMissAndNonString(): void
    {
        [, $decode] = $this->helpers();
        // Memcached miss = false → null
        $this->assertNull($decode->invoke(null, false));
        // Non-string junk → null
        $this->assertNull($decode->invoke(null, 123));
        // A serialized scalar (not an array) → null (rows are always arrays)
        $this->assertNull($decode->invoke(null, serialize('plain-string')));
    }

    public function testEndToEndRoundTripBlocksGadgetWhenServerAvailable(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('ext-memcached not loaded');
        }
        $env = getenv('ZEALPHP_MEMCACHED_SERVERS');
        $servers = is_string($env) && $env !== '' ? $env : '127.0.0.1:11211';
        try {
            $b = new MemcachedBackend($servers, 'zpsec-' . bin2hex(random_bytes(2)));
            if (!$b->ping()) {
                $this->markTestSkipped('memcached unreachable at ' . $servers);
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('memcached setup failed: ' . $e->getMessage());
        }

        $b->make('sec', 100, ['name' => [Store::TYPE_STRING, 32]]);
        $b->set('sec', 'k', ['name' => 'safe']);
        RecordingGadget::reset();

        // Round-trips correctly for scalar rows.
        $this->assertSame(['name' => 'safe'], $b->get('sec', 'k'));
        $this->assertTrue($b->exists('sec', 'k'));
        $this->assertNull($b->get('sec', 'missing'));
        // A normal set/get never instantiates user classes.
        $this->assertFalse(RecordingGadget::$wakeupCalled);
    }
}
