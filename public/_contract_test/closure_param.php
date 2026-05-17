<?php
// Universal return contract fixture: return a Closure with param injection.
// In-process mode: framework reflects $name from the calling $args.
return function (string $name = 'noname') {
    yield "GREET:";
    yield $name;
};
