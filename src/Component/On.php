<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * Runs a component method when another component on the page dispatches an
 * event, then re-renders this component:
 *
 *     #[On('cart-updated')]
 *     public function sync(): void { $this->count = Cart::count(); }
 *
 * Events come from `$this->dispatch('cart-updated')` in any component's
 * action. Each listener refreshes through its own snapshot and session, like
 * any other request; no data travels with the event.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class On
{
    /** @var list<string> */
    public readonly array $events;

    public function __construct(string ...$events)
    {
        foreach ($events as $event) {
            if (preg_match('/^[A-Za-z0-9:_.\-]{1,64}$/D', $event) !== 1) {
                throw new \InvalidArgumentException("#[On] event '{$event}': use 1-64 letters, digits, ':', '_', '.' or '-'");
            }
        }
        if ($events === []) {
            throw new \InvalidArgumentException('#[On] needs at least one event name');
        }
        $this->events = array_values($events);
    }
}
