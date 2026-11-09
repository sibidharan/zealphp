<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * Decides which HTML attributes make a component interactive in the browser,
 * and how endpoint side effects (events, redirects, reloads) are signalled.
 *
 * The component endpoint itself speaks a plain-HTTP protocol (see
 * `docs/components.md#protocol`); a binder adapts the markup and response
 * headers to one client: htmx ({@see HtmxBinder}, the default), no JavaScript
 * ({@see FormBinder}), or a custom one.
 *
 * Every binder must keep the no-JS fallback working: attributes it returns
 * are added on top of the plain-form markup from {@see FormBinder}.
 */
interface ComponentBinder
{
    /**
     * Extra attributes for the component's outer element.
     *
     * @param string|null $submitUrl the URL of the component's `$submit` action, if any
     * @return array<string, string|bool|null>
     */
    public function rootAttributes(Component $component, ?string $submitUrl): array;

    /**
     * Attributes for an element that triggers an action.
     *
     * @param string $argsJson the action arguments, JSON-encoded
     * @return array<string, string|bool|null>
     */
    public function actionAttributes(Component $component, string $url, string $argsJson): array;

    /**
     * Attributes for a form control bound to a `#[Model]` property.
     *
     * @param string $updateUrl the component's `_update` endpoint
     * @param bool   $preserve  true when this control triggered the current update
     *                          and should be left untouched by the swap
     * @return array<string, string|bool|null>
     */
    public function modelAttributes(Component $component, string $prop, Model $model, string $updateUrl, bool $preserve): array;

    /**
     * Attributes for a hidden element that delivers an event the component
     * listens for (`#[On]`): when any component dispatches `$event`, the
     * client posts the component's form to `$url` with `_zc_event` set.
     *
     * @return array<string, string|bool|null>
     */
    public function listenerAttributes(Component $component, string $event, string $url): array;

    /**
     * Whether the request came from this binder's client script (rather than
     * a plain form post that expects a redirect back to the page).
     *
     * @param array<string, mixed> $server CGI-style request variables (`HTTP_*`)
     */
    public function isAsync(array $server): bool;

    /**
     * The name of the form control that triggered the request, if the client
     * reports it.
     *
     * @param array<string, mixed> $server
     */
    public function triggerField(array $server): ?string;

    /**
     * Extra headers for a refused request (400/403/404), e.g. telling the
     * client not to swap the error text into the page.
     *
     * @return array<string, string>
     */
    public function refusalHeaders(): array;

    /**
     * Status and headers for an endpoint outcome.
     *
     * @param array<string, mixed> $events event name → detail
     * @return array{status: int, headers: array<string, string>}
     */
    public function response(Outcome $outcome, array $events, ?string $redirect): array;
}
