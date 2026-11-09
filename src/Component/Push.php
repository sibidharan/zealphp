<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\RequestContext;
use ZealPHP\SafeHtml;

/**
 * Server push for reactive components: when data a component depends on
 * changes, the open pages showing it re-render it.
 *
 * - A component lists the topics it depends on in `topics()`.
 * - `Component::invalidate('contacts')` after a write marks those topics stale.
 * - Each open page holds one connection (a WebSocket by default, or SSE) and
 *   receives the ids of its stale components — never HTML or data. The page
 *   then re-requests each one through the normal endpoint, with its own
 *   session and auth, so one visitor's view never reaches another.
 *
 * Subscriptions come from the server, not the client: the components a page
 * rendered (with that visitor's session) are signed into a token the push
 * connection presents. A client can't subscribe to a topic it wasn't shown.
 *
 * Delivery: the worker that calls invalidate() delivers to its own
 * connections and sends the topics to every other worker on the node
 * (OpenSwoole `sendMessage`); with the Redis Store backend it also publishes
 * them to other nodes. Messages to one connection are coalesced.
 *
 * @internal Use App::componentPush(), App::componentPushRoot(),
 *           Component::topics() and Component::invalidate().
 */
final class Push
{
    public const STREAM = '/_zeal/c/stream';
    public const SOCKET = '/_zeal/c/ws';
    public const CLIENT = '/_zeal/c/client.js';
    public const PIPE_TYPE = 'zc.invalidate';
    public const CHANNEL = 'zeal:components:invalidate';

    /** What a deferred componentPushRoot() prints; replaced when the response is finished. */
    public const ROOT_MARKER = '<!--zealphp:push-root-->';

    private const PURPOSE = 'component-push';
    private const TOPIC = '/^[A-Za-z0-9:_.\-]{1,128}$/D';

    /** 'ws' or 'sse'; null follows ZEALPHP_COMPONENT_PUSH, then 'ws'. */
    private static ?string $transport = null;
    /** Seconds between delivery batches to one connection. */
    private static float $coalesce = 0.05;
    /** Upper bound on open push connections per worker. */
    private static int $maxConnections = 1000;

    private static ?PushState $state = null;

    private static function state(): PushState
    {
        return self::$state ??= new PushState();
    }

    // ── configuration ─────────────────────────────────────────────

    /**
     * @param string|null $transport 'sse' or 'ws' (validated: callers pass config values)
     */
    public static function configure(?string $transport = null, ?int $maxConnections = null, ?float $coalesce = null): void
    {
        if ($transport !== null) {
            if ($transport !== 'sse' && $transport !== 'ws') {
                throw new \InvalidArgumentException("Component push transport must be 'sse' or 'ws'");
            }
            self::$transport = $transport;
        }
        if ($maxConnections !== null) {
            if ($maxConnections < 1) {
                throw new \InvalidArgumentException('maxConnections must be at least 1');
            }
            self::$maxConnections = $maxConnections;
        }
        if ($coalesce !== null) {
            if ($coalesce < 0) {
                throw new \InvalidArgumentException('coalesce must be >= 0 seconds');
            }
            self::$coalesce = $coalesce;
        }
    }

    public static function transport(): string
    {
        if (self::$transport !== null) {
            return self::$transport;
        }
        return getenv('ZEALPHP_COMPONENT_PUSH') === 'sse' ? 'sse' : 'ws';
    }

    public static function reset(): void
    {
        self::$state = null;
        self::$transport = null;
        self::$coalesce = 0.05;
        self::$maxConnections = 1000;
    }

    // ── what a page rendered ──────────────────────────────────────

    /**
     * Note a reactive component this request rendered, with its topics.
     *
     * @param list<string> $topics
     */
    public static function recordRendered(string $id, string $name, array $topics): void
    {
        $valid = [];
        foreach ($topics as $topic) {
            if (preg_match(self::TOPIC, $topic) === 1) {
                $valid[] = $topic;
            } elseif (App::devReload()) {
                self::assertTopic($topic); // throws: a bad topic is a bug, show it in dev
            } else {
                \ZealPHP\elog("[components] push: ignoring invalid topic '{$topic}' of {$name}", 'warn');
            }
        }
        $topics = $valid;
        if ($topics === []) {
            return;
        }
        $g = RequestContext::instance();
        $seen = $g->memo['_zc_push'] ?? [];
        $seen = is_array($seen) ? $seen : [];
        $seen[$id] = ['n' => $name, 't' => array_values(array_unique($topics))];
        $g->memo['_zc_push'] = $seen;
    }

