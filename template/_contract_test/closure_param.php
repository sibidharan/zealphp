<?php
// Streaming-template style — return a Closure with named parameters; the
// framework reflects each param name against the $args array.
return function (string $greeting = 'hi', string $name = 'world') {
    yield "$greeting,";
    yield $name;
};
