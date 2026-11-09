<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\App;
use ZealPHP\Escape;
use ZealPHP\RequestContext;
use ZealPHP\SafeHtml;

/**
 * Base class for server-driven components.
 *
 * Public properties are the component's state. They travel to the browser in
 * a signed snapshot and come back with each request; only properties marked
 * `#[Model]` accept input from form controls, and only methods marked
 * `#[Action]` can be called. Every request builds a fresh instance, so
 * nothing is kept in the worker between requests.
 *
 * ```php
 * final class Counter extends Component
 * {
 *     public int $count = 0;
 *
 *     #[Action]
 *     public function increment(int $by = 1): void { $this->count += $by; }
 *
 *     public function render(): mixed { return $this->view('components/counter'); }
 * }
 * ```
 *
 * Templates rendered with {@see view()} receive each property as a variable,
 * the component as `$c`, and the validation errors as `$errors`. The
 * framework wraps the output in the component's outer element (`$tag`) and
 * adds the snapshot, so the template is just the inside.
 *
 * State must be scalars, arrays of scalars, or backed enums, and is readable
 * in the page source: keep ids in state and load the data in render().
 */
abstract class Component
{
    public const ENDPOINT = '/_zeal/c';
    /** Applies input and re-validates the changed fields, without an action. */
    public const UPDATE = '_update';
    /** Re-renders with the current state (and any submitted input). */
    public const REFRESH = '_refresh';

    /** The outer element. Use `'form'` so the component works without JavaScript. */
    protected string $tag = 'div';
    /** The action a submit of the outer form runs (`$tag = 'form'` only). */
    protected ?string $submit = null;
    /** @var array<string, string|bool|null> extra attributes for the outer element */
    protected array $attributes = [];

    private ?ComponentMeta $zcMeta = null;
    private string $zcId = '';
    /** @var array<string, string> */
    private array $zcErrors = [];
    /** @var list<string> #[Model] fields the current render emitted controls for */
    private array $zcFields = [];
    private ?string $zcTrigger = null;
    /** @var array<string, mixed> */
    private array $zcEvents = [];
    private ?string $zcRedirect = null;
    private ?string $zcWire = null;
    /** @var list<string> fields whose submitted value couldn't be converted */
    private array $zcBadInput = [];
    /** @var array<string, string> the raw submitted text of those fields, to show back */
    private array $zcRaw = [];

    /** Produce the component's inner HTML: a string, SafeHtml, or a Generator of strings. */
    abstract public function render(): mixed;

    /** Called once when the component is first placed on a page, after initial props are set. */
    protected function mount(): void
    {
    }

    /** Called on every endpoint request, after state is restored and before input is applied. */
    protected function hydrate(): void
    {
    }

    /**
     * Topics this component depends on. When any of them is invalidated
     * (`Component::invalidate('contacts')`), open pages showing this
     * component re-render it. Needs `App::componentPushRoot()` in the layout.
     * Topics: 1-128 letters, digits, `:`, `_`, `.` or `-` (e.g. `contact:42`).
     *
     * @return list<string>
     */
    public function topics(): array
    {
        return [];
    }

    /**
     * Mark topics stale: every open page showing a component that lists one
     * of them in `topics()` re-renders it (this worker, the node's other
     * workers, and other nodes with the Redis Store backend). Call it after
     * the write that changed the data.
     */
    public static function invalidate(string ...$topics): void
    {
        Push::invalidate(...$topics);
    }

    /**
     * Whether the current user may run this action. `$action` is the method
     * name, or {@see UPDATE} / {@see REFRESH}. Return false to answer 403.
     */
    public function authorize(string $action): bool
    {
        return true;
    }

    /**
     * Extra validation rules, merged over the `#[Model(rules: …)]` ones.
     *
     * @return array<string, string|list<string|\Closure>>
     */
    protected function rules(): array
    {
        return [];
    }

    /**
     * Custom messages: `'field.rule' => 'message'` or `'rule' => 'message'`.
     * `:field` is replaced with the field's label.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    // ── rendering ──────────────────────────────────────────────────

    /**
     * Render a template from `template/` with the component's state.
     *
     * @param array<string, mixed> $vars extra variables
     */
    protected function view(string $template, array $vars = []): SafeHtml
    {
        $args = $vars + $this->state() + ['c' => $this, 'errors' => $this->zcErrors];
        // Not a fragment selector: a component template is always rendered whole.
        unset($args['fragment']);
        // Absolute from template/: a relative name would be namespaced by the
        // current public file, so the page and the endpoint could pick
        // different templates.
        return App::component('/' . ltrim($template, '/'), $args);
    }

