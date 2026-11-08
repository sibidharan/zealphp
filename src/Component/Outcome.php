<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/** How a component request ended, for {@see ComponentBinder::response()}. */
enum Outcome: string
{
    /** The request succeeded. */
    case Ok = 'ok';
    /** The request produced validation errors (the component shows them). */
    case Invalid = 'invalid';
    /** The snapshot is from another build, another session, or expired: reload the page. */
    case Stale = 'stale';
}
