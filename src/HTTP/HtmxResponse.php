<?php
declare(strict_types=1);

namespace ZealPHP\HTTP;

/**
 * Fluent builder for htmx response headers (HX-*).
 *
 * Obtain via Response::htmx() and chain setter calls; the headers are queued
 * into the parent Response's headersList and emitted on the next flush().
 *
 *   $response->htmx()->retarget('#alerts')->reswap('afterbegin')->trigger('itemSaved');
 *
 * All HX-* header values are validated for CRLF/NUL injection before queuing;
 * invalid values trigger an E_USER_WARNING and are silently dropped (matching
 * the behaviour of Response::header()).
 */
class HtmxResponse
{
    private Response $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    // ---- Navigation / history ----------------------------------------------

    /**
     * HX-Push-Url — push a new URL onto the browser history stack.
     * Pass `false` (as string "false") to prevent pushing.
     */
    public function pushUrl(string $url): static
    {
        $this->emit('HX-Push-Url', $url);
        return $this;
    }

    /**
     * HX-Replace-Url — replace the current URL in the location bar without
     * adding a history entry. Pass `false` (as string "false") to prevent.
     */
    public function replaceUrl(string $url): static
    {
        $this->emit('HX-Replace-Url', $url);
        return $this;
    }

    /**
     * HX-Redirect — client-side redirect (no full page reload).
     */
    public function redirect(string $url): static
    {
        $this->emit('HX-Redirect', $url);
        return $this;
    }

    /**
     * HX-Location — client-side redirect that does not do a full page reload.
     * Accepts a URL string or a JSON-encoded location object
     * (e.g. `{"path":"/page","target":"#content"}`).
     */
    public function location(string $urlOrJson): static
    {
        $this->emit('HX-Location', $urlOrJson);
        return $this;
    }

    // ---- Swap control ------------------------------------------------------

    /**
     * HX-Reswap — override the swap strategy declared on the triggering element.
     * Valid values: innerHTML, outerHTML, beforebegin, afterbegin, beforeend,
     * afterend, delete, none (and modifier suffixes such as `innerHTML swap:1s`).
     */
    public function reswap(string $strategy): static
    {
        $this->emit('HX-Reswap', $strategy);
        return $this;
    }

    /**
     * HX-Retarget — CSS selector that redirects the swap to a different element.
     */
    public function retarget(string $selector): static
    {
        $this->emit('HX-Retarget', $selector);
        return $this;
    }

    /**
     * HX-Reselect — CSS selector choosing which part of the response body
     * is swapped in (overrides an existing hx-select on the element).
     */
    public function reselect(string $selector): static
    {
        $this->emit('HX-Reselect', $selector);
        return $this;
    }

    // ---- Page control ------------------------------------------------------

    /**
     * HX-Refresh — set to "true" to trigger a full client-side page refresh.
     */
    public function refresh(bool $refresh = true): static
    {
        $this->emit('HX-Refresh', $refresh ? 'true' : 'false');
        return $this;
    }

    // ---- Event triggering --------------------------------------------------

    /**
     * HX-Trigger — trigger one or more client-side events after the swap.
     *
     * Pass a single event name, a comma-separated list, or a JSON object for
     * events with detail data (e.g. `{"showMessage":{"level":"info"}}`).
     */
    public function trigger(string $events): static
    {
        $this->emit('HX-Trigger', $events);
        return $this;
    }

    /**
     * HX-Trigger-After-Swap — same as trigger() but fires after the swap step.
     * htmx 2 only: htmx 4 ignores this header (use trigger()).
     */
    public function triggerAfterSwap(string $events): static
    {
        $this->emit('HX-Trigger-After-Swap', $events);
        return $this;
    }

    /**
     * HX-Trigger-After-Settle — same as trigger() but fires after the settle step.
     * htmx 2 only: htmx 4 ignores this header (use trigger()).
     */
    public function triggerAfterSettle(string $events): static
    {
        $this->emit('HX-Trigger-After-Settle', $events);
        return $this;
    }

