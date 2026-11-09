<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\RequestContext;

/**
 * The first-class binder: makes components interactive with htmx (2.x)
 * attributes on top of the plain-form markup, so pages still work without JS.
 *
 * - Actions and live/blur inputs post to the component endpoint, include
 *   every control inside the component (`hx-include="closest [data-zc]"`),
 *   and swap the re-rendered component in place.
 * - An action always goes out: it replaces (aborts) a live update still in
 *   flight on the same component — the action request carries every field
 *   anyway. It doesn't queue behind it, because htmx drops a queued request
 *   whose element was replaced by the swap. The clicked button is disabled
 *   until its response arrives, so a double-click can't re-send a stale
 *   snapshot.
 * - Live updates queue (`queue last`) behind one another; the input that
 *   triggered them is preserved across the swap, so the queued request
 *   still has its element.
 * - The control that triggered a live update gets `hx-preserve`, so htmx
 *   keeps the element the user is typing in (value, focus and cursor) while
 *   the rest of the component is replaced.
 * - Validation errors come back as 200 (htmx doesn't swap 4xx by default);
 *   a snapshot from an older deploy answers `HX-Refresh: true`.
 *
 * `htmx: 2` (default) or `htmx: 4` picks the attribute dialect for pages.
 * A component request tells the binder which htmx sent it (htmx 4 adds
 * `HX-Request-Type`), so its response always uses that version's dialect,
 * whatever the setting; the setting only decides the first page render.
 *
 * The dialects:
 *
 * - **htmx 4** always swaps with its built-in `outerMorph`. Morphing keeps the
 *   DOM nodes, and htmx 4 never overwrites the value of the focused input, so
 *   the field being typed in survives without `hx-preserve` (which in htmx 4
 *   only works when the *existing* element already carries it). Kept nodes
 *   also keep queued requests alive. The clicked button is disabled with
 *   `hx-disable` (which means "ignore this element" in htmx 2).
 * - **htmx 2** swaps `outerHTML` and marks the triggering field
 *   `hx-preserve`; `morph: true` uses the idiomorph extension instead
 *   (`morph:outerHTML`, needs the extension script on the page).
 *
 * Every attribute is set on the element that needs it, never inherited, so
 * htmx 4's explicit inheritance changes nothing. Refused requests answer
 * `HX-Reswap: none`, so htmx 4 (which swaps 4xx/5xx by default) leaves the
 * component in place.
 */
class HtmxBinder extends FormBinder
{
    private const TARGET = 'closest [data-zc]';

    public function __construct(private readonly bool $morph = false, private readonly int $htmx = 2)
    {
        if ($htmx !== 2 && $htmx !== 4) {
            throw new \InvalidArgumentException('HtmxBinder supports htmx 2 and 4');
        }
    }

    /**
     * The htmx version to write attributes for: the one that sent the current
     * request, else the configured one (a page render has no htmx request).
     */
    private function dialect(): int
    {
        $req = RequestContext::instance()->zealphp_request;
        if ($req instanceof \ZealPHP\HTTP\Request && $req->isHtmx()) {
            return $req->htmxVersion() ?? $this->htmx;
        }
        return $this->htmx;
    }

    public function rootAttributes(Component $component, ?string $submitUrl): array
    {
        $attrs = parent::rootAttributes($component, $submitUrl);
        if ($this->morph && $this->dialect() === 2) {
            $attrs['hx-ext'] = 'morph';
        }
        if ($submitUrl !== null && $component->tag() === 'form') {
            $attrs['hx-post'] = $submitUrl;
            $attrs['hx-target'] = 'this';
            $attrs['hx-swap'] = $this->swap();
            $attrs['hx-sync'] = 'this:replace';
        }
        return $attrs;
    }

    public function actionAttributes(Component $component, string $url, string $argsJson): array
    {
        return Attr::merge(parent::actionAttributes($component, $url, $argsJson), [
            'hx-post' => $url,
            'hx-target' => self::TARGET,
            'hx-swap' => $this->swap(),
            'hx-include' => self::TARGET,
            'hx-vals' => (string) json_encode(['_zc_args' => $argsJson], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'hx-sync' => self::TARGET . ':replace',
            ($this->dialect() === 4 ? 'hx-disable' : 'hx-disabled-elt') => 'this',
        ]);
    }

    public function modelAttributes(Component $component, string $prop, Model $model, string $updateUrl, bool $preserve): array
    {
        if (!$model->live && !$model->blur) {
            return [];
        }
        return [
            'hx-post' => $updateUrl,
            'hx-trigger' => $model->live ? "input changed delay:{$model->debounce}ms" : 'change',
            'hx-target' => self::TARGET,
            'hx-swap' => $this->swap(),
            'hx-include' => self::TARGET,
            'hx-sync' => self::TARGET . ':queue last',
            // Names the field that changed, in the body: works on every htmx
            // version (htmx 4 doesn't send HX-Trigger-Name).
            'hx-vals' => (string) json_encode(['_zc_trigger' => $prop], JSON_UNESCAPED_SLASHES),
            // htmx 4 morphs, which already keeps the focused field.
            'hx-preserve' => $preserve && $this->dialect() === 2 ? 'true' : null,
        ];
    }

    public function isAsync(array $server): bool
    {
        return ($server['HTTP_HX_REQUEST'] ?? null) === 'true' || parent::isAsync($server);
    }

    public function triggerField(array $server): ?string
    {
        $v = $server['HTTP_HX_TRIGGER_NAME'] ?? null;
        return is_string($v) && $v !== '' ? $v : parent::triggerField($server);
    }

    public function response(Outcome $outcome, array $events, ?string $redirect): array
    {
        $headers = [];
        if ($events !== []) {
            $headers['HX-Trigger'] = (string) json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($redirect !== null) {
            $headers['HX-Redirect'] = $redirect;
        }
        if ($outcome === Outcome::Stale) {
            $headers['HX-Refresh'] = 'true';
        }
        return ['status' => 200, 'headers' => $headers];
    }

    public function refusalHeaders(): array
    {
        return ['HX-Reswap' => 'none'];
    }

    private function swap(): string
    {
        if ($this->dialect() === 4) {
            return 'outerMorph';
        }
        return $this->morph ? 'morph:outerHTML' : 'outerHTML';
    }
}
