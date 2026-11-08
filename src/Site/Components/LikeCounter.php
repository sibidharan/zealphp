<?php

declare(strict_types=1);

namespace ZealPHP\Site\Components;

use ZealPHP\Component\Action;
use ZealPHP\Component\Component;

/**
 * Reactive components demo: a counter whose state lives in the page's signed
 * snapshot. Each click posts to /_zeal/c/like-counter/{action} and swaps the
 * re-rendered component in.
 */
final class LikeCounter extends Component
{
    protected string $tag = 'form';
    protected array $attributes = ['class' => 'lc-demo lc-counter'];

    public int $count = 0;

    #[Action]
    public function add(int $by = 1): void
    {
        $this->count = max(0, min(1_000_000, $this->count + $by));
    }

    #[Action]
    public function reset(): void
    {
        $this->count = 0;
    }

    public function render(): mixed
    {
        return $this->view('components/live/counter');
    }
}
