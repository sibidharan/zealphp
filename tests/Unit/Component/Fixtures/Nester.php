<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component\Fixtures;

use ZealPHP\App;
use ZealPHP\Component\Component;

/** Renders another component inside itself without a key — refused. */
final class Nester extends Component
{
    public function render(): mixed
    {
        return (string) App::component(Counter::class);
    }
}