    /** The instance id, also the outer element's DOM id (e.g. `counter-main`). */
    public function id(): string
    {
        return $this->zcId;
    }

    /** The registered component name (e.g. `counter`). */
    public function name(): string
    {
        return $this->meta()->name;
    }

    public function tag(): string
    {
        return $this->tag;
    }

    /** The endpoint URL for an action, {@see UPDATE} or {@see REFRESH}. */
    public function actionUrl(string $action): string
    {
        return self::ENDPOINT . '/' . $this->meta()->name . '/' . rawurlencode($action);
    }

    // ── template helpers ──────────────────────────────────────────

    /**
     * Attributes that make an element (normally a `<button>`) run an action.
     *
     * @param array<string, mixed> $args action arguments, by parameter name
     */
    public function action(string $action, array $args = []): SafeHtml
    {
        if (!isset($this->meta()->actions[$action])) {
            throw new \LogicException(static::class . "::{$action}() is not an #[Action]");
        }
        $json = json_encode($args === [] ? new \stdClass() : $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new SafeHtml(Attr::render(
            App::componentBinder()->actionAttributes($this, $this->actionUrl($action), $json)
        ));
    }

    /**
     * Attributes that bind a form control to a `#[Model]` property: `name`,
     * `id`, the current `value` (unless `$withValue` is false — for
     * `<textarea>`, `<select>`, checkbox groups), ARIA error state, and the
     * binder's live/blur attributes.
     */
    public function model(string $prop, bool $withValue = true): SafeHtml
    {
        $model = $this->meta()->models[$prop] ?? null;
        if ($model === null) {
            throw new \LogicException(static::class . "::\${$prop} is not a #[Model] property");
        }
        if (!in_array($prop, $this->zcFields, true)) {
            $this->zcFields[] = $prop;
        }
        $type = $this->meta()->props[$prop];
        // A checkbox group repeats the control, so it gets no id (ids must be unique).
        $attrs = $type->kind === 'array'
            ? ['name' => $prop . '[]']
            : ['name' => $prop, 'id' => $this->zcId . '-' . $prop];
        if ($withValue && $type->kind !== 'array') {
            $attrs['value'] = $type->kind === 'bool' ? '1' : $this->value($prop, false);
        }
        if (isset($this->zcErrors[$prop])) {
            $attrs['aria-invalid'] = 'true';
            $attrs['aria-describedby'] = $this->zcId . '-' . $prop . '-error';
        }
        $binder = App::componentBinder();
        $attrs += $binder->modelAttributes($this, $prop, $model, $this->actionUrl(self::UPDATE), $this->zcTrigger === $prop);
        return new SafeHtml(Attr::render($attrs));
    }

    /**
     * A property's value as a string, HTML-escaped by default — for
     * `<textarea>` content or display.
     */
    public function value(string $prop, bool $escape = true): string
    {
        if (isset($this->zcRaw[$prop])) {
            // Show back what the user typed when it didn't convert.
            return $escape ? Escape::html($this->zcRaw[$prop]) : $this->zcRaw[$prop];
        }
        $v = $this->propValue($prop);
        $s = match (true) {
            $v instanceof \BackedEnum => (string) $v->value,
            is_bool($v) => $v ? '1' : '',
            is_scalar($v) => (string) $v,
            default => '',
        };
        return $escape ? Escape::html($s) : $s;
    }

    /**
     * `checked` when a bool property is true, or when an array property
     * contains `$option` (checkbox groups) / a scalar property equals it (radios).
     */
    public function checked(string $prop, mixed $option = true): string
    {
        return $this->matches($prop, $option) ? 'checked' : '';
    }

    /** `selected` when the property equals (or, for arrays, contains) `$option`. */
    public function selected(string $prop, mixed $option): string
    {
        return $this->matches($prop, $option) ? 'selected' : '';
    }

    /** The error message for a field, wrapped in a `<span class="zc-error">`, or nothing. */
    public function error(string $field): SafeHtml
    {
        if (!isset($this->zcErrors[$field])) {
            return new SafeHtml('');
        }
        return new SafeHtml('<span ' . Attr::render([
            'class' => 'zc-error',
            'id' => $this->zcId . '-' . $field . '-error',
            'role' => 'alert',
        ]) . '>' . Escape::html($this->zcErrors[$field]) . '</span>');
    }

    public function hasError(string $field): bool
    {
        return isset($this->zcErrors[$field]);
    }

    /** @return array<string, string> field → message */
    public function errors(): array
    {
        return $this->zcErrors;
    }

    // ── for actions ──────────────────────────────────────────────

    /**
     * Validate `#[Model]` fields (all, or only `$only`) and throw on failure.
     * Errors for the checked fields are replaced; others are kept.
     *
     * @param list<string>|null $only
     * @throws ValidationException
     */
    protected function validate(?array $only = null): void
    {
        $errors = $this->runValidation($only);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    protected function addError(string $field, string $message): void
    {
        $this->zcErrors[$field] = $message;
    }

    protected function clearErrors(?string $field = null): void
    {
        if ($field === null) {
            $this->zcErrors = [];
        } else {
            unset($this->zcErrors[$field]);
        }
    }

    /**
     * Fire a browser event after the response is swapped in (htmx: via
     * `HX-Trigger`). `$detail` must be JSON-encodable.
     */
    protected function dispatch(string $event, mixed $detail = null): void
    {
        $this->zcEvents[$event] = $detail;
    }

    /** Navigate the browser to a same-site URL after this action. */
    protected function redirect(string $url): void
    {
        // A path starting with one '/', with no control characters, spaces or
        // backslashes anywhere: browsers strip tabs/newlines and treat '\' as
        // '/', so "/\t/evil.test" or "/\\evil.test" would leave the site.
        if (preg_match('#^/(?![/\\\\])[^\x00-\x20\x7f\\\\]*$#', $url) !== 1) {
            throw new \InvalidArgumentException('Component redirects must be a same-site path starting with /');
        }
        $this->zcRedirect = $url;
    }

    // ── framework internals ──────────────────────────────────────

    /**
     * Create a component for first display.
     *
     * @internal
     * @param array<array-key, mixed> $props keys are validated here: callers pass user arrays
     */
    final public static function zcMount(ComponentMeta $meta, array $props, string $id): self
    {
        $c = self::zcInstantiate($meta, $id);
        foreach ($props as $prop => $value) {
            if (!is_string($prop) || !isset($meta->props[$prop])) {
                throw new \LogicException("{$meta->class} has no state property \${$prop}");
            }
            try {
                $c->setProp($prop, $meta->props[$prop]->fromSnapshot($value));
            } catch (\InvalidArgumentException $e) {
                throw new \LogicException("{$meta->class}::\${$prop} " . $e->getMessage(), 0, $e);
            }
        }
        $c->mount();
        return $c;
    }

    /**
     * Rebuild a component from a verified snapshot.
     *
     * @internal
     */
    final public static function zcRestore(ComponentMeta $meta, Snapshot $snapshot): self
    {
        $c = self::zcInstantiate($meta, $snapshot->id);
        foreach ($meta->props as $prop => $type) {
            if (!array_key_exists($prop, $snapshot->props)) {
                throw ComponentException::stale("Snapshot is missing \${$prop}");
            }
            try {
                $c->setProp($prop, $type->fromSnapshot($snapshot->props[$prop]));
            } catch (\InvalidArgumentException $e) {
                throw ComponentException::badRequest("Snapshot value for \${$prop} " . $e->getMessage());
            }
        }
        $c->zcErrors = $snapshot->errors;
        $c->zcFields = array_values(array_intersect($snapshot->fields, array_keys($meta->models)));
        $c->hydrate();
        return $c;
    }

    /**
     * Apply submitted form values to the `#[Model]` fields the last render
     * emitted controls for. Values for other fields are ignored. A missing
     * checkbox (bool) or checkbox group (array) means unchecked. Hooks run
     * separately, via {@see zcRunUpdatedHooks()}.
     *
     * @internal
     * @param array<string, mixed> $input
     * @return list<string> the fields whose value changed
     */
    final public function zcApplyInput(array $input): array
    {
        $changed = [];
        foreach ($this->zcFields as $prop) {
            $type = $this->meta()->props[$prop];
            if (array_key_exists($prop, $input)) {
                $raw = $input[$prop];
            } elseif ($type->kind === 'bool') {
                $raw = false;
            } elseif ($type->kind === 'array') {
                $raw = [];
            } else {
                continue;
            }
            try {
                $value = $type->fromInput($raw);
            } catch (\InvalidArgumentException $e) {
                $this->zcErrors[$prop] = Validator::label($prop) . ' ' . $e->getMessage() . '.';
                $this->zcBadInput[] = $prop;
                $this->zcRaw[$prop] = is_scalar($raw) ? (string) $raw : '';
                continue;
            }
            if ($value === $this->propValue($prop)) {
                continue;
            }
            $this->setProp($prop, $value);
            unset($this->zcErrors[$prop]);
            $changed[] = $prop;
        }
        return $changed;
    }

    /**
     * Run the `updated{Prop}()` / `updated()` hooks for changed fields. The
     * endpoint calls this after live validation, so errors a hook adds stick.
     *
     * @internal
     * @param list<string> $fields
     */
    final public function zcRunUpdatedHooks(array $fields): void
    {
        foreach ($fields as $prop) {
            $value = $this->propValue($prop);
            $hook = 'updated' . ucfirst($prop);
            if (method_exists($this, $hook)) {
                $this->{$hook}($value);
            }
            if (method_exists($this, 'updated')) {
                $this->updated($prop, $value);
            }
        }
    }

    /**
     * Re-validate fields after a live update without throwing.
     *
     * @internal
     * @param list<string> $fields
     */
    final public function zcValidateFields(array $fields): void
    {
        $rules = $this->allRules();
        $fields = array_values(array_filter($fields, static fn(string $f): bool => isset($rules[$f])));
        if ($fields !== []) {
            $this->runValidation($fields);
        }
    }

    /**
     * Run an action with arguments from the request.
     *
     * @internal
     * @param array<string, mixed> $args
     * @throws ComponentException|ValidationException
     */
    final public function zcCall(string $action, array $args): void
    {
        $params = $this->meta()->actions[$action] ?? null;
        if ($params === null) {
            throw ComponentException::notFound("Unknown action {$action}");
        }
        $bound = [];
        foreach ($params as $param) {
            $name = $param['name'];
            if (!array_key_exists($name, $args)) {
                if (!$param['optional']) {
                    throw ComponentException::badRequest("Missing argument \${$name} for {$action}()");
                }
                $bound[] = $param['default'];
                continue;
            }
            try {
                $bound[] = $param['type']->fromInput($args[$name]);
            } catch (\InvalidArgumentException $e) {
                throw ComponentException::badRequest("Argument \${$name} " . $e->getMessage());
            }
        }
        $this->{$action}(...$bound);
    }

    /**
     * Fields from the last {@see zcApplyInput()} whose value couldn't be
     * converted to the property type.
     *
     * @internal
     * @return list<string>
     */
    final public function zcBadInput(): array
    {
        return $this->zcBadInput;
    }

    /** @internal */
    final public function zcSetTrigger(?string $field): void
    {
        $this->zcTrigger = $field;
    }

    /**
     * @internal
     * @return array<string, mixed>
     */
    final public function zcEvents(): array
    {
        return $this->zcEvents;
    }

    /** @internal */
    final public function zcRedirect(): ?string
    {
        return $this->zcRedirect;
    }

    /**
     * @internal
     * @param array<string, string> $errors
     */
    final public function zcMergeErrors(array $errors): void
    {
        $this->zcErrors = $errors + $this->zcErrors;
    }

    /** The signed snapshot from the last {@see zcToHtml()} call. @internal */
    final public function zcWire(): ?string
    {
        return $this->zcWire;
    }

    /**
     * Render the full component: outer element, snapshot, and inner HTML.
     *
     * @internal
     */
    final public function zcToHtml(): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $this->tag) !== 1) {
            throw new \LogicException(static::class . ": invalid \$tag '{$this->tag}'");
        }
        $this->zcFields = [];
        $g = RequestContext::instance();
        $depth = $g->memo['_zc_depth'] ?? 0;
        $g->memo['_zc_depth'] = (is_int($depth) ? $depth : 0) + 1;
        try {
            $body = self::stringify($this->render());
        } finally {
            $g->memo['_zc_depth'] = is_int($depth) ? $depth : 0;
        }

