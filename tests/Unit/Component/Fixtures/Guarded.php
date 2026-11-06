<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component\Fixtures;

use ZealPHP\Component\Action;
use ZealPHP\Component\Component;

final class Guarded extends Component
{
    public string $note = '';

    public function authorize(string $action): bool
    {
        return $action !== 'secret';
    }

    #[Action]
    public function secret(): void
    {
        $this->note = 'ran';
    }

    #[Action]
    public function open(): void
    {
        $this->note = 'open';
    }

    public function render(): mixed
    {
        return '<p>' . \ZealPHP\e($this->note) . '</p>';
    }
}
