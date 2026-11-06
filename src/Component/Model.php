<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * Marks a public component property as writable from a form input.
 *
 * Properties without this attribute are server-owned: they travel in the
 * signed snapshot but the browser can never change them.
 *
 * - `rules` — validation rules, e.g. `'required|email'` (see {@see Validator}).
 * - `live`  — send each change after a short debounce (search boxes, live validation).
 * - `blur`  — send the change when the field loses focus.
 * - neither — deferred: the value rides along with the next action.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Model
{
    public function __construct(
        public readonly string $rules = '',
        public readonly bool $live = false,
        public readonly bool $blur = false,
        public readonly int $debounce = 300,
    ) {
        if ($live && $blur) {
            throw new \InvalidArgumentException('#[Model] cannot be both live and blur');
        }
        if ($debounce < 0) {
            throw new \InvalidArgumentException('#[Model] debounce must be >= 0');
        }
    }
}
