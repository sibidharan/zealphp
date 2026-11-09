<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * The binder for pages without JavaScript, and the protocol's reference
 * client. Components render as ordinary forms: submitting runs the action and
 * the endpoint answers with a 303 back to the page. Set the component's
 * `$tag = 'form'` so its controls and action buttons sit inside a form.
 *
 * Other binders build on this one: {@see HtmxBinder} adds `hx-*` attributes
 * to the same markup, so the no-JS path keeps working under htmx too.
 *
 * Protocol headers it understands from scripted clients:
 * `Zeal-Request: 1` (async), `Zeal-Trigger: <field>`. It answers with
 * `Zeal-Events` (JSON), `Zeal-Redirect`, and `Zeal-Reload: 1`.
 */
class FormBinder implements ComponentBinder
{
    public function rootAttributes(Component $component, ?string $submitUrl): array
    {
        return ['method' => 'post', 'action' => $submitUrl ?? $component->actionUrl(Component::REFRESH)];
    }

    public function actionAttributes(Component $component, string $url, string $argsJson): array
    {
        return [
            'type' => 'submit',
            'formaction' => $url,
            'formmethod' => 'post',
            'name' => '_zc_args',
            'value' => $argsJson,
        ];
    }

    public function modelAttributes(Component $component, string $prop, Model $model, string $updateUrl, bool $preserve): array
    {
        return [];
    }

    /** Markers a script can act on; without JavaScript nothing delivers events. */
    public function listenerAttributes(Component $component, string $event, string $url): array
    {
        return ['data-zc-on' => $event, 'data-zc-url' => $url, 'data-zc-for' => $component->id()];
    }

    public function isAsync(array $server): bool
    {
        return ($server['HTTP_ZEAL_REQUEST'] ?? null) === '1';
    }

    public function triggerField(array $server): ?string
    {
        $v = $server['HTTP_ZEAL_TRIGGER'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function refusalHeaders(): array
    {
        return [];
    }

    public function response(Outcome $outcome, array $events, ?string $redirect): array
    {
        $headers = [];
        if ($events !== []) {
            $headers['Zeal-Events'] = (string) json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($redirect !== null) {
            $headers['Zeal-Redirect'] = $redirect;
        }
        $status = match ($outcome) {
            Outcome::Invalid => 422,
            Outcome::Stale => 409,
            Outcome::Ok => 200,
        };
        if ($outcome === Outcome::Stale) {
            $headers['Zeal-Reload'] = '1';
        }
        return ['status' => $status, 'headers' => $headers];
    }
}
