<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\SafeHtml;

/**
 * Components without htmx: a small script (`/_zeal/c/zeal.js`) that speaks
 * the plain protocol (`Zeal-Request: 1`, `Zeal-Events`, `Zeal-Redirect`,
 * `Zeal-Reload`, 422 for validation).
 *
 *     App::componentBinder(new ZealJsBinder());
 *     // in the layout, once:
 *     <?= ZealJsBinder::script() ?>
 *
 * Built on {@see FormBinder}, so the markup stays a working form without
 * JavaScript; this binder only adds `data-zc-*` markers the script reads:
 *
 * - actions: `data-zc-action` on the element (its `formaction` and `value`
 *   already carry the URL and arguments);
 * - live inputs: `data-zc-live` (debounce in ms) and `data-zc-update`;
 *   on-blur inputs: `data-zc-blur` and `data-zc-update`.
 *
 * The script swaps the re-rendered component in place, keeps the value,
 * focus and cursor of the field being typed in, lets an action replace a
 * live update still in flight on the same component, and re-dispatches
 * `Zeal-Events` as DOM events (`CustomEvent`, bubbling, `detail` set) on
 * the component.
 */
final class ZealJsBinder extends FormBinder
{
    public const SCRIPT = '/_zeal/c/zeal.js';

    /** The script tag for the layout, versioned by the script's content. */
    public static function script(): SafeHtml
    {
        return new SafeHtml('<script src="' . self::SCRIPT . '?v=' . self::version() . '" defer></script>');
    }

    /** The client script's source, served at {@see self::SCRIPT}. */
    public static function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/zeal.js');
    }

    public function actionAttributes(Component $component, string $url, string $argsJson): array
    {
        return parent::actionAttributes($component, $url, $argsJson) + ['data-zc-action' => true];
    }

    public function modelAttributes(Component $component, string $prop, Model $model, string $updateUrl, bool $preserve): array
    {
        if ($model->live) {
            return ['data-zc-live' => (string) $model->debounce, 'data-zc-update' => $updateUrl];
        }
        if ($model->blur) {
            return ['data-zc-blur' => true, 'data-zc-update' => $updateUrl];
        }
        return [];
    }

    private static function version(): string
    {
        return self::$version ??= substr(hash('sha256', self::source()), 0, 12);
    }

    private static ?string $version = null;
}
