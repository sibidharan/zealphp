<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\HTTP\Response;
use ZealPHP\Tests\TestCase;
use ZealPHP\Learn\Auth;
use ZealPHP\Learn\Chat;
use ZealPHP\Learn\ChatHistory;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Notes;

/**
 * Characterization tests for ZealPHP\Learn\Chat.
 *
 * Chat::mock() drives the whole SSE flow synchronously when handed a Response
 * subclass whose sse() invokes the callback with a capturing $emit closure —
 * no live AI backend, no subprocess, no network.
 *
 * Chat::real() is exercised only up to (not including) the proc_open() call:
 * a Response subclass whose sse() ignores the callback covers the
 * payload-building prelude (DB read, profile shaping, base64 encoding) without
 * spawning Python.
 */
class LearnChatTest extends TestCase
{
    private string $dbPath;
    private \PDO $db;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $root = defined('ZEALPHP_ROOT') ? constant('ZEALPHP_ROOT') : dirname(__DIR__, 2);
        App::$cwd = is_string($root) ? $root : dirname(__DIR__, 2);
        App::superglobals(true);

        $this->dbPath = sys_get_temp_dir() . '/learn_chat_test_' . uniqid() . '.db';
        putenv('ZEALPHP_LEARN_DB_PATH=' . $this->dbPath);
        $this->db = DB::open();
        $this->userId = (int) Auth::register($this->db, 'alice', 'password123');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $p) {
            if (file_exists($p)) {
                @unlink($p);
            }
        }
        putenv('ZEALPHP_LEARN_DB_PATH');
    }

    /**
     * A Response whose sse() invokes the callback synchronously, capturing every
     * emitted (event, decoded-data) pair into $events.
     *
     * @param array<int, array{event: string, data: mixed}> $events
     */
    private function capturingResponse(array &$events): Response
    {
        // The capturing closure references $events by use(&); the anonymous
        // Response just forwards its sse() callback into it. No parent
        // constructor — no OpenSwoole socket exists in the unit-test process,
        // and sse() is fully overridden so the parent's $parent/$g state is
        // never touched.
        $capture = function (callable $fn) use (&$events): void {
            $emit = function (string $data, string $event = '', string $id = '') use (&$events): void {
                unset($id);
                $events[] = ['event' => $event, 'data' => json_decode($data, true)];
            };
            $fn($emit);
        };
        return new class($capture) extends Response {
            /** @var \Closure(callable): void */
            private \Closure $capture;

            public function __construct(\Closure $capture)
            {
                $this->capture = $capture;
            }

            public function sse(callable $fn): void
            {
                ($this->capture)($fn);
            }
        };
    }

    /**
     * A Response whose sse() records that it was called but never invokes the
     * callback — keeps Chat::real() off proc_open().
     */
    private function nonInvokingResponse(bool &$called): Response
    {
        $mark = function () use (&$called): void {
            $called = true;
        };
        return new class($mark) extends Response {
            /** @var \Closure(): void */
            private \Closure $mark;

            public function __construct(\Closure $mark)
            {
                $this->mark = $mark;
            }

            public function sse(callable $fn): void
            {
                ($this->mark)();
                unset($fn);
            }
        };
    }

    /**
     * @param array<int, array{event: string, data: mixed}> $events
     * @return array<int, string>
     */
    private function eventNames(array $events): array
    {
        return array_map(static fn(array $e): string => $e['event'], $events);
    }

    /** @param array<int, array{event: string, data: mixed}> $events */
    private function joinTokens(array $events): string
    {
        $html = '';
        foreach ($events as $e) {
            if ($e['event'] === 'token' && is_array($e['data']) && isset($e['data']['token'])) {
                $token = $e['data']['token'];
                $html .= is_scalar($token) ? (string) $token : '';
            }
        }
        return $html;
    }

    /** @return array{user_id: int, username: string} */
    private function user(): array
    {
        return ['user_id' => $this->userId, 'username' => 'alice'];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function firstItemsJson(array $rows): string
    {
        $v = $rows[0]['items_json'] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    public function test_mock_emits_thread_and_done_events(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'hello there', 't-main');

        $names = $this->eventNames($events);
        $this->assertSame('thread', $names[0]);
        $this->assertSame('done', $names[count($names) - 1]);

        $first = $events[0]['data'];
        $this->assertIsArray($first);
        $this->assertSame('t-main', $first['thread_id']);
    }

    public function test_mock_persists_user_and_assistant_history(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'hello there', 't-hist');

        $rows = ChatHistory::forThread($this->db, $this->userId, 't-hist');
        $this->assertCount(2, $rows);
        $this->assertSame('user', $rows[0]['role']);
        $this->assertSame('assistant', $rows[1]['role']);
        $this->assertStringContainsString('hello there', $this->firstItemsJson($rows));
    }

    public function test_mock_fallback_message_when_unmatched(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'random unmatched text', 't1');

        $this->assertStringContainsString('Mock mode is active', $this->joinTokens($events));
    }

    public function test_mock_list_notes_empty(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'list notes', 't1');

        $names = $this->eventNames($events);
        $this->assertContains('tool_call', $names);
        $this->assertContains('tool_done', $names);
        $this->assertStringContainsString('No notes yet', $this->joinTokens($events));
    }

    public function test_mock_list_notes_with_existing(): void
    {
        Notes::create($this->db, $this->userId, 'Buy milk', '');
        Notes::create($this->db, $this->userId, 'Pay rent', '');

        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'show all', 't1');

        $html = $this->joinTokens($events);
        $this->assertStringContainsString('Here are your notes', $html);
        $this->assertStringContainsString('Buy milk', $html);
        $this->assertStringContainsString('Pay rent', $html);
    }

    public function test_mock_create_note(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'create a note titled buy milk', 't1');

        $notes = Notes::list($this->db, $this->userId);
        $this->assertCount(1, $notes);
        $this->assertSame('buy milk', $notes[0]['title']);

        $names = $this->eventNames($events);
        $this->assertContains('tool_args', $names);
        $this->assertContains('notes_changed', $names);
        $this->assertStringContainsString('Created note', $this->joinTokens($events));
    }

    public function test_mock_create_note_untitled_when_blank(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        // Trailing whitespace after "titled" leaves an empty capture -> 'untitled'.
        Chat::mock($this->capturingResponse($events), $this->user(), 'create a note titled  ', 't1');

        $notes = Notes::list($this->db, $this->userId);
        $this->assertCount(1, $notes);
        $this->assertSame('untitled', $notes[0]['title']);
    }

    public function test_mock_delete_note_found(): void
    {
        Notes::create($this->db, $this->userId, 'Buy milk', '');

        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'delete buy milk', 't1');

        $this->assertCount(0, Notes::list($this->db, $this->userId));
        $this->assertStringContainsString('Deleted note', $this->joinTokens($events));
        $this->assertContains('notes_changed', $this->eventNames($events));
    }

    public function test_mock_delete_note_not_found(): void
    {
        Notes::create($this->db, $this->userId, 'Buy milk', '');

        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'delete groceries', 't1');

        $this->assertCount(1, Notes::list($this->db, $this->userId));
        $this->assertStringContainsString("couldn't find", $this->joinTokens($events));
    }

    public function test_mock_search_notes_with_hits(): void
    {
        Notes::create($this->db, $this->userId, 'Buy groceries', 'Apples');

        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'search groceries', 't1');

        $this->assertStringContainsString('Buy groceries', $this->joinTokens($events));
    }

    public function test_mock_search_notes_no_hits(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'find nonexistent', 't1');

        $this->assertStringContainsString('No notes match', $this->joinTokens($events));
    }

    public function test_mock_html_escapes_message_in_history(): void
    {
        /** @var array<int, array{event: string, data: mixed}> $events */
        $events = [];
        Chat::mock($this->capturingResponse($events), $this->user(), 'list <script>', 't-esc');

        $rows = ChatHistory::forThread($this->db, $this->userId, 't-esc');
        $this->assertStringContainsString('&lt;script&gt;', $this->firstItemsJson($rows));
    }

    public function test_real_builds_payload_without_spawning_process(): void
    {
        $g = RequestContext::instance();
        $g->server = ['SERVER_PORT' => '9999'];

        Notes::create($this->db, $this->userId, 'Existing note', '');

        $sseCalled = false;
        Chat::real($this->nonInvokingResponse($sseCalled), $this->user(), 'hello model', 't-real', 'sk-test-key');

        $this->assertTrue($sseCalled);
        // The user turn is appended before the SSE stream begins.
        $rows = ChatHistory::forThread($this->db, $this->userId, 't-real');
        $this->assertCount(1, $rows);
        $this->assertSame('user', $rows[0]['role']);
        $this->assertStringContainsString('hello model', $this->firstItemsJson($rows));
    }
}
