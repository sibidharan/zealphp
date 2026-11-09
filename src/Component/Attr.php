<?php

declare(strict_types=1);

namespace ZealPHP\Component;

use ZealPHP\Escape;

/**
 * Renders an attribute map to an escaped HTML attribute string.
 *
 * `true` emits a bare attribute (`disabled`), `false`/`null` omits it, and
 * strings are escaped with {@see Escape::html()}.
 *
 * @internal
 */
final class Attr
{
    /**
     * @param array<string, string|bool|null> $attrs
     */
    public static function render(array $attrs): string
    {
        $out = [];
        foreach ($attrs as $name => $value) {
            if (preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/D', $name) !== 1) {
                throw new \InvalidArgumentException("Invalid HTML attribute name: {$name}");
            }
            if ($value === null || $value === false) {
                continue;
            }
            $out[] = $value === true ? $name : $name . '="' . Escape::html($value) . '"';
        }
        return implode(' ', $out);
    }

    /**
     * Merge attribute maps; later maps win, except `class`, which is joined.
     *
     * @param array<string, string|bool|null> ...$maps
     * @return array<string, string|bool|null>
     */
    public static function merge(array ...$maps): array
    {
        $out = [];
        foreach ($maps as $map) {
            foreach ($map as $name => $value) {
                if ($name === 'class' && is_string($value) && is_string($out['class'] ?? null) && $out['class'] !== '') {
                    $out['class'] .= ' ' . $value;
                } else {
                    $out[$name] = $value;
                }
            }
        }
        return $out;
    }
}