    /**
     * The push root for the page: the script that opens the connection.
     *
     * In a buffered response it prints a placeholder that finish() replaces
     * with the script for every component the response rendered, so it can sit
     * anywhere in the layout. In a stream, or outside a request, it prints the
     * script for the components rendered so far. A page gets at most one
     * script: the client keeps one connection per page.
     */
    public static function root(): SafeHtml
    {
        $g = RequestContext::instance();
        if (($g->memo['_zc_push_defer'] ?? false) === true && !($g->_streaming ?? false)) {
            $g->memo['_zc_push_root'] = true;
            return new SafeHtml(self::ROOT_MARKER);
        }
        return new SafeHtml(self::takeScript());
    }

    /**
     * Start of a request (ResponseMiddleware): componentPushRoot() prints a
     * placeholder, filled in by finish().
     *
     * @internal
     */
    public static function deferRoot(): void
    {
        RequestContext::instance()->memo['_zc_push_defer'] = true;
    }

    /**
     * End of a buffered response (ResponseMiddleware): put the push script
     * where componentPushRoot() was called, or, when the layout didn't call
     * it, before `</body>`. Responses without `</body>` (htmx fragments,
     * component refreshes, JSON), non-HTML responses, and bodies already
     * compressed or cut to a range (204/206/304, Content-Encoding,
     * Content-Range) are left alone.
     * Costs two array lookups when the page rendered no component with topics.
     *
     * @internal
     */
    public static function finish(\Psr\Http\Message\ResponseInterface $response): \Psr\Http\Message\ResponseInterface
    {
        $g = RequestContext::instance();
        $g->memo['_zc_push_defer'] = false;
        if (!self::pending() && ($g->memo['_zc_push_root'] ?? false) !== true) {
            return $response;
        }
        if (($g->_streaming ?? false) || !self::isHtml($response->getHeaderLine('Content-Type'))) {
            return $response;
        }
        // A body that middleware inside the router compressed or cut to a range
        // can't be edited: inserting bytes would corrupt it.
        if ($response->hasHeader('Content-Encoding') || $response->hasHeader('Content-Range')
            || in_array($response->getStatusCode(), [204, 206, 304], true)) {
            if (self::pending()) {
                \ZealPHP\elog('[components] push: script not added to a ' . $response->getStatusCode()
                    . ' response with Content-Encoding/Content-Range set inside the router; register compression globally', 'debug');
            }
            return $response;
        }
        $body = (string) $response->getBody();
        $new = self::place($body);
        if ($new === $body) {
            return $response;
        }
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return $response;
        }
        fwrite($stream, $new);
        fseek($stream, 0);
        $response = $response->withBody(new \OpenSwoole\Core\Psr\Stream($stream));
        return $response->hasHeader('Content-Length')
            ? $response->withHeader('Content-Length', (string) strlen($new))
            : $response;
    }

    /**
     * A chunk about to be streamed: put the push script in it when it holds
     * the placeholder or `</body>` and the page hasn't got one yet.
     *
     * @internal
     */
    public static function spliceStream(string $chunk): string
    {
        if (!self::pending() && !str_contains($chunk, self::ROOT_MARKER)) {
            return $chunk;
        }
        return self::place($chunk);
    }

    /**
     * Stop deferring: a stream is starting, so componentPushRoot() must print
     * the script itself.
     *
     * @internal
     */
    public static function streaming(): void
    {
        RequestContext::instance()->memo['_zc_push_defer'] = false;
    }

    /**
     * The script goes where the first placeholder is (other placeholders are
     * removed), else before the last `</body>`.
     */
    private static function place(string $html): string
    {
        $at = strpos($html, self::ROOT_MARKER);
        if ($at !== false) {
            $html = substr_replace($html, self::takeScript(), $at, strlen(self::ROOT_MARKER));
            return str_replace(self::ROOT_MARKER, '', $html);
        }
        if (!self::pending()) {
            return $html;
        }
        $at = strripos($html, '</body');
        return $at === false ? $html : substr_replace($html, self::takeScript(), $at, 0);
    }

    /** Components with topics were rendered and no script carries them yet. */
    private static function pending(): bool
    {
        $memo = RequestContext::instance()->memo;
        $seen = $memo['_zc_push'] ?? [];
        return is_array($seen) && $seen !== [] && ($memo['_zc_push_done'] ?? false) !== true;
    }

    /** A Content-Type that is HTML, or none (the default is text/html). */
    private static function isHtml(string $contentType): bool
    {
        if ($contentType === '') {
            $wrapper = RequestContext::instance()->zealphp_response ?? null;
            if ($wrapper instanceof \ZealPHP\HTTP\Response) {
                foreach ($wrapper->headersList as [$name, $value]) {
                    if (strcasecmp($name, 'Content-Type') === 0) {
                        $contentType = $value;
                    }
                }
            }
        }
        return $contentType === '' || str_contains(strtolower($contentType), 'html');
    }

    /**
     * The script for every component recorded so far, once per request;
     * '' when there are none or the page already has it.
     */
    private static function takeScript(): string
    {
        if (!self::pending()) {
            return '';
        }
        $g = RequestContext::instance();
        $g->memo['_zc_push_done'] = true;
        $seen = $g->memo['_zc_push'];
        /** @var array<string, array{n: string, t: list<string>}> $seen */
        $token = self::token($seen);
        return '<script ' . Attr::render([
            'src' => self::CLIENT . '?v=' . self::clientVersion(),
            'defer' => true,
            // The SSE stream needs a coroutine per page; without coroutines, use the socket.
            'data-zc-push' => \OpenSwoole\Coroutine::getCid() < 0 ? 'ws' : self::transport(),
            'data-zc-token' => $token,
            'data-zc-ids' => implode(' ', array_keys($seen)),
        ]) . '></script>';
    }

    /** Changes when client.js does, so a deploy isn't hidden by the browser cache. */
    private static function clientVersion(): string
    {
        return self::$clientVersion ??= substr(hash_file('sha256', __DIR__ . '/client.js') ?: '0', 0, 12);
    }

    private static ?string $clientVersion = null;

    // ── tokens ────────────────────────────────────────────────────

    /**
     * Sign the subscriptions of a page, bound to the visitor's session.
     *
     * @param array<string, array{n: string, t: list<string>}> $subs instance id → {name, topics}
     */
    public static function token(array $subs): string
    {
        $json = json_encode([
            's' => Snapshot::currentBinding(),
            't' => time(),
            'c' => $subs,
        ], JSON_THROW_ON_ERROR);
        $mac = hash_hmac('sha256', $json, AppKey::derive(self::PURPOSE), true);
        return self::b64($json) . '.' . self::b64($mac);
    }

    /**
     * Verify a token from a push connection.
     *
     * @return array<string, list<string>> instance id → topics
     * Tokens don't expire on their own: a push carries only ids, and every
     * refresh re-checks the snapshot (including its age) and authorize().
     *
     * @throws ComponentException 400 malformed/tampered, 409 another session or a retired key
     */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($token) > 16384) {
            throw ComponentException::badRequest('Malformed push token');
        }
        $json = self::unb64($parts[0]);
        $mac = self::unb64($parts[1]);
        if ($json === null || $mac === null) {
            throw ComponentException::badRequest('Malformed push token');
        }
        $valid = false;
        foreach (AppKey::deriveAll(self::PURPOSE) as $key) {
            if (hash_equals(hash_hmac('sha256', $json, $key, true), $mac)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw AppKey::isEphemeral()
                ? ComponentException::stale('Push token was signed before a restart')
                : ComponentException::badRequest('Push token signature is invalid');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['s'] ?? null) || !is_int($data['t'] ?? null) || !is_array($data['c'] ?? null)) {
            throw ComponentException::badRequest('Malformed push token');
        }
        if (!Snapshot::bindingMatches($data['s'])) {
            throw ComponentException::stale('Push token belongs to another session');
        }
        $subs = [];
        foreach ($data['c'] as $id => $sub) {
            if (!is_string($id) || !is_array($sub) || !is_array($sub['t'] ?? null)) {
                throw ComponentException::badRequest('Malformed push token');
            }
            $topics = array_values(array_filter($sub['t'], static fn($t): bool => is_string($t) && preg_match(self::TOPIC, $t) === 1));
            if ($topics !== []) {
                $subs[$id] = $topics;
            }
        }
        return $subs;
    }

    // ── connections (per worker) ──────────────────────────────────

    /**
     * Register a push connection. `$send` writes one JSON message (a list of
     * stale instance ids) to the client and returns false once the client is
     * gone.
     *
     * @param array<string, list<string>> $subs instance id → topics
     * @param \Closure(string): bool $send
     * @throws ComponentException 503 when the worker is at its connection limit
     */
    public static function connect(string $connId, array $subs, \Closure $send): void
    {
        self::disconnect($connId); // an id is never registered twice
        $state = self::state();
        if (count($state->connections) >= self::$maxConnections) {
            throw new ComponentException('Too many push connections on this worker', 503);
        }
        $topics = [];
        foreach ($subs as $id => $list) {
            foreach ($list as $topic) {
                $topics[$topic][] = $id;
                $state->byTopic[$topic][$connId] = true;
            }
        }
        $state->connections[$connId] = ['send' => $send, 'topics' => $topics, 'pending' => [], 'scheduled' => false];
    }

    public static function disconnect(string $connId): void
    {
        $state = self::state();
        $conn = $state->connections[$connId] ?? null;
        if ($conn === null) {
            return;
        }
        foreach (array_keys($conn['topics']) as $topic) {
            self::unsubscribe($state, $topic, $connId);
        }
        unset($state->connections[$connId]);
    }

    public static function connectionCount(): int
    {
        return count(self::state()->connections);
    }

    private static function unsubscribe(PushState $state, string $topic, string $connId): void
    {
        unset($state->byTopic[$topic][$connId]);
        if (($state->byTopic[$topic] ?? []) === []) {
            unset($state->byTopic[$topic]);
        }
    }

    // ── invalidation ──────────────────────────────────────────────

    /**
     * Mark topics stale everywhere: this worker, the node's other workers,
     * and (Redis backend) other nodes.
     */
    public static function invalidate(string ...$topics): void
    {
        $topics = array_values(array_unique($topics));
        if ($topics === []) {
            return;
        }
        foreach ($topics as $topic) {
            self::assertTopic($topic);
        }
        self::deliverLocal($topics);
        $state = self::state();
        foreach ($topics as $topic) {
            $state->outbox[$topic] = true;
        }
        // Batch what this worker invalidates in one tick into one message per
        // worker and one publish, so a loop of writes doesn't fan out per row.
        if (\OpenSwoole\Coroutine::getCid() < 0) {
            self::sendOutbox();
        } elseif (!$state->outboxScheduled) {
            $state->outboxScheduled = true;
            \OpenSwoole\Timer::after(1, static function (): void {
                self::sendOutbox();
            });
        }
    }

    /** Send the batched topics to the node's other workers and to other nodes. */
    public static function sendOutbox(): void
    {
        $payload = self::drainOutbox();
        if ($payload !== null) {
            self::fanOutToWorkers($payload);
            self::fanOutToNodes($payload);
        }
    }

    /** Empty the outbox into one message, or null when nothing is waiting. */
    public static function drainOutbox(): ?string
    {
        $state = self::state();
        $topics = array_map('strval', array_keys($state->outbox));
        $state->outbox = [];
        $state->outboxScheduled = false;
        if ($topics === []) {
            return null;
        }
        return json_encode(['topics' => $topics, 'origin' => self::origin()], JSON_THROW_ON_ERROR);
    }

    /**
     * Handle topics invalidated on another worker or node.
     */
    public static function receive(string $payload): void
    {
        $data = json_decode($payload, true);
        if (!is_array($data) || !is_array($data['topics'] ?? null)) {
            return;
        }
        $origin = $data['origin'] ?? null;
        $topics = array_values(array_filter($data['topics'], static fn($t): bool => is_string($t) && preg_match(self::TOPIC, $t) === 1));
        // A node's own Redis message comes back to it; its workers already
        // received the topics by pipe.
        if ($origin !== null && $origin === self::origin() && ($data['via'] ?? '') === 'redis') {
            return;
        }
        self::deliverLocal($topics);
    }

    /**
     * Queue the stale ids for every local connection subscribed to $topics.
     *
     * @param list<string> $topics
     */
    public static function deliverLocal(array $topics): void
    {
        $state = self::state();
        foreach ($topics as $topic) {
            foreach (array_keys($state->byTopic[$topic] ?? []) as $connId) {
                $connId = (string) $connId;
                if (!isset($state->connections[$connId])) {
                    self::unsubscribe($state, $topic, $connId); // left behind by a dropped connection
                    continue;
                }
                foreach ($state->connections[$connId]['topics'][$topic] ?? [] as $id) {
                    $state->connections[$connId]['pending'][$id] = true;
                }
                if (!$state->connections[$connId]['scheduled']) {
                    $state->connections[$connId]['scheduled'] = true;
                    self::schedule($connId);
                }
            }
        }
    }

    /** Send a connection's pending ids now. */
    public static function flush(string $connId): void
    {
        $state = self::state();
        $conn = $state->connections[$connId] ?? null;
        if ($conn === null) {
            return;
        }
        $ids = array_keys($conn['pending']);
        $state->connections[$connId]['pending'] = [];
        $state->connections[$connId]['scheduled'] = false;
        if ($ids === []) {
            return;
        }
        $ok = ($conn['send'])((string) json_encode($ids, JSON_UNESCAPED_SLASHES));
        if (!$ok) {
            self::disconnect($connId);
        }
    }

    private static function schedule(string $connId): void
    {
        if (self::$coalesce <= 0 || \OpenSwoole\Coroutine::getCid() < 0) {
            self::flush($connId);
            return;
        }
        \OpenSwoole\Timer::after(self::delayMs(self::$coalesce), static function () use ($connId): void {
            self::flush($connId);
        });
    }

    /** A coalesce window in whole milliseconds for Timer::after(), at least 1. */
    public static function delayMs(float $seconds): int
    {
        return max(1, (int) round($seconds * 1000));
    }

    private static function fanOutToWorkers(string $payload): void
    {
        $server = App::getServer();
        if (!$server instanceof \OpenSwoole\Server) {
            return;
        }
        $self = $server->worker_id;
        $setting = $server->setting;
        $workers = is_array($setting) && is_int($setting['worker_num'] ?? null) ? $setting['worker_num'] : 1;
        $message = (string) json_encode(['zc' => self::PIPE_TYPE, 'payload' => $payload]);
        // From an HTTP worker, the others; from a task worker, all of them
        // (push connections only live on HTTP workers).
        for ($w = 0; $w < $workers; $w++) {
            if ($w !== $self) {
                $server->sendMessage($message, $w);
            }
        }
    }

    private static function fanOutToNodes(string $payload): void
    {
        if (!self::nodesShareRedis()) {
            return;
        }
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return;
        }
        $data['via'] = 'redis';
        try {
            \ZealPHP\Store::publish(self::CHANNEL, (string) json_encode($data));
        } catch (\Throwable $e) {
            \ZealPHP\elog('[components] push: could not publish to other nodes: ' . $e->getMessage(), 'warn');
        }
    }

    /** Whether the Store backend reaches other nodes (Redis or Tiered). */
    public static function nodesShareRedis(): bool
    {
        $b = \ZealPHP\Store::defaultBackend();
        return $b instanceof \ZealPHP\Store\RedisBackend || $b instanceof \ZealPHP\Store\TieredBackend;
    }

    /** This node in cross-node messages: host + the server's master pid (shared by its workers). */
    private static function origin(): string
    {
        if (self::$origin === null) {
            $server = App::getServer();
            $master = $server instanceof \OpenSwoole\Server && is_int($server->master_pid) ? $server->master_pid : 0;
            self::$origin = gethostname() . ':' . ($master > 0 ? $master : getmypid());
        }
        return self::$origin;
    }

    private static ?string $origin = null;

    private static function assertTopic(string $topic): void
    {
        if (preg_match(self::TOPIC, $topic) !== 1) {
            throw new \InvalidArgumentException("Invalid component topic '{$topic}': use 1-128 letters, digits, ':', '_', '.' or '-'");
        }
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        // Strict mode refuses anything outside the alphabet; the MAC decides the rest.
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        return $raw === false || $raw === '' ? null : $raw;
    }
}
