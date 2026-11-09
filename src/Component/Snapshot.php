<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\AppKey;
use ZealPHP\RequestContext;

/**
 * The signed state of one component instance, carried in the page.
 *
 * Wire form: `base64url(json) . "." . base64url(hmac_sha256(json))`, where the
 * JSON is `{"c": name, "id": instance id, "p": props, "v": version,
 * "s": session binding, "f": bound fields rendered, "e": validation errors,
 * "t": issued-at}` and the HMAC key is derived from `ZEALPHP_APP_KEY`.
 *
 * A snapshot proves the state was rendered for this session at time `t` — not
 * that it's the latest one. A client can re-send an older snapshot until it
 * expires ({@see maxAge()}), so keep authoritative state (votes, attempts,
 * balances) in the database or session, not in component props.
 *
 * Props are signed, not encrypted: anyone who can see the page can read
 * them. Never put secrets in component state.
 *
 * @internal
 */
final class Snapshot
{
    private const PURPOSE = 'component-snapshot';
    private const MAX_BYTES = 65536;

    /** Seconds a snapshot stays valid; 0 = no limit. */
    private static int $maxAge = 86400;

    /**
     * @param array<string, mixed>  $props  JSON-safe values
     * @param list<string>          $fields the #[Model] fields the last render emitted controls for
     * @param array<string, string> $errors
     */
    public function __construct(
        public readonly string $name,
        public readonly string $id,
        public readonly array $props,
        public readonly string $version,
        public readonly string $binding,
        public readonly array $fields = [],
        public readonly array $errors = [],
        public readonly int $issuedAt = 0,
    ) {
    }

    /**
     * How long a snapshot stays valid, in seconds (default 24 hours; 0 = no
     * limit). An expired snapshot is answered like one from an older build:
     * the page refreshes. Pass a value to set it; call with none to read it.
     */
    public static function maxAge(?int $seconds = null): int
    {
        if ($seconds !== null) {
            if ($seconds < 0) {
                throw new \InvalidArgumentException('Snapshot max age must be >= 0');
            }
            self::$maxAge = $seconds;
        }
        return self::$maxAge;
    }

    public function encode(): string
    {
        $json = json_encode([
            'c' => $this->name,
            'id' => $this->id,
            'p' => $this->props === [] ? new \stdClass() : $this->props,
            'v' => $this->version,
            's' => $this->binding,
            'f' => $this->fields,
            'e' => $this->errors === [] ? new \stdClass() : $this->errors,
            't' => $this->issuedAt > 0 ? $this->issuedAt : time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $mac = hash_hmac('sha256', $json, AppKey::derive(self::PURPOSE), true);
        $wire = self::b64($json) . '.' . self::b64($mac);
        // decode() refuses anything bigger, so refuse it here too rather than
        // put an unusable snapshot in the page (or the session flash).
        if (strlen($wire) > self::MAX_BYTES) {
            throw ComponentException::badRequest('Component state is too large (limit ' . self::MAX_BYTES . ' bytes); validate input sizes');
        }
        return $wire;
    }

    /**
     * Verify and parse a snapshot from the request.
     *
     * @throws ComponentException 400 malformed/tampered, 403 other session, 409 other build
     */
    public static function decode(string $wire, ComponentMeta $meta): self
    {
        if ($wire === '' || strlen($wire) > self::MAX_BYTES) {
            throw ComponentException::badRequest('Missing or oversized component snapshot');
        }
        $parts = explode('.', $wire);
        if (count($parts) !== 2) {
            throw ComponentException::badRequest('Malformed component snapshot');
        }
        $json = self::unb64($parts[0]);
        $mac = self::unb64($parts[1]);
        if ($json === null || $mac === null) {
            throw ComponentException::badRequest('Malformed component snapshot');
        }
        $valid = false;
        foreach (AppKey::deriveAll(self::PURPOSE) as $key) {
            if (hash_equals(hash_hmac('sha256', $json, $key, true), $mac)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            // A dev-mode key changes on every restart: reload the page rather
            // than leave dead buttons. With a configured key it's tampering.
            if (AppKey::isEphemeral()) {
                throw ComponentException::stale('Component snapshot was signed before a restart');
            }
            throw ComponentException::badRequest('Component snapshot signature is invalid');
        }

        $data = json_decode($json, true);
        if (!is_array($data)
            || !is_string($data['c'] ?? null) || !is_string($data['id'] ?? null)
            || !is_array($data['p'] ?? null) || !is_string($data['v'] ?? null)
            || !is_string($data['s'] ?? null)
            || !is_array($data['f'] ?? null) || !is_array($data['e'] ?? null)
            || !is_int($data['t'] ?? null)) {
            throw ComponentException::badRequest('Malformed component snapshot');
        }
        if ($data['c'] !== $meta->name) {
            throw ComponentException::badRequest('Snapshot belongs to another component');
        }
        if (!self::bindingMatches($data['s'])) {
            // Login (session_regenerate_id) or a session starting after the
            // page rendered: reload so the page re-renders for this session.
            // A snapshot from someone else's session is refused the same way —
            // the reload shows the visitor their own state, never the other's.
            throw ComponentException::stale('Snapshot belongs to another session');
        }
        if ($data['v'] !== $meta->version) {
            throw ComponentException::stale('Snapshot is from another version of this component');
        }
        if (self::$maxAge > 0 && $data['t'] < time() - self::$maxAge) {
            throw ComponentException::stale('Snapshot has expired');
        }
        /** @var array<string, mixed> $props */
        $props = $data['p'];
        $fields = array_values(array_filter($data['f'], 'is_string'));
        $errors = [];
        foreach ($data['e'] as $field => $message) {
            if (is_string($field) && is_string($message)) {
                $errors[$field] = $message;
            }
        }
        return new self($data['c'], $data['id'], $props, $data['v'], $data['s'], $fields, $errors, $data['t']);
    }

    /**
     * A value tying a snapshot to the current session: an HMAC of the session
     * id, or '' when no session is active. Never the raw id, so the page
     * doesn't expose it.
     */
    public static function currentBinding(): string
    {
        $sid = self::sessionId();
        if ($sid === '') {
            return '';
        }
        return self::bindingFor($sid, AppKey::derive('component-session'));
    }

    /** Whether a binding is this session's, under the current or a previous key. */
    public static function bindingMatches(string $binding): bool
    {
        $sid = self::sessionId();
        if ($sid === '') {
            return hash_equals('', $binding);
        }
        foreach (AppKey::deriveAll('component-session') as $key) {
            if (hash_equals(self::bindingFor($sid, $key), $binding)) {
                return true;
            }
        }
        return false;
    }

    private static function bindingFor(string $sid, string $key): string
    {
        return self::b64(substr(hash_hmac('sha256', $sid, $key, true), 0, 16));
    }

    private static function sessionId(): string
    {
        $g = RequestContext::instance();
        if (\ZealPHP\Session\zeal_session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        // session_params['session_id'] is the canonical current id (kept in
        // sync by session_regenerate_id()); the cookie can be stale.
        $sid = $g->session_params['session_id'] ?? null;
        if (is_string($sid) && $sid !== '') {
            return $sid;
        }
        $name = $g->session_params['name'] ?? 'PHPSESSID';
        $sid = is_string($name) ? ($g->cookie[$name] ?? '') : '';
        return is_string($sid) ? $sid : '';
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $s) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }
}
