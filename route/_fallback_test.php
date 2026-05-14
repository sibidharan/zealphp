<?php
/**
 * Test fixture: exercises App::setFallback() return-value semantics from
 * tests/Integration/FallbackTest.php. Real demo URLs are unaffected —
 * non-test URIs fall through to a generic 404 body.
 */

use ZealPHP\App;
use ZealPHP\G;

$app = App::instance();

$app->setFallback(function($request, $response) {
    $uri  = G::instance()->server['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';

    if (strpos($path, '/__fallback_test/') !== 0) {
        echo "<pre>404 Not Found</pre>";
        http_response_code(404);
        return;
    }

    $mode = substr($path, strlen('/__fallback_test/'));

    switch ($mode) {
        case 'echo':
            echo 'FALLBACK-ECHO-BODY';
            return;

        case 'include':
            return App::includeFile(__DIR__ . '/../tests/fixtures/fallback_include.php');

        case 'string':
            return 'FALLBACK-STRING-BODY';

        case 'json':
            return ['fallback' => true, 'x' => 1, 'y' => 'two'];

        case 'generator':
            return (function() {
                yield 'AAA';
                yield 'BBB';
                yield 'CCC';
            })();

        case 'status':
            http_response_code(503);
            echo 'FALLBACK-503-BODY';
            return;

        case 'param-injection':
            // Verifies $request and $response are injected by name into the
            // fallback handler (just like normal route handlers).
            return [
                'has_request'  => $request  !== null,
                'has_response' => $response !== null,
            ];

        default:
            echo "<pre>404 Not Found</pre>";
            http_response_code(404);
            return;
    }
});
