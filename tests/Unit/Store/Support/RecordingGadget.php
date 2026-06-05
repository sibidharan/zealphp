<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store\Support;

/**
 * Test gadget used to prove MemcachedBackend's object-injection defence
 * (#251). If `unserialize()` ever instantiated this class, `__wakeup` /
 * `__destruct` would flip the static flags below — the test asserts they
 * stay false, i.e. the magic methods never ran.
 *
 * With the fix, a serialized RecordingGadget blob read back through the
 * backend deserializes with `['allowed_classes' => false]`, so it becomes a
 * `__PHP_Incomplete_Class` and NONE of these run.
 */
final class RecordingGadget
{
    public static bool $wakeupCalled = false;
    public static bool $destructCalled = false;

    public string $marker = 'gadget-payload';

    public static function reset(): void
    {
        self::$wakeupCalled = false;
        self::$destructCalled = false;
    }

    public function __wakeup(): void
    {
        self::$wakeupCalled = true;
    }

    public function __destruct()
    {
        self::$destructCalled = true;
    }
}
