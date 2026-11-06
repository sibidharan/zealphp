<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component\Fixtures;

use ZealPHP\Component\Action;
use ZealPHP\Component\Component;

final class Counter extends Component
{
    public int $count = 0;
    public int $mounted = 0;

    public function mount(): void
    {
        $this->mounted++;
    }

    #[Action]
    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    #[Action]
    public function setTo(int $value, Size $size = Size::Small): void
    {
        $this->count = $value;
    }

    /** Public but not an #[Action]: must not be callable. */
    public function reset(): void
    {
        $this->count = 0;
    }

    public function render(): mixed
    {
        return $this->view('components/counter');
    }
}
