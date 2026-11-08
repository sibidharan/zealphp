<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component\Fixtures;

use ZealPHP\App;
use ZealPHP\Component\Component;

/** Renders another component inside itself — not supported yet. */
final class Nester extends Component
{
    public function render(): mixed
    {
        return (string) App::component(Counter::class);
    }
}