        $meta = $this->meta();
        $props = [];
        foreach ($meta->props as $prop => $type) {
            $props[$prop] = $type->toSnapshot($this->propValue($prop), static::class . "::\${$prop}");
        }
        $this->zcWire = (new Snapshot(
            $meta->name,
            $this->zcId,
            $props,
            $meta->version,
            Snapshot::currentBinding(),
            $this->zcFields,
            $this->zcErrors,
        ))->encode();

        $submitUrl = null;
        if ($this->submit !== null) {
            if (!isset($meta->actions[$this->submit])) {
                throw new \LogicException(static::class . "::\$submit names {$this->submit}(), which is not an #[Action]");
            }
            $submitUrl = $this->actionUrl($this->submit);
        }
        $attrs = Attr::merge(
            $this->attributes,
            App::componentBinder()->rootAttributes($this, $submitUrl),
            ['id' => $this->zcId, 'data-zc' => $meta->name],
        );
        // Pressing Enter submits with the form's FIRST submit button. Put a
        // hidden one for $submit first, so an action button earlier in the
        // template (e.g. "Remove") doesn't run instead.
        $defaultSubmit = '';
        if ($submitUrl !== null && $this->tag === 'form') {
            $defaultSubmit = '<button ' . Attr::render([
                'type' => 'submit', 'hidden' => true, 'tabindex' => '-1', 'aria-hidden' => 'true',
                'formaction' => $submitUrl, 'formmethod' => 'post',
            ]) . '></button>';
        }
        return '<' . $this->tag . ' ' . Attr::render($attrs) . '>'
            . '<input type="hidden" name="_zc" value="' . Escape::html($this->zcWire) . '">'
            . $defaultSubmit
            . $body
            . '</' . $this->tag . '>';
    }

    // ── private ──────────────────────────────────────────────────

    private static function zcInstantiate(ComponentMeta $meta, string $id): self
    {
        $class = $meta->class;
        $c = new $class();
        $c->zcMeta = $meta;
        $c->zcId = $id;
        return $c;
    }

    private function meta(): ComponentMeta
    {
        if ($this->zcMeta === null) {
            throw new \LogicException(static::class . ' was created directly; render it with App::component()');
        }
        return $this->zcMeta;
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $out = [];
        foreach (array_keys($this->meta()->props) as $prop) {
            $out[$prop] = $this->propValue($prop);
        }
        return $out;
    }

    private function propValue(string $prop): mixed
    {
        if (!isset($this->meta()->props[$prop])) {
            throw new \LogicException(static::class . " has no state property \${$prop}");
        }
        return (new \ReflectionProperty($this, $prop))->getValue($this);
    }

    private function setProp(string $prop, mixed $value): void
    {
        (new \ReflectionProperty($this, $prop))->setValue($this, $value);
    }

    private function matches(string $prop, mixed $option): bool
    {
        $v = $this->propValue($prop);
        $norm = static fn(mixed $x): mixed => $x instanceof \BackedEnum ? $x->value : $x;
        $option = $norm($option);
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_scalar($item) && is_scalar($option) && (string) $item === (string) $option) {
                    return true;
                }
            }
            return false;
        }
        $v = $norm($v);
        if (is_bool($v) && $option === true) {
            return $v;
        }
        return is_scalar($v) && is_scalar($option) && (string) $v === (string) $option;
    }

    /**
     * @return array<string, string|list<string|\Closure>>
     */
    private function allRules(): array
    {
        $rules = [];
        foreach ($this->meta()->models as $prop => $model) {
            if ($model->rules !== '') {
                $rules[$prop] = $model->rules;
            }
        }
        return $this->rules() + $rules;
    }

    /**
     * @param list<string>|null $only
     * @return array<string, string> errors for the checked fields
     */
    private function runValidation(?array $only): array
    {
        $rules = $this->allRules();
        if ($only !== null) {
            $rules = array_intersect_key($rules, array_flip($only));
        }
        $values = [];
        foreach (array_keys($rules) as $field) {
            $values[$field] = isset($this->meta()->props[$field]) ? $this->propValue($field) : null;
        }
        $errors = Validator::validate($values, $rules, $this->messages());
        foreach (array_keys($rules) as $field) {
            unset($this->zcErrors[$field]);
        }
        $this->zcErrors = $errors + $this->zcErrors;
        return $errors;
    }

    private static function stringify(mixed $out): string
    {
        if ($out instanceof \Generator) {
            $buf = '';
            foreach ($out as $chunk) {
                $buf .= self::stringify($chunk);
            }
            return $buf;
        }
        if ($out === null) {
            return '';
        }
        if (is_scalar($out) || $out instanceof \Stringable) {
            return (string) $out;
        }
        throw new \LogicException('Component render() must return a string, SafeHtml, or a Generator of strings');
    }
}
