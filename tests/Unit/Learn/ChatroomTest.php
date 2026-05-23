<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Learn;

use PHPUnit\Framework\TestCase;
use ZealPHP\Learn\Chatroom;
use ZealPHP\Learn\DB;

/**
 * Lesson 22 — multi-room group chat model.
 *
 * Each test runs against a fresh in-memory SQLite by pointing
 * ZEALPHP_LEARN_DB_PATH at a tempfile + flushing the DB cache between
 * tests so schemas land clean.
 */
final class ChatroomTest extends TestCase
{
    private string $prevDb = '';
    private string $tmpDb  = '';

    protected function setUp(): void
    {
        $prev = getenv('ZEALPHP_LEARN_DB_PATH');
        $this->prevDb = is_string($prev) ? $prev : '';
        $this->tmpDb = sys_get_temp_dir() . '/zealphp-chatroom-test-' . bin2hex(random_bytes(4)) . '.db';
        putenv('ZEALPHP_LEARN_DB_PATH=' . $this->tmpDb);

        $r = new \ReflectionProperty(DB::class, 'cache');
        $r->setAccessible(true);
        $r->setValue(null, []);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDb !== '' && file_exists($this->tmpDb)) {
            @unlink($this->tmpDb);
        }
        if ($this->prevDb !== '') {
            putenv('ZEALPHP_LEARN_DB_PATH=' . $this->prevDb);
        } else {
            putenv('ZEALPHP_LEARN_DB_PATH');
        }
        $r = new \ReflectionProperty(DB::class, 'cache');
        $r->setAccessible(true);
        $r->setValue(null, []);
    }

    public function testSaveAndRecallSingleMessage(): void
    {
        $row = Chatroom::saveMessage('general', 'alice', 'first message');
        self::assertGreaterThan(0, $row['id']);
        self::assertSame('general', $row['room']);
        self::assertSame('alice', $row['username']);
        self::assertSame('first message', $row['body']);
        self::assertSame('message', $row['kind']);

        $recent = Chatroom::recent('general');
        self::assertCount(1, $recent);
        self::assertSame('first message', $recent[0]['body']);
    }

    public function testRecentReturnsChronologicalOrder(): void
    {
        Chatroom::saveMessage('general', 'alice', '1');
        Chatroom::saveMessage('general', 'bob',   '2');
        Chatroom::saveMessage('general', 'carol', '3');

        $recent = Chatroom::recent('general');
        self::assertSame(['1', '2', '3'], array_column($recent, 'body'));
    }

    public function testRoomsAreIsolated(): void
    {
        Chatroom::saveMessage('engineering', 'alice', 'hello eng');
        Chatroom::saveMessage('random',      'bob',   'hello random');

        self::assertSame(['hello eng'],    array_column(Chatroom::recent('engineering'), 'body'));
        self::assertSame(['hello random'], array_column(Chatroom::recent('random'), 'body'));
        self::assertSame([],               Chatroom::recent('nonexistent'));
    }

    public function testTailLimit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            Chatroom::saveMessage('general', 'alice', "msg $i");
        }
        self::assertCount(10, Chatroom::recent('general', 10));
        self::assertCount(50, Chatroom::recent('general', 50));
        self::assertCount(60, Chatroom::recent('general', 100));   // less than total
    }

    public function testSystemKind(): void
    {
        $row = Chatroom::saveMessage('general', 'alice', 'joined #general', 'system');
        self::assertSame('system', $row['kind']);
        self::assertSame('system', Chatroom::recent('general')[0]['kind']);
    }

    public function testEmptyBodyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Chatroom::saveMessage('general', 'alice', '   ');
    }

    public function testListRoomsShowsActivityOrder(): void
    {
        Chatroom::saveMessage('older',   'alice', 'msg');
        usleep(1_100_000); // 1.1s — ensures created_at increments at second granularity
        Chatroom::saveMessage('newer',   'bob',   'msg');

        $rooms = Chatroom::listRooms();
        self::assertCount(2, $rooms);
        self::assertSame('newer', $rooms[0]['room'], 'most-recent room first');
        self::assertSame('older', $rooms[1]['room']);
    }

    public function testUsernameAndBodyTrimmed(): void
    {
        $row = Chatroom::saveMessage('general', str_repeat('a', 100), str_repeat('b', 3000));
        self::assertLessThanOrEqual(32,   mb_strlen($row['username']));
        self::assertLessThanOrEqual(2000, mb_strlen($row['body']));
    }

    public function testDefaultsForEmptyRoomAndUsername(): void
    {
        $row = Chatroom::saveMessage('', '', 'hi');
        self::assertSame('general',   $row['room']);
        self::assertSame('anonymous', $row['username']);
    }
}
