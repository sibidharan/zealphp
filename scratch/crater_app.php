<?php
require_once '/zeal/vendor/autoload.php';

use ZealPHP\App;

const APP_DIR = '/apps50/crater';
const ENTRY   = '/public/index.php';
const PORT    = 9722;

App::mode(App::MODE_COROUTINE_LEGACY);
$app = App::init("127.0.0.1", PORT);
App::$cwd = APP_DIR;
App::documentRoot(APP_DIR . '/public');

if (file_exists(APP_DIR . '/vendor/autoload.php')) {
    require_once APP_DIR . '/vendor/autoload.php';
}
App::preloadClassmap();

$app->setFallback(function () {
    $g = \ZealPHP\G::instance();
    $uri = parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $docroot = APP_DIR . '/public';
    $f = realpath($docroot . $uri);
    if ($f && str_starts_with($f, $docroot) && is_file($f) && !str_ends_with($f, '.php')) {
        $mime = match (strtolower(pathinfo($f, PATHINFO_EXTENSION))) {
            'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'jpg','jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };
        header("Content-Type: $mime");
        return file_get_contents($f);
    }
    $script = ($f && str_ends_with($f, '.php')) ? substr($f, strlen($docroot)) : ENTRY;
    $g->server['PHP_SELF'] = substr($script, strlen($docroot));
    $g->server['SCRIPT_NAME'] = $g->server['PHP_SELF'];
    $g->server['SCRIPT_FILENAME'] = $script;
    return App::include($script);
});
$app->run(['worker_num' => 2, 'task_worker_num' => 0]);
