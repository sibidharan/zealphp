<?php
/**
 * Test routes for tests/Integration/PsrResponseHeadersTest.php — handlers
 * that return a PSR-7 response carrying repeated headers (Link, Set-Cookie),
 * alone and next to cookies set with setcookie().
 */

use OpenSwoole\Core\Psr\Response;
use ZealPHP\App;
use ZealPHP\Middleware\HeaderMiddleware;

// Test fixtures: off unless the integration suite's server asks for them
// (deterministic error, fallback and timing routes have no place on a public
// site).
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return;
}

$app = App::instance();

$app->route('/__psr_headers/repeated', fn() =>
    (new Response('ok', 200))
        ->withHeader('X-Multi', ['one', 'two'])
        ->withAddedHeader('Link', '</a.css>; rel=preload')
        ->withAddedHeader('Link', '</b.js>; rel=preload')
);

$app->route('/__psr_headers/cookies', fn() =>
    (new Response('ok', 200))->withHeader('Set-Cookie', ['p=1', 'q=2'])
);

$app->route('/__psr_headers/cookies-and-setcookie', function () {
    setcookie('side', '1');
    return (new Response('ok', 200))->withHeader('Set-Cookie', ['p=1', 'q=2']);
});

$app->route('/__psr_headers/cookie-lowercase-and-setcookie', function () {
    setcookie('side', '1');
    return (new Response('ok', 200))->withHeader('set-cookie', 'p=1');
});

$app->route('/__psr_headers/header-middleware-add', fn() => 'ok',
    middleware: [new HeaderMiddleware(['add' => ['Set-Cookie' => ['a=1', 'b=2']]])]);

$app->route('/__psr_headers/no-content', fn() =>
    (new Response('', 204))
        ->withAddedHeader('Link', '</a.css>; rel=preload')
        ->withAddedHeader('Link', '</b.js>; rel=preload')
);