    /**
     * HX-Trigger — trigger a single named client-side event carrying a detail
     * payload, without hand-encoding the JSON object form yourself.
     *
     * `triggerJSON('showMessage', ['level' => 'info', 'message' => 'Saved!'])`
     * is shorthand for `trigger('{"showMessage":{"level":"info","message":"Saved!"}}')`.
     * The browser receives `event.detail` = the decoded `$detail` array, the
     * htmx convention for passing structured data to an event listener.
     *
     * @param array<string, mixed> $detail Detail payload — becomes `event.detail`.
     */
    public function triggerJSON(string $event, array $detail): static
    {
        return $this->trigger((string) json_encode([$event => $detail]));
    }

    // ---- Out-of-band helper ------------------------------------------------

    /**
     * Wrap $html in an OOB swap element.
     *
     * Generates a fragment like `<div id="$id" hx-swap-oob="$swap">$html</div>`.
     * Append the return value to any response body to perform an out-of-band
     * swap without an additional round-trip.
     *
     * @param string $id    The CSS id of the target element (without `#`).
     * @param string $html  Inner HTML to inject.
     * @param string $swap  hx-swap-oob value (default: "true" → innerHTML).
     * @param string $tag   Wrapper element tag (default: div).
     */
    public static function oob(string $id, string $html, string $swap = 'true', string $tag = 'div'): string
    {
        $id   = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $swap = htmlspecialchars($swap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tag  = preg_replace('/[^a-zA-Z0-9]/', '', $tag) ?: 'div';
        return "<{$tag} id=\"{$id}\" hx-swap-oob=\"{$swap}\">{$html}</{$tag}>";
    }

    /**
     * Wrap $html so it swaps into another element on the page, in addition to
     * the response's main target — e.g. one action updating several
     * components.
     *
     * htmx 4 gets `<hx-partial hx-target="…" hx-swap="…">`; htmx 2 gets the
     * equivalent `<div hx-swap-oob="swap:selector">`, whose children htmx 2
     * swaps into the target. `$htmx` defaults to the version that sent the
     * current request (2 when there is none).
     *
     * @param string $target CSS selector of the element to update, e.g. `#cart`
     * @param string|\Stringable $html markup to swap in (not escaped)
     * @param string $swap   swap style: innerHTML, outerHTML, beforeend, …
     */
    public static function partial(string $target, string|\Stringable $html, string $swap = 'innerHTML', ?int $htmx = null): string
    {
        if ($htmx === null) {
            $req = \ZealPHP\RequestContext::instance()->zealphp_request;
            $htmx = $req instanceof Request ? ($req->htmxVersion() ?? 2) : 2;
        }
        if ($htmx !== 2 && $htmx !== 4) {
            throw new \InvalidArgumentException('HtmxResponse::partial() supports htmx 2 and 4');
        }
        $t = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = htmlspecialchars($swap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($htmx === 4) {
            return "<hx-partial hx-target=\"{$t}\" hx-swap=\"{$s}\">{$html}</hx-partial>";
        }
        return "<div hx-swap-oob=\"{$s}:{$t}\">{$html}</div>";
    }

    // ---- Builder exit ------------------------------------------------------

    /**
     * Return the parent Response so the builder chain can flow back into the
     * Response API. Without this the htmx() builder dead-ends — every HX-*
     * setter returns `static`, so there is no way to continue with a Response
     * call (e.g. `status()`) in the same expression.
     *
     *   $res->htmx()->retarget('#errors')->reswap('outerHTML')
     *       ->response()->status(422);
     */
    public function response(): Response
    {
        return $this->response;
    }

    // ---- Internal ----------------------------------------------------------

    private function emit(string $name, string $value): void
    {
        $this->response->header($name, $value);
    }
}
