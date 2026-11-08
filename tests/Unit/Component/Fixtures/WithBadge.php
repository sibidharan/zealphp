<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component\Fixtures;

use ZealPHP\App;
use ZealPHP\Component\Component;

/** A reactive component that uses a stateless one inside it (allowed). */
final class WithBadge extends Component
{
    public string $label = 'new';

    public function render(): mixed
    {
        return (string) App::component('badge', ['label' => $this->label]);
    }
}
