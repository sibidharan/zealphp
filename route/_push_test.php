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
