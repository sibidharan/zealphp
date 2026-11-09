<?php

declare(strict_types=1);

namespace ZealPHP\Site\Components;

use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Counter;

/**
 * Components demo: push. One like count shared by every visitor (a
 * cross-worker Counter). Clicking Like increments it and invalidates the
 * `demo:likes` topic, so every open page showing this component re-renders
 * it — no reload, no polling.
 */
final class SharedLikes extends Component
{
    protected string $tag = 'form';
    protected array $attributes = ['class' => 'lc-demo lc-counter'];

    /** Created at boot in route/components.php, shared by all workers. */
    public static ?Counter $likes = null;

    public function topics(): array
    {
        return ['demo:likes'];
    }

    #[Action]
    public function like(): void
    {
        self::$likes?->increment();
        Component::invalidate('demo:likes');
    }

    public function render(): mixed
    {
        return $this->view('components/live/likes', ['likes' => self::$likes?->get() ?? 0]);
    }
}
