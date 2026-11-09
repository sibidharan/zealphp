<?php
/**
 * Test routes for tests/Integration/ComponentPushRootTest.php: where the
 * component push script lands when a page renders a component with topics.
 * Needs the components demo (a ZEALPHP_APP_KEY or dev mode).
 */

use ZealPHP\App;
use ZealPHP\Site\Components\SharedLikes;

if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return;
}

$app = App::instance();
if ($app === null) {
    return;
}

// No componentPushRoot(): the framework adds the script before </body>.
$app->route('/_test/push/auto', fn() => '<!doctype html><html><body>'
    . App::component(SharedLikes::class, [], key: 'auto') . '</body></html>');

// The root in <head>, before the component renders.
$app->route('/_test/push/head', fn() => '<!doctype html><html><head>' . App::componentPushRoot() . '</head><body>'
    . App::component(SharedLikes::class, [], key: 'head') . '</body></html>');

// A streamed page: the script rides in the chunk that carries </body>.
$app->route('/_test/push/stream', fn() => (function () {
    yield '<!doctype html><html><body>';
    yield (string) App::component(SharedLikes::class, [], key: 'stream');
    yield '</body></html>';
})());

// A fragment (no </body>): left alone.
$app->route('/_test/push/fragment', fn() => App::component(SharedLikes::class, [], key: 'fragment'));

// A raw route streaming a page, with the root in <head> (rendered before the stream).
$app->route('/_test/push/raw-stream', fn() => (function () {
    yield '<!doctype html><html><head>' . App::componentPushRoot() . '</head><body>';
    yield (string) App::component(SharedLikes::class, [], key: 'raw');
    yield '</body></html>';
})(), methods: ['GET'], raw: true);

// A raw route whose root runs BEFORE the stream starts: it prints the
// placeholder, which the </body> splice fills in.
$app->route('/_test/push/raw-head', function () {
    $head = '<!doctype html><html><head>' . App::componentPushRoot() . '</head><body>';
    return (function () use ($head) {
        yield $head;
        yield (string) App::component(SharedLikes::class, [], key: 'rawhead');
        yield '</body></html>';
    })();
}, methods: ['GET'], raw: true);
