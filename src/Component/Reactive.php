<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * A child component's state property that follows its parent: when the
 * parent re-renders and passes a new value for it, the child takes that
 * value. Other properties are seeded from the parent's props only when the
 * child first mounts; after that the child owns them (what the user typed
 * stays).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Reactive
{
}
