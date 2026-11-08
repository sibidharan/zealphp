<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\App;
use ZealPHP\RequestContext;

/**
 * Handles `POST /_zeal/c/{name}/{action}`: verify the snapshot, rebuild the
 * component, apply input, run the action, and re-render.
 *
 * HTTP-agnostic so it can be tested without a server: {@see handle()} takes
 * the form input and CGI-style request variables and returns status, headers
 * and body. The route registered by the framework adapts it to the response.
 *
 * @internal
 */
final class Endpoint
{
    /** Session key holding snapshots for no-JS round trips, by instance id. */
    public const FLASH_KEY = '_zc_flash';
    private const MAX_FLASH = 20;

    /**
     * @param array<string, mixed> $input  form fields (`$g->post`)
     * @param array<string, mixed> $server CGI variables (`$g->server`)
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function handle(string $name, string $action, array $input, array $server): array
    {
        $binder = App::componentBinder();
        $async = $binder->isAsync($server);
        try {
            if (!self::sameOrigin($server)) {
                throw ComponentException::forbidden('Cross-origin component request refused');
            }
            // Without a session the snapshot isn't bound to anyone, so the
            // origin headers are the only CSRF defence: require one of them.
            if (Snapshot::currentBinding() === '' && !self::hasOriginHeader($server)) {
                throw ComponentException::forbidden('Component requests without a session must send Origin or Sec-Fetch-Site');
            }
            $meta = Registry::byName($name);
            if ($meta === null) {
                throw ComponentException::notFound("Unknown component {$name}");
            }
            if ($action !== Component::UPDATE && $action !== Component::REFRESH && !isset($meta->actions[$action])) {
                throw ComponentException::notFound("Unknown action {$action}");
            }
            $wire = $input['_zc'] ?? '';
            $snapshot = Snapshot::decode(is_string($wire) ? $wire : '', $meta);
            $component = Component::zcRestore($meta, $snapshot);
            if (!$component->authorize($action)) {
                throw ComponentException::forbidden("Not allowed: {$action}");
            }

            $changed = $component->zcApplyInput($input);
            $before = $component->errors();
            $outcome = $component->zcBadInput() !== [] ? Outcome::Invalid : Outcome::Ok;
            try {
                if ($action === Component::UPDATE) {
                    // Validate first so errors added by updated*() hooks survive.
                    $component->zcValidateFields($changed);
                    // The client names the changed field in the body (_zc_trigger);
                    // older clients only send it as a header.
                    $named = $input['_zc_trigger'] ?? null;
                    $trigger = is_string($named) && $named !== '' ? $named : $binder->triggerField($server);
                    $component->zcSetTrigger($trigger ?? (count($changed) === 1 ? $changed[0] : null));
                    $component->zcRunUpdatedHooks($changed);
                } else {
                    $component->zcRunUpdatedHooks($changed);
                    // Don't run an action on values that didn't convert.
                    if ($outcome === Outcome::Ok && $action !== Component::REFRESH) {
                        $component->zcCall($action, self::decodeArgs($input['_zc_args'] ?? null));
                    }
                }
            } catch (ValidationException $e) {
                // From validate() in an action or in an updated*() hook.
                $component->zcMergeErrors($e->errors);
                $outcome = Outcome::Invalid;
            }
            // Errors this request added (live validation, addError()) count;
            // old errors carried in the snapshot on untouched fields don't.
            $after = $component->errors();
            if ($outcome === Outcome::Ok && $action !== Component::REFRESH && $after !== [] && $after !== $before) {
                $outcome = Outcome::Invalid;
            }

            $html = $component->zcToHtml();
            $redirect = $component->zcRedirect();

            if (!$async) {
                // No-JS form post: keep the new state for the next page render
                // (Post/Redirect/Get), then send the browser back.
                if ($redirect === null) {
                    self::flash($component);
                }
                return self::seeOther($redirect ?? self::backUrl($server));
            }
            $r = $binder->response($outcome, $component->zcEvents(), $redirect);
            return ['status' => $r['status'], 'headers' => $r['headers'] + self::baseHeaders(), 'body' => $html];
        } catch (ComponentException $e) {
            if ($e->getCode() === 409) {
                if (!$async) {
                    return self::seeOther(self::backUrl($server));
                }
                $r = $binder->response(Outcome::Stale, [], null);
                return ['status' => $r['status'], 'headers' => $r['headers'] + self::baseHeaders(), 'body' => ''];
            }
            return [
                'status' => $e->getCode(),
                'headers' => ['Content-Type' => 'text/plain; charset=utf-8'] + $binder->refusalHeaders() + self::baseHeaders(),
                'body' => $e->getMessage(),
            ];
        }
    }

    /**
     * Take a component's flashed snapshot for this instance id, if a no-JS
     * request left one.
     */
    public static function takeFlash(string $id): ?string
    {
        if (\ZealPHP\Session\zeal_session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $g = RequestContext::instance();
        $flash = $g->session[self::FLASH_KEY] ?? null;
        if (!is_array($flash) || !is_string($flash[$id] ?? null)) {
            return null;
        }
        $wire = $flash[$id];
        unset($flash[$id]);
        if ($flash === []) {
            unset($g->session[self::FLASH_KEY]);
        } else {
            $g->session[self::FLASH_KEY] = $flash;
        }
        return $wire;
    }

    private static function flash(Component $component): void
    {
        $wire = $component->zcWire();
        if ($wire === null || \ZealPHP\Session\zeal_session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $g = RequestContext::instance();
        $flash = $g->session[self::FLASH_KEY] ?? [];
        if (!is_array($flash)) {
            $flash = [];
        }
        unset($flash[$component->id()]);
        $flash[$component->id()] = $wire;
        $g->session[self::FLASH_KEY] = array_slice($flash, -self::MAX_FLASH, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeArgs(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_string($raw) || strlen($raw) > 16384) {
            throw ComponentException::badRequest('Invalid action arguments');
        }
        $args = json_decode($raw, true);
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            throw ComponentException::badRequest('Action arguments must be a JSON object');
        }
        /** @var array<string, mixed> $args */
        return $args;
    }

    /**
     * Refuse cross-site requests: `Sec-Fetch-Site` must be same-origin when
     * present, else `Origin` must match `Host`. Requests with neither header
     * (non-browser clients) pass; they still need a valid, session-bound
     * snapshot.
     *
     * @param array<string, mixed> $server
     */
    public static function sameOrigin(array $server): bool
    {
        $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($site) && $site !== '') {
            return $site === 'same-origin';
        }
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '') {
            return true;
        }
        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($host) || $host === '') {
            return false;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        $originHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $host = strtolower($host);
        if ($originHost === $host) {
            return true;
        }
        // Host may include the default port while Origin omits it, or vice versa.
        $scheme = strtolower($parts['scheme'] ?? '');
        $default = $scheme === 'https' ? ':443' : ($scheme === 'http' ? ':80' : null);
        return $default !== null && ($originHost . $default === $host || $originHost === $host . $default);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function hasOriginHeader(array $server): bool
    {
        foreach (['HTTP_SEC_FETCH_SITE', 'HTTP_ORIGIN'] as $h) {
            if (is_string($server[$h] ?? null) && $server[$h] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The page to return to after a no-JS post: the Referer when it's on this
     * host, else `/`.
     *
     * @param array<string, mixed> $server
     */
    private static function backUrl(array $server): string
    {
        $ref = $server['HTTP_REFERER'] ?? null;
        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($ref) || !is_string($host) || $ref === '') {
            return '/';
        }
        $parts = parse_url($ref);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '/';
        }
        $refHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        if ($refHost !== strtolower($host)) {
            return '/';
        }
        $path = $parts['path'] ?? '/';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return '/';
        }
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function seeOther(string $url): array
    {
        return ['status' => 303, 'headers' => ['Location' => $url] + self::baseHeaders(), 'body' => ''];
    }

    /** @return array<string, string> */
    private static function baseHeaders(): array
    {
        return ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
    }
}
