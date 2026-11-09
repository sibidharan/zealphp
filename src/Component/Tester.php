<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\App;
use ZealPHP\AppKey;

/**
 * Drive a reactive component in a unit test, without a server or a browser:
 *
 *     Tester::render(Counter::class, ['count' => 1])
 *         ->call('add', ['by' => 2])
 *         ->assertSet('count', 3)
 *         ->assertSee('3');
 *
 * Each step goes through the real component endpoint with the plain-HTTP
 * protocol (`Zeal-Request: 1`), so snapshots, validation, `authorize()`,
 * hooks, events and redirects behave exactly as in production. Assertions
 * use PHPUnit when it's installed and throw `\AssertionError` otherwise.
 *
 * Without `ZEALPHP_APP_KEY` the tester uses a random key for the process.
 */
final class Tester
{
    private string $html;
    private int $status = 200;
    /** @var array<string, string> */
    private array $headers = [];
    private string $body = '';

    private function __construct(private readonly ComponentMeta $meta)
    {
    }

    /**
     * Render a component (registering its class if needed).
     *
     * @param class-string<Component>|string $component a class or a registered name
     * @param array<string, mixed> $props
     */
    public static function render(string $component, array $props = [], ?string $key = null): self
    {
        if (AppKey::current() === null && !AppKey::isEphemeral()) {
            AppKey::useEphemeral();
        }
        $meta = Registry::byName($component);
        if ($meta === null && is_subclass_of($component, Component::class)) {
            $meta = Registry::byClass($component) ?? Registry::register($component);
        }
        if ($meta === null) {
            throw new \InvalidArgumentException("Unknown component {$component}: pass a Component class or a registered name");
        }
        $tester = new self($meta);
        $tester->html = (string) App::component($meta->class, $props, $key);
        return $tester;
    }

    // ── steps ─────────────────────────────────────────────────────

    /**
     * Run an `#[Action]`, like clicking its button.
     *
     * @param array<string, mixed> $args
     */
    public function call(string $action, array $args = []): self
    {
        return $this->send($action, ['_zc_args' => (string) json_encode($args === [] ? new \stdClass() : $args)]);
    }

    /**
     * Change a `#[Model]` property, like typing into its input: the field is
     * validated and its `updated{Prop}()` hook runs. The other bound fields
     * are sent with their current values, as a browser would.
     */
    public function set(string $prop, mixed $value): self
    {
        if (!isset($this->meta->models[$prop])) {
            throw new \InvalidArgumentException("{$this->meta->class}::\${$prop} is not a #[Model] property");
        }
        $fields = $this->currentFields();
        unset($fields[$prop]);
        $fields += self::field($prop, $value);
        return $this->send(Component::UPDATE, $fields + ['_zc_trigger' => $prop]);
    }

    /** Re-render with the current state, like a push refresh. */
    public function refresh(): self
    {
        return $this->send(Component::REFRESH, []);
    }

    // ── reading ───────────────────────────────────────────────────

    /** The component's current markup (its outer element included). */
    public function html(): string
    {
        return $this->html;
    }

    /** HTTP status of the last step (200 before any). */
    public function status(): int
    {
        return $this->status;
    }

    /** A public property's current value. */
    public function get(string $prop): mixed
    {
        if (!isset($this->meta->props[$prop])) {
            throw new \InvalidArgumentException("{$this->meta->class} has no state property \${$prop}");
        }
        return $this->instance()->{$prop};
    }

    /** @return array<string, string> field → message */
    public function errors(): array
    {
        return $this->snapshot()->errors;
    }

