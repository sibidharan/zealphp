<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * Marks a public component method as callable from the browser.
 *
 * Only methods carrying this attribute can be invoked through the component
 * endpoint. Arguments are bound by parameter name from the request and must
 * be typed `string`, `int`, `float`, `bool`, a backed enum, or `array`.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Action
{
}
