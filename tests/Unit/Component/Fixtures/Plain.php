<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component\Fixtures;

/** A pure (non-backed) enum: not allowed in component state. */
enum Plain
{
    case A;
}