    /** @return array<string, mixed> events the last step dispatched, name → detail */
    public function events(): array
    {
        $raw = $this->headers['Zeal-Events'] ?? '';
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $events = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $detail) {
            $events[(string) $name] = $detail;
        }
        return $events;
    }

    /** Where the last step redirected the page, or null. */
    public function redirectedTo(): ?string
    {
        return $this->headers['Zeal-Redirect'] ?? null;
    }

    // ── assertions ────────────────────────────────────────────────

    /** The markup contains $text (HTML-escaped first, unless $escape is false). */
    public function assertSee(string $text, bool $escape = true): self
    {
        $needle = $escape ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
        return $this->check(str_contains($this->html, $needle), "Expected the component to show '{$text}'");
    }

    public function assertDontSee(string $text, bool $escape = true): self
    {
        $needle = $escape ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
        return $this->check(!str_contains($this->html, $needle), "Expected the component not to show '{$text}'");
    }

    public function assertSet(string $prop, mixed $expected): self
    {
        $actual = $this->get($prop);
        return $this->check(
            $actual === $expected,
            "Expected \${$prop} to be " . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    /** Every named field has an error; with none named, at least one error exists. */
    public function assertHasErrors(string ...$fields): self
    {
        $errors = $this->errors();
        if ($fields === []) {
            return $this->check($errors !== [], 'Expected validation errors, found none');
        }
        $missing = array_values(array_diff($fields, array_keys($errors)));
        return $this->check($missing === [], 'Expected errors on: ' . implode(', ', $missing));
    }

    public function assertHasNoErrors(): self
    {
        $errors = $this->errors();
        return $this->check($errors === [], 'Expected no errors, found: ' . (string) json_encode($errors));
    }

    public function assertDispatched(string $event): self
    {
        return $this->check(array_key_exists($event, $this->events()), "Expected event '{$event}' to be dispatched");
    }

    public function assertRedirect(?string $to = null): self
    {
        $at = $this->redirectedTo();
        return $this->check(
            $at !== null && ($to === null || $at === $to),
            $to === null ? 'Expected a redirect' : "Expected a redirect to {$to}, got " . ($at ?? 'none')
        );
    }

    public function assertStatus(int $status): self
    {
        return $this->check($this->status === $status, "Expected status {$status}, got {$this->status}: {$this->body}");
    }

    // ── internals ─────────────────────────────────────────────────

    /** @param array<string, mixed> $fields */
    private function send(string $action, array $fields): self
    {
        $binder = App::componentBinder();
        App::componentBinder(new FormBinder()); // the plain protocol: same results whatever the app's binder
        try {
            $r = Endpoint::handle($this->meta->name, $action, ['_zc' => $this->wire()] + $fields, [
                'HTTP_ZEAL_REQUEST' => '1',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ]);
        } finally {
            App::componentBinder($binder);
        }
        $this->status = $r['status'];
        $this->headers = $r['headers'];
        $this->body = $r['body'];
        if ($r['status'] === 200 || $r['status'] === 422) {
            $this->html = $r['body'];
        }
        return $this;
    }

    private function wire(): string
    {
        if (preg_match('/name="_zc" value="([^"]+)"/', $this->html, $m) !== 1) {
            throw new \LogicException('No component snapshot in the markup');
        }
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    private function snapshot(): Snapshot
    {
        return Snapshot::decode($this->wire(), $this->meta);
    }

    private function instance(): Component
    {
        return Component::zcRestore($this->meta, $this->snapshot());
    }

    /**
     * The bound fields' current values, encoded as a form would send them.
     *
     * @return array<string, mixed>
     */
    private function currentFields(): array
    {
        $instance = $this->instance();
        $fields = [];
        foreach ($this->snapshot()->fields as $prop) {
            $fields += self::field($prop, $instance->{$prop});
        }
        return $fields;
    }

    /** @return array<string, mixed> */
    private static function field(string $prop, mixed $value): array
    {
        return match (true) {
            $value === true => [$prop => '1'],
            $value === false => [], // an unchecked checkbox sends nothing
            $value === null => [$prop => ''],
            $value instanceof \BackedEnum => [$prop => (string) $value->value],
            is_array($value) => [$prop => array_map(
                static fn(mixed $v): string => $v instanceof \BackedEnum ? (string) $v->value : (is_scalar($v) ? (string) $v : ''),
                array_values($value)
            )],
            is_scalar($value) => [$prop => (string) $value],
            default => [$prop => ''],
        };
    }

    private function check(bool $ok, string $message): self
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertTrue($ok, $message);
        } elseif (!$ok) {
            throw new \AssertionError($message);
        }
        return $this;
    }
}
