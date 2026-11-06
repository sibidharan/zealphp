<?php

declare(strict_types=1);

namespace ZealPHP\Component;

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
 * `morph: true` swaps with idiomorph (`hx-swap="morph:outerHTML"`), which
 * keeps more DOM state across updates. It needs the htmx idiomorph extension
 * script on the page.
 */
class HtmxBinder extends FormBinder
{
    private const TARGET = 'closest [data-zc]';

    public function __construct(private readonly bool $morph = false)
    {
    }

    public function rootAttributes(Component $component, ?string $submitUrl): array
    {
        $attrs = parent::rootAttributes($component, $submitUrl);
        if ($this->morph) {
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
            'hx-disabled-elt' => 'this',
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
            'hx-preserve' => $preserve ? 'true' : null,
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

    private function swap(): string
    {
        return $this->morph ? 'morph:outerHTML' : 'outerHTML';
    }
}
