<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * The reactive component whose render is running, as seen by components it
 * renders: their parent.
 *
 * @internal
 */
final class RenderFrame
{
    /**
     * @param list<string> $ancestors form ids of the components the parent is nested in, outermost first
     */
    public function __construct(
        public readonly string $id,
        public readonly string $form,
        public readonly string $tag,
        public readonly array $ancestors,
    ) {
    }
}
