<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * A string of trusted markup. {@see Escape::html()} and {@see e()} emit it
 * unchanged instead of escaping it. Create one with {@see Escape::raw()}.
 */
final class SafeHtml implements \Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
