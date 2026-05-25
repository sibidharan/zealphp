<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Pins issue #103 — `App::dispatchTaskCallback()` must tolerate BOTH the
 * 2-arg coroutine form (`task_enable_coroutine => true`) and the legacy
 * 4-arg form. A regression here surfaces in production as
 * `ArgumentCountError: Too few arguments to function … 2 passed and
 * exactly 4 expected`, crashing worker processes mid-task.
 *
 * The handler closure registered on `$server->on('task', …)` is just a
 * thin shim that passes the variadic rest through to this static
 * method; the dispatch logic lives here so it's directly testable
 * without booting a real OpenSwoole server.
 */
final class TaskCallbackDispatchTest extends TestCase
{
    private static string $tmpDir = '';
    private static string $origCwd = '';

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/zealphp-task-test-' . bin2hex(random_bytes(3));
        mkdir(self::$tmpDir . '/task', 0775, true);
        self::$origCwd = App::$cwd ?? '';
        App::$cwd = self::$tmpDir;

        // A working task handler: task/echo.php returns its args verbatim.
        file_put_contents(
            self::$tmpDir . '/task/echo.php',
            "<?php\n\$echo = function (...\$args) { return ['echoed' => \$args]; };\n"
        );
    }

    public static function tearDownAfterClass(): void
    {
        App::$cwd = self::$origCwd;
        @unlink(self::$tmpDir . '/task/echo.php');
        @rmdir(self::$tmpDir . '/task');
        @rmdir(self::$tmpDir);
    }

    // ── shape acceptance: both 2-arg and 4-arg call forms work ──────

    public function testTwoArgCoroutineFormWithTaskObject(): void
    {
        // OpenSwoole 22.x: $server->on('task', function ($server, Task $task))
        // when task_enable_coroutine => true (our default).
        $task = new \stdClass();
        $task->id = 42;
        $task->worker_id = 0;
        $task->data = ['handler' => '/task/echo', 'args' => ['hello']];

        $result = App::dispatchTaskCallback([$task]);

        $this->assertIsArray($result);
        $this->assertSame($task->data, $result['task']);
        $this->assertSame(['echoed' => ['hello']], $result['result']);
    }

    public function testLegacyFourArgFormWithPositionalData(): void
    {
        // OpenSwoole 22.x: $server->on('task', function ($server, $id, $rid, $data))
        // when task_enable_coroutine => false.
        $data = ['handler' => '/task/echo', 'args' => ['world']];

        $result = App::dispatchTaskCallback([7, 0, $data]);

        $this->assertIsArray($result);
        $this->assertSame($data, $result['task']);
        $this->assertSame(['echoed' => ['world']], $result['result']);
    }

    // ── defensive paths: unexpected arity / shape never throw ───────

    public function testUnexpectedArityReturnsFalseWithoutThrowing(): void
    {
        // Hypothetical OpenSwoole bump that adds a 3rd shape — the
        // dispatcher must log + return false rather than throw, so
        // worker stays alive.
        $this->assertFalse(App::dispatchTaskCallback([1, 2]));         // 2-arg without object
        $this->assertFalse(App::dispatchTaskCallback([1, 2, 3, 4]));  // 4-arg
        $this->assertFalse(App::dispatchTaskCallback([]));             // empty
    }

    public function testTwoArgFormWithNonObjectIsRejected(): void
    {
        // Scalar in position 0 is not a Task; dispatcher returns false.
        $this->assertFalse(App::dispatchTaskCallback(['not-an-object']));
    }

    public function testNonArrayPayloadReturnsFalse(): void
    {
        $task = new \stdClass();
        $task->data = 'not an array';
        $this->assertFalse(App::dispatchTaskCallback([$task]));
    }

    public function testMissingHandlerKeyReturnsFalse(): void
    {
        $task = new \stdClass();
        $task->data = ['args' => ['x']];  // no 'handler'
        $this->assertFalse(App::dispatchTaskCallback([$task]));
    }

    public function testNonArrayArgsReturnsFalse(): void
    {
        $task = new \stdClass();
        $task->data = ['handler' => '/task/echo', 'args' => 'not-an-array'];
        $this->assertFalse(App::dispatchTaskCallback([$task]));
    }

    public function testUnknownHandlerFileReturnsTaskShapeWithFalseResult(): void
    {
        // Missing handler file is non-fatal — wrapper still returns the
        // task envelope, with result=false. Keeps the OpenSwoole 'finish'
        // callback happy.
        $task = new \stdClass();
        $task->data = ['handler' => '/task/does-not-exist', 'args' => []];
        $result = App::dispatchTaskCallback([$task]);
        $this->assertIsArray($result);
        $this->assertSame($task->data, $result['task']);
        $this->assertFalse($result['result']);
    }

    // ── data flow: args from the task payload reach the handler ─────

    public function testHandlerReceivesArgsInOrder(): void
    {
        $task = new \stdClass();
        $task->data = ['handler' => '/task/echo', 'args' => ['a', 'b', 'c']];
        $result = App::dispatchTaskCallback([$task]);
        $this->assertSame(['echoed' => ['a', 'b', 'c']], $result['result']);
    }

    public function testHandlerWithNoArgsRunsCleanly(): void
    {
        $task = new \stdClass();
        $task->data = ['handler' => '/task/echo', 'args' => []];
        $result = App::dispatchTaskCallback([$task]);
        $this->assertSame(['echoed' => []], $result['result']);
    }
}
